"""Instalacja treści aplikacji: modpacki, loadery, pluginy i mody.

Panel rozwiązuje paczkę (Modrinth, CurseForge, FTB, Hangar) do listy prostych
kroków, a agent je wykonuje w katalogu aplikacji:

* download — plik z adresu HTTPS do ścieżki, z weryfikacją SHA-1/SHA-512
  i rozmiaru (plik powstaje pod tymczasową nazwą i dopiero po sprawdzeniu
  zastępuje docelowy),
* extract  — archiwum zip (np. overrides z .mrpack, server pack z CurseForge)
  z mapowaniem prefiksów i opcjonalnym zdjęciem wspólnego katalogu,
* delete   — usunięcie ścieżek (stare mody przy zmianie paczki),
* write    — mały plik tekstowy (start.sh, eula.txt, znacznik paczki),
* java     — polecenie Javy w kontenerze z obrazem aplikacji (instalator
  Forge/NeoForge/Quilt), z tymi samymi zabezpieczeniami co aplikacja.

Bezpieczeństwo: tylko https, adresy prywatne/lokalne odrzucane (także po
przekierowaniach) — panel nie zrobi z węzła serwera proxy do sieci
wewnętrznej. Zapisy idą przez AppFiles (O_NOFOLLOW, bez „..”), całość
mieści się w limicie dysku aplikacji.
"""

from __future__ import annotations

import fnmatch
import hashlib
import ipaddress
import logging
import os
import socket
import time
import zipfile
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from typing import Any, Callable, Literal
from urllib.parse import urljoin, urlsplit

import httpx
from pydantic import BaseModel, Field

from .apps import AppError, AppFiles
from .jobs import progress
from .schemas import AppSpec

log = logging.getLogger("virthub.content")

USER_AGENT = "VirtHub-Agent/1.0 (+https://github.com/TeYroXOfficial/virthub)"
DOWNLOAD_WORKERS = 8
MAX_REDIRECTS = 5
JAVA_TIMEOUT = 30 * 60
APP_REL_PATH = r"^[^\x00-\x1f]{1,1024}$"


class ContentStep(BaseModel):
    op: Literal["download", "extract", "delete", "write", "java"]
    url: str | None = Field(default=None, max_length=2048)
    path: str | None = Field(default=None, pattern=APP_REL_PATH)
    sha1: str | None = Field(default=None, pattern=r"^[0-9a-f]{40}$")
    sha512: str | None = Field(default=None, pattern=r"^[0-9a-f]{128}$")
    sha256: str | None = Field(default=None, pattern=r"^[0-9a-f]{64}$")
    size: int | None = Field(default=None, ge=0)
    # extract: {"overrides/": "", "server-overrides/": ""}; puste = całe archiwum
    prefixes: dict[str, str] = Field(default_factory=dict, max_length=10)
    strip_root: bool = False
    skip: list[str] = Field(default_factory=list, max_length=100)
    paths: list[str] = Field(default_factory=list, max_length=500)
    content: str | None = Field(default=None, max_length=200_000)
    executable: bool = False
    args: list[str] = Field(default_factory=list, max_length=40)
    label: str | None = Field(default=None, max_length=200)


class AppContentRequest(BaseModel):
    steps: list[ContentStep] = Field(min_length=1, max_length=10_000)
    label: str = Field(default="treść", max_length=200)
    # Modpack/loader: zatrzymuje aplikację i blokuje ją na czas instalacji
    # (konsola pokazuje postęp). Plugin/mod: w tle, bez zatrzymywania.
    exclusive: bool = False
    # Nowa specyfikacja (np. egg modpacków i obraz z właściwą Javą) — zapisana przed krokami.
    spec: AppSpec | None = None


class ContentError(AppError):
    pass


def check_url(url: str, allow_private: bool = False) -> None:
    parts = urlsplit(url)
    if parts.scheme != "https" and not (allow_private and parts.scheme == "http"):
        raise ContentError(f"Dozwolone są tylko adresy https: {url[:120]}")
    host = parts.hostname
    if not host:
        raise ContentError("Adres bez hosta.")
    if allow_private:
        return
    try:
        infos = socket.getaddrinfo(host, parts.port or 443, proto=socket.IPPROTO_TCP)
    except socket.gaierror as exc:
        raise ContentError(f"Nie można rozwiązać {host}: {exc}") from None
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if not ip.is_global:
            raise ContentError(f"Adres {host} wskazuje na sieć prywatną ({ip}) — odrzucono.")


class _HashingReader:
    """Strumień odpowiedzi HTTP dla shutil.copyfileobj z liczeniem skrótów i limitem."""

    def __init__(self, chunks: Any, limit: int | None, on_bytes: Callable[[int], None]):
        self._it = iter(chunks)
        self._buf = b""
        self.sha1 = hashlib.sha1()
        self.sha512 = hashlib.sha512()
        self.sha256 = hashlib.sha256()
        self.size = 0
        self.limit = limit
        self.on_bytes = on_bytes

    def read(self, n: int = -1) -> bytes:
        while not self._buf:
            try:
                self._buf = next(self._it)
            except StopIteration:
                return b""
        data, self._buf = (self._buf, b"") if n < 0 or n >= len(self._buf) else (self._buf[:n], self._buf[n:])
        self.size += len(data)
        if self.limit is not None and self.size > self.limit:
            raise ContentError("Plik jest większy, niż zapowiadał serwis — przerwano.")
        self.sha1.update(data)
        self.sha512.update(data)
        self.sha256.update(data)
        self.on_bytes(len(data))
        return data


class ContentInstaller:
    def __init__(self, manager: Any, uuid: str, request: AppContentRequest, log_line: Callable[[str], None]):
        self.apps = manager
        self.uuid = uuid
        self.request = request
        self.files: AppFiles = manager.files(uuid)
        self.log = log_line
        self.allow_private = bool(getattr(manager.settings, "apps_content_allow_private", False))
        spec = manager.load_spec(uuid)
        self.spec = spec
        used = manager.files(uuid).usage_bytes()
        self.budget = (spec.disk_mb * 1024 * 1024 - used) if spec.disk_mb else None
        self.written = 0
        self.client = httpx.Client(headers={"User-Agent": USER_AGENT}, timeout=httpx.Timeout(30, read=120),
                                   follow_redirects=False)

    # --- pobieranie ----------------------------------------------------------------

    def _account(self, n: int) -> None:
        self.written += n
        if self.budget is not None and self.written > self.budget:
            raise ContentError(
                f"Paczka nie mieści się w limicie dysku aplikacji ({self.spec.disk_mb} MB). "
                "Zwiększ plan albo usuń zbędne pliki."
            )

    def _open(self, url: str) -> httpx.Response:
        for _ in range(MAX_REDIRECTS + 1):
            check_url(url, self.allow_private)
            response = self.client.send(self.client.build_request("GET", url), stream=True)
            if response.is_redirect:
                location = response.headers.get("location", "")
                response.close()
                url = urljoin(url, location)
                continue
            if response.status_code != 200:
                response.close()
                raise ContentError(f"{urlsplit(url).hostname} odpowiedział HTTP {response.status_code} dla {url[-120:]}")
            return response
        raise ContentError("Za dużo przekierowań.")

    def download(self, step: ContentStep) -> None:
        assert step.url and step.path
        parts = self.files.parts(step.path)
        if not parts:
            raise ContentError("Pusta ścieżka docelowa.")
        tmp = "/".join(parts[:-1] + [f".{parts[-1]}.vhpart"])
        response = self._open(step.url)
        try:
            limit = step.size + 1024 if step.size else None
            reader = _HashingReader(response.iter_bytes(256 * 1024), limit, self._account)
            self.files._write_stream(tmp, reader)
        except BaseException:
            self.files.delete(tmp)
            raise
        finally:
            response.close()
        problem = None
        if step.sha1 and reader.sha1.hexdigest() != step.sha1:
            problem = "SHA-1"
        elif step.sha512 and reader.sha512.hexdigest() != step.sha512:
            problem = "SHA-512"
        elif step.sha256 and reader.sha256.hexdigest() != step.sha256:
            problem = "SHA-256"
        elif step.size is not None and reader.size != step.size:
            problem = "rozmiar"
        if problem:
            self.files.delete(tmp)
            raise ContentError(f"Plik {step.path} ma zły {problem} — pobieranie przerwane (uszkodzony albo podmieniony).")
        self._replace(parts[:-1], f".{parts[-1]}.vhpart", parts[-1])

    def _replace(self, parents: list[str], source: str, target: str) -> None:
        """Atomowa podmiana w obrębie jednego katalogu (dowiązanie w miejscu
        celu zostaje zastąpione plikiem, a nie przeskoczone)."""
        dfd = self.files._open_dir(parents)
        try:
            os.replace(source, target, src_dir_fd=dfd, dst_dir_fd=dfd)
        except IsADirectoryError:
            raise ContentError(f"{target} jest katalogiem — nie można go nadpisać plikiem.") from None
        finally:
            os.close(dfd)

    def _downloads(self, steps: list[ContentStep], done_before: int, total: int) -> None:
        done = 0

        def one(step: ContentStep) -> ContentStep:
            self.download(step)
            return step

        # Katalogi zakładamy po kolei — równoległe mkdir tego samego katalogu kolidują.
        for parent in sorted({"/".join(self.files.parts(s.path or "")[:-1]) for s in steps}):
            if parent:
                self.files._open_close_dir(parent)

        with ThreadPoolExecutor(DOWNLOAD_WORKERS) as pool:
            for step in pool.map(one, steps):
                done += 1
                if done % 10 == 0 or done == len(steps) or len(steps) < 30:
                    self.log(f"[{done_before + done}/{total}] {step.label or step.path}")
                progress("download", 5 + int(80 * (done_before + done) / max(total, 1)),
                         f"{done_before + done}/{total} · {self.written // (1024 * 1024)} MB")

    # --- pozostałe kroki -----------------------------------------------------------------

    def extract(self, step: ContentStep) -> None:
        assert step.url
        meta = self.apps._meta_dir() / f"{self.uuid}.content.zip"
        response = self._open(step.url)
        try:
            h1, h512 = hashlib.sha1(), hashlib.sha512()
            with meta.open("wb") as out:
                for chunk in response.iter_bytes(256 * 1024):
                    out.write(chunk)
                    h1.update(chunk)
                    h512.update(chunk)
        finally:
            response.close()
        try:
            if (step.sha1 and h1.hexdigest() != step.sha1) or (step.sha512 and h512.hexdigest() != step.sha512):
                raise ContentError("Archiwum paczki ma złą sumę kontrolną — przerwano.")
            self._extract_zip(meta, step)
        finally:
            meta.unlink(missing_ok=True)

    def _extract_zip(self, archive_path: Path, step: ContentStep) -> None:
        try:
            archive = zipfile.ZipFile(archive_path)
        except zipfile.BadZipFile:
            raise ContentError("Pobrane archiwum nie jest poprawnym plikiem zip.") from None
        with archive:
            names = [i.filename for i in archive.infolist() if not i.is_dir()]
            root = ""
            if step.strip_root and names:
                first = names[0].split("/", 1)[0] + "/"
                if all(n.startswith(first) for n in names):
                    root = first
            count = 0
            for info in archive.infolist():
                name = info.filename.replace("\\", "/")
                if info.is_dir() or (info.external_attr >> 16) & 0o170000 == 0o120000:
                    continue
                if root:
                    name = name[len(root):]
                if step.prefixes:
                    for prefix, dest in step.prefixes.items():
                        if name.startswith(prefix):
                            name = dest + name[len(prefix):]
                            break
                    else:
                        continue
                if not name or any(name == s or name.startswith(s.rstrip("/") + "/") for s in step.skip):
                    continue
                self.files.parts(name)  # „..” w archiwum → błąd
                self._account(info.file_size)
                with archive.open(info) as src:
                    self.files._write_stream(name, src)
                count += 1
            self.log(f"Rozpakowano {count} plików z {step.label or 'archiwum'}")

    def delete(self, step: ContentStep) -> None:
        """Ścieżki do usunięcia; ostatni człon może mieć wzorzec (forge-*.jar)."""
        for path in step.paths:
            try:
                parts = self.files.parts(path)
                if not parts:
                    continue
                if any(c in parts[-1] for c in "*?["):
                    parent = "/".join(parts[:-1])
                    for entry in self.files.list(parent):
                        if fnmatch.fnmatch(entry["name"], parts[-1]):
                            self.files.delete(f"{parent}/{entry['name']}" if parent else entry["name"])
                else:
                    self.files.delete(path)
            except AppError:
                pass  # brak katalogu nadrzędnego — nie ma czego usuwać

    def write(self, step: ContentStep) -> None:
        assert step.path is not None and step.content is not None
        parents, name = self.files._split(step.path)
        dfd = self.files._open_dir(parents, create=True)
        try:
            self.files._write_at(dfd, name, step.content.encode("utf-8"), 0o755 if step.executable else 0o644)
        finally:
            os.close(dfd)

    def java(self, step: ContentStep) -> None:
        self.log(f"$ java {' '.join(step.args)}")
        self.apps.run_java(self.spec, step.args, self.log, timeout=JAVA_TIMEOUT)

    # --- przebieg -----------------------------------------------------------------------

    def run(self) -> dict[str, Any]:
        steps = self.request.steps
        total_downloads = sum(1 for s in steps if s.op == "download")
        done = 0
        batch: list[ContentStep] = []
        started = time.time()

        def flush() -> None:
            nonlocal done, batch
            if batch:
                self._downloads(batch, done, total_downloads)
                done += len(batch)
                batch = []

        try:
            for step in steps:
                if step.op == "download":
                    batch.append(step)
                    continue
                flush()
                if step.label:
                    self.log(step.label)
                getattr(self, step.op)(step)
            flush()
        finally:
            self.client.close()
        progress("done", 100)
        seconds = int(time.time() - started)
        self.log(f"Gotowe: {total_downloads} plików, {self.written // (1024 * 1024)} MB w {seconds} s.")
        return {"files": total_downloads, "bytes": self.written, "seconds": seconds}
