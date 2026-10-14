"""Cele kopii zapasowych: katalog lokalny, S3 (AWS, MinIO, Wasabi, B2…) i SFTP.

Każdy cel umie: wysłać plik pod klucz, pobrać klucz do pliku, usunąć klucz.
Dane dostępowe przychodzą z panelu w treści zadania (nie są zapisywane na
węźle). S3 bez dodatkowych bibliotek: podpis AWS Signature V4 i wysyłka
wieloczęściowa dla dużych dysków.
"""

from __future__ import annotations

import asyncio
import datetime as dt
import hashlib
import hmac
import os
import re
import shutil
from pathlib import Path
from typing import Any, Protocol
from urllib.parse import quote, urlsplit
from xml.etree import ElementTree as ET

import httpx

KEY = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,500}$")
PART_SIZE = int(os.environ.get("VH_BACKUP_PART_MB", "64")) * 1024 * 1024
CHUNK = 1024 * 1024


class BackupTargetError(RuntimeError):
    pass


def _check_key(key: str) -> str:
    if not KEY.match(key) or ".." in key.split("/"):
        raise BackupTargetError(f"Nieprawidłowy klucz kopii: {key!r}")
    return key


class Target(Protocol):
    def upload(self, source: Path, key: str) -> None: ...

    def download(self, key: str, target: Path) -> None: ...

    def delete(self, key: str) -> None: ...


# --- katalog ------------------------------------------------------------------


class LocalTarget:
    """Katalog na węźle (np. zamontowany NFS albo osobny dysk)."""

    def __init__(self, config: dict[str, Any]):
        path = str(config.get("path") or "")
        if not path.startswith("/") or ".." in path.split("/"):
            raise BackupTargetError("Katalog kopii musi być ścieżką bezwzględną.")
        self.root = Path(path)

    def _path(self, key: str) -> Path:
        return self.root / _check_key(key)

    def upload(self, source: Path, key: str) -> None:
        target = self._path(key)
        target.parent.mkdir(parents=True, exist_ok=True)
        partial = target.with_name(f".{target.name}.part")
        shutil.copyfile(source, partial)
        os.replace(partial, target)

    def download(self, key: str, target: Path) -> None:
        source = self._path(key)
        if not source.is_file():
            raise BackupTargetError(f"Brak kopii {key} w {self.root}.")
        shutil.copyfile(source, target)

    def delete(self, key: str) -> None:
        self._path(key).unlink(missing_ok=True)


# --- S3 -------------------------------------------------------------------------


def _sign(key: bytes, msg: str) -> bytes:
    return hmac.new(key, msg.encode(), hashlib.sha256).digest()


def sigv4_headers(method: str, url: str, region: str, access_key: str, secret_key: str,
                  payload_sha256: str, now: dt.datetime | None = None, extra: dict[str, str] | None = None) -> dict[str, str]:
    """Nagłówki AWS Signature V4 dla usługi s3 (ścieżka i zapytanie już zakodowane w url)."""
    now = now or dt.datetime.now(dt.timezone.utc)
    amz_date = now.strftime("%Y%m%dT%H%M%SZ")
    date = now.strftime("%Y%m%d")
    parts = urlsplit(url)
    host = parts.netloc
    query = "&".join(sorted(parts.query.split("&"))) if parts.query else ""
    # Parametry bez wartości (np. ?uploads) podpisujemy jako „uploads=”.
    query = "&".join(p if "=" in p else f"{p}=" for p in query.split("&")) if query else ""
    headers = {"host": host, "x-amz-content-sha256": payload_sha256, "x-amz-date": amz_date, **{k.lower(): v for k, v in (extra or {}).items()}}
    signed = ";".join(sorted(headers))
    canonical = "\n".join([
        method, parts.path or "/", query,
        "".join(f"{k}:{headers[k].strip()}\n" for k in sorted(headers)),
        signed, payload_sha256,
    ])
    scope = f"{date}/{region}/s3/aws4_request"
    to_sign = "\n".join(["AWS4-HMAC-SHA256", amz_date, scope, hashlib.sha256(canonical.encode()).hexdigest()])
    k = _sign(_sign(_sign(_sign(("AWS4" + secret_key).encode(), date), region), "s3"), "aws4_request")
    signature = hmac.new(k, to_sign.encode(), hashlib.sha256).hexdigest()
    out = {k: v for k, v in headers.items() if k != "host"}
    out["Authorization"] = f"AWS4-HMAC-SHA256 Credential={access_key}/{scope}, SignedHeaders={signed}, Signature={signature}"
    return out


class S3Target:
    """Dowolny serwis zgodny z S3 (adresowanie ścieżką: endpoint/bucket/klucz)."""

    def __init__(self, config: dict[str, Any], client: httpx.Client | None = None):
        try:
            self.endpoint = str(config["endpoint"]).rstrip("/")
            self.bucket = str(config["bucket"])
            self.access_key = str(config["access_key"])
            self.secret_key = str(config["secret_key"])
        except KeyError as exc:
            raise BackupTargetError(f"Brak pola {exc.args[0]} w konfiguracji S3.") from None
        if not self.endpoint.startswith(("https://", "http://")):
            raise BackupTargetError("Endpoint S3 musi zaczynać się od https://")
        self.region = str(config.get("region") or "us-east-1")
        self.prefix = str(config.get("prefix") or "").strip("/")
        self.client = client or httpx.Client(timeout=httpx.Timeout(60, read=600))

    def _url(self, key: str, query: str = "") -> str:
        full = "/".join(p for p in (self.prefix, _check_key(key)) if p)
        return f"{self.endpoint}/{quote(self.bucket)}/{quote(full, safe='/')}" + (f"?{query}" if query else "")

    def _request(self, method: str, url: str, body: bytes = b"", extra: dict[str, str] | None = None) -> httpx.Response:
        sha = hashlib.sha256(body).hexdigest()
        headers = sigv4_headers(method, url, self.region, self.access_key, self.secret_key, sha, extra=extra)
        response = self.client.request(method, url, content=body, headers=headers)
        if response.status_code >= 300:
            raise BackupTargetError(f"S3 {method} {urlsplit(url).path}: HTTP {response.status_code} {response.text[:300]}")
        return response

    def upload(self, source: Path, key: str) -> None:
        size = source.stat().st_size
        if size <= PART_SIZE:
            self._request("PUT", self._url(key), source.read_bytes())
            return
        start = self._request("POST", self._url(key, "uploads"))
        upload_id = ET.fromstring(start.text).findtext("{*}UploadId") or ET.fromstring(start.text).findtext("UploadId")
        if not upload_id:
            raise BackupTargetError("S3 nie zwrócił UploadId.")
        etags: list[str] = []
        try:
            with source.open("rb") as fh:
                number = 1
                while chunk := fh.read(PART_SIZE):
                    response = self._request("PUT", self._url(key, f"partNumber={number}&uploadId={quote(upload_id, safe='')}"), chunk)
                    etags.append(response.headers.get("ETag", ""))
                    number += 1
            body = "<CompleteMultipartUpload>" + "".join(
                f"<Part><PartNumber>{i}</PartNumber><ETag>{etag}</ETag></Part>" for i, etag in enumerate(etags, start=1)
            ) + "</CompleteMultipartUpload>"
            self._request("POST", self._url(key, f"uploadId={quote(upload_id, safe='')}"), body.encode())
        except BaseException:
            try:
                self._request("DELETE", self._url(key, f"uploadId={quote(upload_id, safe='')}"))
            except BackupTargetError:
                pass
            raise

    def download(self, key: str, target: Path) -> None:
        url = self._url(key)
        headers = sigv4_headers("GET", url, self.region, self.access_key, self.secret_key, hashlib.sha256(b"").hexdigest())
        with self.client.stream("GET", url, headers=headers) as response:
            if response.status_code >= 300:
                raise BackupTargetError(f"S3 GET: HTTP {response.status_code}")
            with target.open("wb") as out:
                for chunk in response.iter_bytes(CHUNK):
                    out.write(chunk)

    def delete(self, key: str) -> None:
        try:
            self._request("DELETE", self._url(key))
        except BackupTargetError as exc:
            if "HTTP 404" not in str(exc):
                raise


# --- SFTP -------------------------------------------------------------------------


class SftpTarget:
    def __init__(self, config: dict[str, Any]):
        self.host = str(config.get("host") or "")
        self.port = int(config.get("port") or 22)
        self.username = str(config.get("username") or "")
        self.password = config.get("password") or None
        self.private_key = config.get("private_key") or None
        self.root = str(config.get("path") or ".").rstrip("/") or "."
        # Odcisk klucza hosta (SHA256:…) — bez niego łączymy się z zaufaniem do pierwszego klucza.
        self.host_key = config.get("host_key_fingerprint") or None
        if not self.host or not self.username:
            raise BackupTargetError("SFTP wymaga hosta i użytkownika.")

    async def _connect(self):
        import asyncssh

        keys = [asyncssh.import_private_key(self.private_key)] if self.private_key else None
        conn = await asyncssh.connect(self.host, port=self.port, username=self.username, password=self.password,
                                      client_keys=keys, known_hosts=None, connect_timeout=30)
        if self.host_key:
            actual = conn.get_server_host_key().get_fingerprint("sha256")
            if actual != self.host_key:
                conn.close()
                raise BackupTargetError(f"Klucz hosta SFTP się nie zgadza ({actual}).")
        return conn

    def _remote(self, key: str) -> str:
        return f"{self.root}/{_check_key(key)}"

    def _run(self, coro):
        return asyncio.run(coro)

    def upload(self, source: Path, key: str) -> None:
        async def go():
            async with await self._connect() as conn, conn.start_sftp_client() as sftp:
                remote = self._remote(key)
                await sftp.makedirs(remote.rsplit("/", 1)[0], exist_ok=True)
                await sftp.put(str(source), remote + ".part")
                await sftp.posix_rename(remote + ".part", remote) if hasattr(sftp, "posix_rename") else await sftp.rename(remote + ".part", remote)
        self._run(go())

    def download(self, key: str, target: Path) -> None:
        async def go():
            async with await self._connect() as conn, conn.start_sftp_client() as sftp:
                await sftp.get(self._remote(key), str(target))
        self._run(go())

    def delete(self, key: str) -> None:
        async def go():
            import asyncssh

            async with await self._connect() as conn, conn.start_sftp_client() as sftp:
                try:
                    await sftp.remove(self._remote(key))
                except asyncssh.SFTPNoSuchFile:
                    pass
        self._run(go())


def build_target(spec: dict[str, Any]) -> Target:
    kind = spec.get("type")
    config = spec.get("config") or {}
    if kind == "local":
        return LocalTarget(config)
    if kind == "s3":
        return S3Target(config)
    if kind == "sftp":
        return SftpTarget(config)
    raise BackupTargetError(f"Nieznany typ celu kopii: {kind!r}")
