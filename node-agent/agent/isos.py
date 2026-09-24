"""Biblioteka obrazów ISO na węźle.

Obrazy dodaje administrator w panelu (adres URL); węzeł pobiera je sam do
VH_ISO_DIR, sprawdza sumę kontrolną i dopiero wtedy udostępnia pod właściwą
nazwą — przerwane pobieranie nie zostawia połowy pliku, który ktoś mógłby
zamontować.
"""

from __future__ import annotations

import hashlib
import logging
import os
import re
import urllib.request
from pathlib import Path

from .config import Settings

log = logging.getLogger("virthub.isos")

NAME = re.compile(r"^[a-z0-9][a-z0-9._-]{0,80}\.iso$")
CHUNK = 1024 * 1024
# Obraz instalacyjny DVD ma kilka GB; limit chroni dysk węzła przed pomyłką w URL.
MAX_BYTES = int(os.environ.get("VH_ISO_MAX_GB", "20")) * 1024**3


class IsoError(RuntimeError):
    pass


class IsoLibrary:
    def __init__(self, settings: Settings):
        self.dir: Path = settings.iso_dir
        self.mock = settings.is_mock

    def path(self, name: str) -> Path:
        if not NAME.match(name):
            raise IsoError(f"Nieprawidłowa nazwa obrazu ISO: {name!r}")
        return self.dir / name

    def exists(self, name: str) -> bool:
        return self.path(name).is_file()

    def list(self) -> list[dict]:
        if not self.dir.exists():
            return []
        return [
            {"name": p.name, "size_bytes": p.stat().st_size}
            for p in sorted(self.dir.glob("*.iso")) if p.is_file()
        ]

    def download(self, name: str, url: str, sha256: str | None = None) -> dict:
        target = self.path(name)
        self.dir.mkdir(parents=True, exist_ok=True)
        partial = target.with_suffix(".iso.part")
        digest = hashlib.sha256()
        size = 0

        log.info("Pobieram ISO %s z %s", name, url)
        request = urllib.request.Request(url, headers={"User-Agent": "VirtHub-Agent"})
        try:
            with urllib.request.urlopen(request, timeout=60) as response, partial.open("wb") as out:
                while chunk := response.read(CHUNK):
                    size += len(chunk)
                    if size > MAX_BYTES:
                        raise IsoError(f"Obraz przekracza limit {MAX_BYTES // 1024**3} GB (VH_ISO_MAX_GB).")
                    digest.update(chunk)
                    out.write(chunk)
        except IsoError:
            partial.unlink(missing_ok=True)
            raise
        except OSError as exc:
            partial.unlink(missing_ok=True)
            raise IsoError(f"Nie udało się pobrać obrazu: {exc}") from exc

        actual = digest.hexdigest()
        if sha256 and actual.lower() != sha256.lower():
            partial.unlink(missing_ok=True)
            raise IsoError(f"Suma SHA-256 się nie zgadza: oczekiwano {sha256.lower()}, jest {actual}.")

        os.replace(partial, target)
        target.chmod(0o644)
        return {"name": name, "size_bytes": size, "sha256": actual}

    def delete(self, name: str) -> dict:
        self.path(name).unlink(missing_ok=True)
        return {"name": name, "deleted": True}
