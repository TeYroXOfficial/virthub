"""Budowanie szablonów Windows Server na węźle KVM (Packer).

Kolejność:
  1. Packer z releases.hashicorp.com (suma z SHA256SUMS), wtyczka qemu.
  2. Pliki instalacji z repozytorium VirtFusion (Autounattend, sysprep,
     cloudbase-init) — kopia robocza z losowym hasłem budowy zamiast hasła
     zapisanego w publicznym repozytorium.
  3. ISO Windows (retail/SPLA z adresu administratora albo ewaluacyjne) i
     sterowniki virtio — w pamięci podręcznej, pobierane raz.
  4. `packer build` z agent/packer/windows.pkr.hcl — WinRM i VNC tylko na
     127.0.0.1. Budowa trwa zwykle od 40 minut do kilku godzin.
  5. Gotowy qcow2 (sprawdzony jak pobierane szablony) trafia do VH_TEMPLATE_DIR.

Istniejącego szablonu nie nadpisujemy — dyski maszyn są nad nim warstwami.
"""

from __future__ import annotations

import hashlib
import logging
import os
import platform
import re
import secrets
import shutil
import signal
import string
import subprocess
import time
import urllib.request
import zipfile
from pathlib import Path

from .config import Settings
from .jobs import progress
from .schemas import TemplateBuildRequest
from .templates import TemplateError, TemplateLibrary
from .transfer import TransferProgress

log = logging.getLogger("virthub.builder")

PACKER_VERSION = "1.16.1"
RECIPE = Path(__file__).with_name("packer") / "windows.pkr.hcl"
BUILD_TIMEOUT = int(os.environ.get("VH_BUILD_TIMEOUT_HOURS", "8")) * 3600
CHUNK = 1024 * 1024
ANSI = re.compile(r"\x1b\[[0-9;]*m")

# Etapy z wyjścia Packera → postęp (budowa nie podaje procentów).
STAGES = [
    ("Retrieving ISO", 3, "Sprawdzam ISO"),
    ("Creating floppy disk", 5, "Przygotowuję pliki instalacji"),
    ("Starting VM", 8, "Instalacja Windows"),
    ("Waiting for WinRM", 10, "Instalacja Windows i aktualizacje (to najdłuższy etap)"),
    ("Connected to WinRM", 60, "Windows zainstalowany — konfiguruję system"),
    ("Provisioning with windows-shell", 65, "Konfiguracja systemu"),
    ("Provisioning with powershell", 70, "Czyszczenie systemu"),
    ("Restarting Machine", 75, "Restart"),
    ("Gracefully halting", 88, "Sysprep i wyłączenie"),
    ("Converting hard drive", 94, "Kompresja obrazu"),
]


class BuildError(TemplateError):
    pass


def _arch() -> str:
    return {"x86_64": "amd64", "amd64": "amd64", "aarch64": "arm64", "arm64": "arm64"}.get(platform.machine().lower(), "amd64")


def _download(url: str, target: Path, sha256: str | None = None, *, stage: str = "download",
              low: int = 0, high: int = 100, limit: int = 20 * 1024**3) -> str:
    """Pobiera plik atomowo (przez .part) i zwraca SHA-256."""
    target.parent.mkdir(parents=True, exist_ok=True)
    partial = target.with_name(f".{target.name}.part")
    digest = hashlib.sha256()
    size = 0
    request = urllib.request.Request(url, headers={"User-Agent": "VirtHub-Agent"})
    try:
        with urllib.request.urlopen(request, timeout=60) as response, partial.open("wb") as out:
            length = response.headers.get("Content-Length")
            tracker = TransferProgress(stage=stage, total=int(length) if length and length.isdigit() else None, low=low, high=high)
            while chunk := response.read(CHUNK):
                size += len(chunk)
                if size > limit:
                    raise BuildError(f"Plik {url} przekracza limit {limit // 1024**3} GB.")
                digest.update(chunk)
                out.write(chunk)
                tracker.update(size)
    except BuildError:
        partial.unlink(missing_ok=True)
        raise
    except OSError as exc:
        partial.unlink(missing_ok=True)
        raise BuildError(f"Nie udało się pobrać {url}: {exc}") from exc
    actual = digest.hexdigest()
    if sha256 and actual != sha256.lower():
        partial.unlink(missing_ok=True)
        raise BuildError(f"Suma SHA-256 pliku {target.name} się nie zgadza: oczekiwano {sha256.lower()}, jest {actual}.")
    os.replace(partial, target)
    return actual


def build_password() -> str:
    """Hasło spełniające wymagania złożoności Windows — tylko na czas budowy."""
    alphabet = string.ascii_letters + string.digits
    core = "".join(secrets.choice(alphabet) for _ in range(20))
    return f"Vh{core}#9"


class TemplateBuilder:
    def __init__(self, settings: Settings):
        self.settings = settings
        self.root = settings.template_dir.parent / "packer"
        self.library = TemplateLibrary(settings)

    # --- narzędzia -------------------------------------------------------------

    def packer(self) -> Path:
        binary = self.root / "bin" / "packer"
        stamp = self.root / "bin" / "VERSION"
        if binary.exists() and stamp.exists() and stamp.read_text().strip() == PACKER_VERSION:
            return binary
        progress("prepare", 1, f"Pobieram Packer {PACKER_VERSION}")
        base = f"https://releases.hashicorp.com/packer/{PACKER_VERSION}"
        name = f"packer_{PACKER_VERSION}_linux_{_arch()}.zip"
        sums = urllib.request.urlopen(urllib.request.Request(f"{base}/packer_{PACKER_VERSION}_SHA256SUMS",
                                                              headers={"User-Agent": "VirtHub-Agent"}), timeout=60).read().decode()
        expected = next((line.split()[0] for line in sums.splitlines() if line.endswith(name)), None)
        if not expected:
            raise BuildError(f"Brak sumy kontrolnej dla {name}.")
        archive = self.root / "cache" / name
        _download(f"{base}/{name}", archive, expected, stage="prepare", low=1, high=2)
        binary.parent.mkdir(parents=True, exist_ok=True)
        with zipfile.ZipFile(archive) as zf:
            binary.write_bytes(zf.read("packer"))
        binary.chmod(0o755)
        stamp.write_text(PACKER_VERSION)
        archive.unlink(missing_ok=True)
        return binary

    def _env(self) -> dict[str, str]:
        env = {k: v for k, v in os.environ.items() if k in ("PATH", "LANG", "LC_ALL", "http_proxy", "https_proxy", "no_proxy")}
        env.update({
            "PACKER_PLUGIN_PATH": str(self.root / "plugins"),
            "PACKER_CONFIG_DIR": str(self.root / "config"),
            "PACKER_CACHE_DIR": str(self.root / "cache" / "packer"),
            "TMPDIR": str(self.root / "tmp"),
            "HOME": str(self.root),
            "CHECKPOINT_DISABLE": "1",
            "PACKER_NO_COLOR": "1",
        })
        for key in ("plugins", "config", "tmp"):
            (self.root / key).mkdir(parents=True, exist_ok=True)
        return env

    # --- pliki VirtFusion ------------------------------------------------------------

    def virtfusion_files(self, req: TemplateBuildRequest, work: Path, password: str) -> Path:
        progress("prepare", 2, "Pobieram pliki instalacji Windows (VirtFusion)")
        archive = work / "virtfusion.zip"
        _download(req.files_url, archive, stage="prepare", low=2, high=3, limit=200 * 1024**2)
        source = work / "virtfusion"
        with zipfile.ZipFile(archive) as zf:
            for member in zf.namelist():
                # Archiwum z Bitbucketa ma jeden katalog główny; ścieżki z „..” odrzucamy.
                if member.startswith("/") or ".." in Path(member).parts:
                    raise BuildError(f"Podejrzana ścieżka w archiwum: {member}")
            zf.extractall(source)
        roots = [p for p in source.iterdir() if p.is_dir()]
        config = (roots[0] if len(roots) == 1 else source) / "config"

        edition_dir = config / f"windows-server-{req.edition}-standard"
        if req.evaluation and (config / f"windows-server-{req.edition}-standard-eval").is_dir():
            edition_dir = config / f"windows-server-{req.edition}-standard-eval"
        shared = config / "windows-shared"
        if not edition_dir.is_dir() or not shared.is_dir():
            raise BuildError(f"W repozytorium nie ma konfiguracji {edition_dir.name} albo windows-shared.")

        files = work / "files"
        shutil.copytree(shared, files / "shared")
        shutil.copytree(edition_dir / "files", files / "edition")
        self._replace_password(files, password)
        return files

    @staticmethod
    def _replace_password(files: Path, password: str) -> None:
        """Hasło Administratora z Autounattend.xml → losowe, we wszystkich plikach."""
        autounattend = (files / "edition" / "Autounattend.xml").read_text(encoding="utf-8", errors="replace")
        match = re.search(r"<AdministratorPassword>\s*<Value>([^<]+)</Value>", autounattend)
        if not match:
            raise BuildError("Autounattend.xml nie zawiera hasła Administratora — zmienił się format repozytorium.")
        old = match.group(1)
        for path in files.rglob("*"):
            if path.is_file() and path.suffix.lower() in (".xml", ".bat", ".ps1", ".conf", ".txt", ".cmd"):
                text = path.read_bytes()
                if old.encode() in text:
                    path.write_bytes(text.replace(old.encode(), password.encode()))

    # --- budowa -------------------------------------------------------------------------

    def build(self, req: TemplateBuildRequest) -> dict:
        target = self.library.path(req.name)
        if target.exists():
            progress("build", 100, "Szablon jest już na węźle")
            return {"name": req.name, "cached": True}
        if not self.settings.is_mock and not os.access("/dev/kvm", os.R_OK | os.W_OK):
            raise BuildError("Agent nie ma dostępu do /dev/kvm — zaktualizuj węzeł (dodaje konto agenta do grupy kvm).")

        packer = self.packer()
        work = self.root / "work" / Path(req.name).stem
        shutil.rmtree(work, ignore_errors=True)
        work.mkdir(parents=True)
        password = build_password()
        try:
            files = self.virtfusion_files(req, work, password)
            iso = self._cached(req.iso_url, req.iso_sha256, f"windows-{req.edition}{'-eval' if req.evaluation else ''}", 3, 5)
            virtio = self._cached(req.virtio_url, None, "virtio-win", 5, 6)
            recipe = work / "windows.pkr.hcl"
            shutil.copy(RECIPE, recipe)
            env = self._env()
            self._run([str(packer), "init", str(recipe)], env, work, timeout=900)
            output = work / "output"
            variables = {
                "files_dir": str(files), "edition": req.edition, "evaluation": "true" if req.evaluation else "false",
                "iso": str(iso), "iso_checksum": f"sha256:{req.iso_sha256}" if req.iso_sha256 else "none",
                "virtio_iso": str(virtio), "output_dir": str(output), "vm_name": req.name,
                "disk_size_mb": str(req.disk_gb * 1024), "cpus": str(req.cpus), "memory_mb": str(req.memory_mb),
                "password": password, "kms_key": req.kms_key or "",
            }
            argv = [str(packer), "build", "-force", "-color=false"]
            for key, value in variables.items():
                argv += ["-var", f"{key}={value}"]
            argv.append(str(recipe))
            self._run(argv, env, work, timeout=BUILD_TIMEOUT, secret=password, stages=True)

            image = output / req.name
            if not image.exists():
                raise BuildError("Packer skończył bez obrazu dysku.")
            progress("verify", 97, "Sprawdzam obraz")
            self.library._check_image(image)
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.move(str(image), str(target))
            target.chmod(0o644)
            progress("build", 100, "Szablon gotowy")
            return {"name": req.name, "cached": False, "size_bytes": target.stat().st_size}
        finally:
            shutil.rmtree(work, ignore_errors=True)

    def _cached(self, url: str, sha256: str | None, stem: str, low: int, high: int) -> Path:
        """ISO w pamięci podręcznej węzła — nazwa z adresu, żeby zmiana adresu pobrała nowy plik."""
        key = hashlib.sha256(url.encode()).hexdigest()[:12]
        path = self.root / "cache" / "isos" / f"{stem}-{key}.iso"
        if path.exists():
            return path
        progress("download", low, f"Pobieram {stem}.iso")
        _download(url, path, sha256, stage="download", low=low, high=high)
        return path

    def _run(self, argv: list[str], env: dict[str, str], cwd: Path, *, timeout: int,
             secret: str | None = None, stages: bool = False) -> None:
        log.info("Uruchamiam: %s", " ".join(a if not secret or secret not in a else a.split("=")[0] + "=***" for a in argv))
        tail: list[str] = []
        started = time.monotonic()
        proc = subprocess.Popen(argv, cwd=cwd, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                text=True, errors="replace", start_new_session=True)
        try:
            assert proc.stdout is not None
            for raw in proc.stdout:
                line = ANSI.sub("", raw).rstrip()
                if secret:
                    line = line.replace(secret, "***")
                if not line:
                    continue
                tail = (tail + [line])[-30:]
                if stages:
                    for marker, percent, label in STAGES:
                        if marker in line:
                            minutes = int((time.monotonic() - started) // 60)
                            progress("build", percent, f"{label} · {minutes} min")
                            break
                if time.monotonic() - started > timeout:
                    raise BuildError(f"Budowa przekroczyła limit {timeout // 3600} h.")
            code = proc.wait()
        except BaseException:
            os.killpg(proc.pid, signal.SIGTERM)
            try:
                proc.wait(timeout=60)
            except subprocess.TimeoutExpired:
                os.killpg(proc.pid, signal.SIGKILL)
            raise
        if code != 0:
            errors = [line for line in tail if "error" in line.lower()] or tail[-8:]
            raise BuildError("Packer zakończył się błędem: " + " | ".join(errors[-6:])[:1500])

