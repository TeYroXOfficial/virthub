"""Migracje maszyn między węzłami.

Na zimno: węzeł źródłowy zatrzymuje maszynę, spłaszcza dysk do jednego pliku
qcow2 (bez łańcucha backing — węzeł docelowy nie musi mieć szablonu) i wystawia
go pod jednorazowym, losowym tokenem. Węzeł docelowy pobiera plik bezpośrednio
(z weryfikacją sha256), zakłada sieć i domenę. Panel na koniec każe źródłu
posprzątać.

Na żywo: węzeł docelowy przygotowuje sieć, nośnik cloud-init i pusty dysk,
a źródło migruje domenę przez libvirt z kopiowaniem dysku.
"""

from __future__ import annotations

import hashlib
import json
import os
import secrets
import time
from pathlib import Path
from typing import Any

import httpx

from .config import Settings
from .jobs import progress

TOKEN_TTL = 6 * 3600
CHUNK = 1024 * 1024


class TransferError(RuntimeError):
    pass


class TransferStore:
    """Wystawione dyski: token → plik i termin ważności (plik 0600 obok bazy zadań)."""

    def __init__(self, settings: Settings):
        self.path = settings.state_db.parent / "transfers.json"

    def _load(self) -> dict[str, dict[str, Any]]:
        try:
            data = json.loads(self.path.read_text(encoding="utf-8"))
        except (FileNotFoundError, json.JSONDecodeError):
            return {}
        now = time.time()
        for token, entry in list(data.items()):
            if entry.get("expires", 0) <= now:  # przeterminowane: plik eksportu do kosza
                Path(entry["path"]).unlink(missing_ok=True)
                del data[token]
        return data

    def _save(self, data: dict[str, dict[str, Any]]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.path.with_suffix(".tmp")
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(data, fh)
        os.replace(tmp, self.path)

    def register(self, path: Path, name: str) -> str:
        token = secrets.token_urlsafe(32)
        data = self._load()
        data[token] = {"path": str(path), "name": name, "expires": time.time() + TOKEN_TTL}
        self._save(data)
        return token

    def resolve(self, token: str) -> Path | None:
        entry = self._load().get(token)
        if entry is None or entry["expires"] < time.time():
            return None
        path = Path(entry["path"])
        return path if path.is_file() else None

    def revoke(self, token: str) -> bool:
        data = self._load()
        entry = data.pop(token, None)
        if entry is None:
            return False
        Path(entry["path"]).unlink(missing_ok=True)
        self._save(data)
        return True


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as fh:
        while chunk := fh.read(CHUNK):
            digest.update(chunk)
    return digest.hexdigest()


def http_stream(url: str):
    """Strumień HTTP pliku z węzła źródłowego (w testach podmieniany)."""
    return httpx.stream("GET", url, timeout=httpx.Timeout(30, read=600), follow_redirects=False)


def download(source: Any, target: Path) -> None:
    """Pobiera wystawiony dysk do pliku docelowego i sprawdza sumę kontrolną."""
    digest = hashlib.sha256()
    done = 0
    last = 0.0
    total = source.size_bytes or 0
    try:
        with http_stream(source.url) as response, target.open("wb") as out:
            if response.status_code != 200:
                raise TransferError(f"Węzeł źródłowy odpowiedział HTTP {response.status_code}.")
            for chunk in response.iter_bytes(CHUNK):
                out.write(chunk)
                digest.update(chunk)
                done += len(chunk)
                if total and time.monotonic() - last > 2:
                    last = time.monotonic()
                    progress("download", min(99, int(done * 100 / total)))
    except httpx.HTTPError as exc:
        target.unlink(missing_ok=True)
        raise TransferError(f"Pobieranie dysku z węzła źródłowego nie powiodło się: {exc}") from exc
    except TransferError:
        target.unlink(missing_ok=True)
        raise
    if digest.hexdigest() != source.sha256:
        target.unlink(missing_ok=True)
        raise TransferError("Suma kontrolna przeniesionego dysku się nie zgadza — plik uszkodzony w drodze.")
