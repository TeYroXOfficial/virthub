"""Biblioteka obrazów ISO na węźle.

Obrazy dodaje administrator w panelu (adres URL); węzeł pobiera je sam do
VH_ISO_DIR, sprawdza sumę kontrolną i dopiero wtedy udostępnia pod właściwą
nazwą — przerwane pobieranie nie zostawia połowy pliku, który ktoś mógłby
zamontować.
"""

from __future__ import annotations

import hashlib
import ipaddress
import logging
import os
import re
import socket
import urllib.error
import urllib.request
from pathlib import Path
from urllib.parse import urljoin, urlsplit

from .config import Settings
from .jobs import progress
from .transfer import TransferProgress

log = logging.getLogger("virthub.isos")

NAME = re.compile(r"^[a-z0-9][a-z0-9._-]{0,80}\.iso$")
CHUNK = 1024 * 1024
# Obraz instalacyjny DVD ma kilka GB; limit chroni dysk węzła przed pomyłką w URL.
MAX_BYTES = int(os.environ.get("VH_ISO_MAX_GB", "20")) * 1024**3


MAX_REDIRECTS = 5


class IsoError(RuntimeError):
    pass


def check_public_url(url: str) -> None:
    """Adres od klienta nie może prowadzić do sieci węzła (SSRF): wszystkie
    adresy hosta muszą być publiczne. Sprawdzane przy każdym przekierowaniu."""
    parts = urlsplit(url)
    if parts.scheme not in ("http", "https") or not parts.hostname:
        raise IsoError("Dozwolone są tylko adresy http(s).")
    try:
        infos = socket.getaddrinfo(parts.hostname, parts.port or (443 if parts.scheme == "https" else 80),
                                   proto=socket.IPPROTO_TCP)
    except socket.gaierror as exc:
        raise IsoError(f"Nie można rozwiązać {parts.hostname}: {exc}") from None
    for info in infos:
        ip = ipaddress.ip_address(info[4][0])
        if not ip.is_global:
            raise IsoError(f"Adres {parts.hostname} wskazuje na sieć prywatną ({ip}) — odrzucono.")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):  # noqa: D401 — przekierowania obsługujemy sami
        return None


def _open_untrusted(url: str):
    opener = urllib.request.build_opener(_NoRedirect)
    for _ in range(MAX_REDIRECTS + 1):
        check_public_url(url)
        try:
            return opener.open(urllib.request.Request(url, headers={"User-Agent": "VirtHub-Agent"}), timeout=60)
        except urllib.error.HTTPError as exc:
            if exc.code in (301, 302, 303, 307, 308) and exc.headers.get("Location"):
                url = urljoin(url, exc.headers["Location"])
                continue
            raise
    raise IsoError("Za dużo przekierowań.")


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

    def download(self, name: str, url: str, sha256: str | None = None, *, untrusted: bool = False,
                 max_gb: int | None = None) -> dict:
        target = self.path(name)
        self.dir.mkdir(parents=True, exist_ok=True)
        partial = target.with_suffix(".iso.part")
        digest = hashlib.sha256()
        size = 0

        limit = min(MAX_BYTES, max_gb * 1024**3) if max_gb else MAX_BYTES
        log.info("Pobieram ISO %s z %s", name, url)
        request = urllib.request.Request(url, headers={"User-Agent": "VirtHub-Agent"})
        try:
            opened = _open_untrusted(url) if untrusted else urllib.request.urlopen(request, timeout=60)
            with opened as response, partial.open("wb") as out:
                length = response.headers.get("Content-Length")
                tracker = TransferProgress(total=int(length) if length and length.isdigit() else None)
                tracker.update(0, force=True)
                while chunk := response.read(CHUNK):
                    size += len(chunk)
                    tracker.update(size)
                    if size > limit:
                        raise IsoError(f"Obraz przekracza limit {limit // 1024**3} GB.")
                    digest.update(chunk)
                    out.write(chunk)
        except IsoError:
            partial.unlink(missing_ok=True)
            raise
        except OSError as exc:
            partial.unlink(missing_ok=True)
            raise IsoError(f"Nie udało się pobrać obrazu: {exc}") from exc

        tracker.update(size, force=True)
        progress("verify", 100, "Sprawdzam sumę kontrolną")
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
