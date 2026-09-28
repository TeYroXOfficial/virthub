"""Szablony maszyn KVM pobierane z katalogu panelu.

Panel podaje adres oficjalnego obrazu cloud (Debian, Ubuntu, AlmaLinux…) i
sumę kontrolną z serwera dystrybucji. Węzeł pobiera plik do VH_TEMPLATE_DIR,
sprawdza sumę i format, i dopiero wtedy udostępnia go pod właściwą nazwą.

Istniejącego szablonu nie nadpisujemy: dyski maszyn są cienkimi warstwami nad
tym plikiem, a podmiana obrazu bazowego zepsułaby każdą z nich.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import re
import urllib.request
from pathlib import Path

from .config import Settings
from .jobs import progress
from .shell import run
from .transfer import TransferProgress

log = logging.getLogger("virthub.templates")

NAME = re.compile(r"^[a-z0-9][a-z0-9._-]{0,80}\.qcow2$")
CHUNK = 1024 * 1024
MAX_BYTES = int(os.environ.get("VH_TEMPLATE_MAX_GB", "20")) * 1024**3


class TemplateError(RuntimeError):
    pass


class TemplateLibrary:
    def __init__(self, settings: Settings):
        self.dir: Path = settings.template_dir
        self.mock = settings.is_mock

    def path(self, name: str) -> Path:
        if not NAME.match(name):
            raise TemplateError(f"Nieprawidłowa nazwa szablonu: {name!r}")
        return self.dir / name

    def download(self, name: str, url: str, sha256: str | None = None, sha512: str | None = None) -> dict:
        target = self.path(name)
        if target.exists():
            # Obraz bazowy dysków istniejących maszyn — zostaje, jaki jest.
            progress("download", 100, "Szablon jest już na węźle")
            return {"name": name, "size_bytes": target.stat().st_size, "cached": True}

        self.dir.mkdir(parents=True, exist_ok=True)
        partial = self.dir / f".{name}.part"
        digest = hashlib.sha512() if sha512 else hashlib.sha256()
        expected = (sha512 or sha256 or "").lower() or None
        size = 0

        log.info("Pobieram szablon %s z %s", name, url)
        request = urllib.request.Request(url, headers={"User-Agent": "VirtHub-Agent"})
        try:
            with urllib.request.urlopen(request, timeout=60) as response, partial.open("wb") as out:
                length = response.headers.get("Content-Length")
                tracker = TransferProgress(total=int(length) if length and length.isdigit() else None, high=95)
                tracker.update(0, force=True)
                while chunk := response.read(CHUNK):
                    size += len(chunk)
                    if size > MAX_BYTES:
                        raise TemplateError(f"Obraz przekracza limit {MAX_BYTES // 1024**3} GB (VH_TEMPLATE_MAX_GB).")
                    digest.update(chunk)
                    out.write(chunk)
                    tracker.update(size)
        except TemplateError:
            partial.unlink(missing_ok=True)
            raise
        except OSError as exc:
            partial.unlink(missing_ok=True)
            raise TemplateError(f"Nie udało się pobrać obrazu: {exc}") from exc

        progress("verify", 97, "Sprawdzam sumę kontrolną i format")
        actual = digest.hexdigest()
        if expected and actual != expected:
            partial.unlink(missing_ok=True)
            raise TemplateError(f"Suma kontrolna się nie zgadza: oczekiwano {expected}, jest {actual}.")
        try:
            self._check_image(partial)
        except TemplateError:
            partial.unlink(missing_ok=True)
            raise

        os.replace(partial, target)
        target.chmod(0o644)
        progress("download", 100, "Szablon gotowy")
        return {"name": name, "size_bytes": size, "sha": actual, "cached": False}

    def _check_image(self, path: Path) -> None:
        """Tylko samodzielny qcow2: obraz z plikiem bazowym mógłby wskazywać
        dowolny plik węzła, który trafiłby potem na dysk klienta."""
        if self.mock:
            return
        try:
            info = json.loads(run(["qemu-img", "info", "--output=json", str(path)], timeout=120))
        except (RuntimeError, OSError, ValueError) as exc:
            raise TemplateError(f"To nie jest poprawny obraz dysku: {exc}") from exc
        if info.get("format") != "qcow2":
            raise TemplateError(f"Oczekiwano obrazu qcow2, a jest {info.get('format')!r}.")
        if info.get("backing-filename") or info.get("full-backing-filename"):
            raise TemplateError("Obraz wskazuje plik bazowy — odrzucony.")
