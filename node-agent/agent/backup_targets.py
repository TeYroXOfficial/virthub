"""Cele kopii zapasowych: katalog lokalny, S3 (AWS, MinIO, Wasabi, B2…), SFTP,
FTP/FTPS, Rclone (ponad 35 chmur) i Proxmox Backup Server.

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


# --- FTP / FTPS --------------------------------------------------------------------


class FtpTarget:
    """FTP albo FTPS (jawne TLS, AUTH TLS) z kanałem danych chronionym (PROT P)."""

    def __init__(self, config: dict[str, Any], factory: Any = None):
        self.host = str(config.get("host") or "")
        self.port = int(config.get("port") or 21)
        self.username = str(config.get("username") or "anonymous")
        self.password = str(config.get("password") or "")
        self.root = "/" + str(config.get("path") or "").strip("/")
        self.tls = bool(config.get("tls", True))
        self.passive = bool(config.get("passive", True))
        self.factory = factory
        if not self.host:
            raise BackupTargetError("FTP wymaga hosta.")

    def _connect(self):
        import ftplib
        import ssl

        if self.factory is not None:
            ftp = self.factory()
        elif self.tls:
            ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=60)
        else:
            ftp = ftplib.FTP(timeout=60)
        try:
            ftp.connect(self.host, self.port)
            ftp.login(self.username, self.password)
            if self.tls and hasattr(ftp, "prot_p"):
                ftp.prot_p()
            ftp.set_pasv(self.passive)
        except ftplib.all_errors as exc:
            raise BackupTargetError(f"Połączenie FTP nieudane: {exc}") from exc
        return ftp

    def _remote(self, key: str) -> str:
        return f"{self.root.rstrip('/')}/{_check_key(key)}"

    def _makedirs(self, ftp, remote: str) -> None:
        import ftplib

        path = ""
        for part in remote.strip("/").split("/")[:-1]:
            path += "/" + part
            try:
                ftp.mkd(path)
            except ftplib.error_perm:
                pass  # katalog już jest

    def upload(self, source: Path, key: str) -> None:
        import ftplib

        ftp = self._connect()
        remote = self._remote(key)
        try:
            self._makedirs(ftp, remote)
            with source.open("rb") as fh:
                ftp.storbinary(f"STOR {remote}.part", fh, blocksize=CHUNK)
            ftp.rename(f"{remote}.part", remote)
        except ftplib.all_errors as exc:
            raise BackupTargetError(f"Wysyłka FTP nieudana: {exc}") from exc
        finally:
            ftp.close()

    def download(self, key: str, target: Path) -> None:
        import ftplib

        ftp = self._connect()
        try:
            with target.open("wb") as fh:
                ftp.retrbinary(f"RETR {self._remote(key)}", fh.write, blocksize=CHUNK)
        except ftplib.all_errors as exc:
            raise BackupTargetError(f"Pobranie FTP nieudane: {exc}") from exc
        finally:
            ftp.close()

    def delete(self, key: str) -> None:
        import ftplib

        ftp = self._connect()
        try:
            ftp.delete(self._remote(key))
        except ftplib.error_perm as exc:
            if not str(exc).startswith("550"):
                raise BackupTargetError(f"Usunięcie FTP nieudane: {exc}") from exc
        finally:
            ftp.close()


# --- Rclone ----------------------------------------------------------------------


RCLONE_TYPE = re.compile(r"^[a-z0-9]{2,32}$")
RCLONE_PARAM = re.compile(r"^[a-z0-9_]{1,64}$")


class RcloneTarget:
    """Dowolny backend rclone (Google Drive, B2, OneDrive, Dropbox, Swift…).
    Konfiguracja przychodzi z panelu i trafia do pliku tymczasowego (0600)
    tylko na czas operacji — na węźle nie zostaje żaden sekret."""

    def __init__(self, config: dict[str, Any], runner: Any = None):
        self.backend = str(config.get("backend") or "")
        self.params = {str(k): str(v) for k, v in (config.get("params") or {}).items()}
        self.root = str(config.get("path") or "").strip("/")
        self.runner = runner
        if not RCLONE_TYPE.match(self.backend):
            raise BackupTargetError("Rclone wymaga typu backendu (np. drive, b2, onedrive).")
        bad = [k for k, v in self.params.items() if not RCLONE_PARAM.match(k) or "\n" in v or "\r" in v]
        if bad:
            raise BackupTargetError(f"Nieprawidłowe parametry rclone: {', '.join(bad)}")

    def _remote(self, key: str) -> str:
        return f"vh:{self.root}/{_check_key(key)}" if self.root else f"vh:{_check_key(key)}"

    def _rclone(self, *args: str) -> None:
        import tempfile

        from .shell import CommandError, run

        lines = ["[vh]", f"type = {self.backend}", *[f"{k} = {v}" for k, v in self.params.items()]]
        fd, conf = tempfile.mkstemp(prefix="vh-rclone-", suffix=".conf")
        try:
            with os.fdopen(fd, "w", encoding="utf-8") as fh:
                fh.write("\n".join(lines) + "\n")
            argv = ["rclone", "--config", conf, "--retries", "3", "--low-level-retries", "10", *args]
            try:
                (self.runner or run)(argv, timeout=6 * 3600)
            except CommandError as exc:
                if exc.returncode == 127:
                    raise BackupTargetError("Na węźle brak rclone — zainstaluj pakiet rclone.") from exc
                raise BackupTargetError(f"rclone: {(exc.stderr or str(exc)).strip()[:300]}") from exc
        finally:
            Path(conf).unlink(missing_ok=True)

    def upload(self, source: Path, key: str) -> None:
        self._rclone("copyto", str(source), self._remote(key))

    def download(self, key: str, target: Path) -> None:
        self._rclone("copyto", self._remote(key), str(target))

    def delete(self, key: str) -> None:
        self._rclone("deletefile", self._remote(key))


# --- Proxmox Backup Server ----------------------------------------------------------


class PbsTarget:
    """Proxmox Backup Server przez proxmox-backup-client.

    Każda kopia to osobna migawka typu vm z identyfikatorem wyprowadzonym z
    klucza i stałym czasem — dzięki temu klucz panelu jednoznacznie wskazuje
    migawkę bez zapisywania czegokolwiek na węźle. Dysk idzie do PBS jako
    obraz surowy (rzadki): PBS dzieli go na kawałki, deduplikuje między
    wszystkimi kopiami w magazynie i pomija bloki zerowe.
    """

    BACKUP_TIME = 946684800  # 2000-01-01T00:00:00Z — czas migawki jest stały, identyfikator unikalny

    def __init__(self, config: dict[str, Any], runner: Any = None):
        self.host = str(config.get("host") or "")
        self.port = int(config.get("port") or 8007)
        self.datastore = str(config.get("datastore") or "")
        self.username = str(config.get("username") or "")  # user@realm albo user@realm!token
        self.password = str(config.get("password") or "")
        self.fingerprint = str(config.get("fingerprint") or "")
        self.namespace = str(config.get("namespace") or "")
        self.runner = runner
        if not (self.host and self.datastore and self.username and self.password):
            raise BackupTargetError("PBS wymaga hosta, magazynu, użytkownika (lub tokenu) i hasła.")
        if not re.fullmatch(r"[A-Za-z0-9._@!-]{1,128}", self.username) or not re.fullmatch(r"[A-Za-z0-9._-]{1,64}", self.datastore):
            raise BackupTargetError("Nieprawidłowy użytkownik albo magazyn PBS.")

    @staticmethod
    def backup_id(key: str) -> str:
        return re.sub(r"[^A-Za-z0-9_-]", "-", _check_key(key).rsplit(".", 1)[0])[:120]

    def snapshot(self, key: str) -> str:
        return f"vm/{self.backup_id(key)}/2000-01-01T00:00:00Z"

    def _run(self, *args: str) -> None:
        from .shell import CommandError, run

        env = {**os.environ, "PBS_PASSWORD": self.password, "PBS_REPOSITORY": f"{self.username}@{self.host}:{self.port}:{self.datastore}"}
        if self.fingerprint:
            env["PBS_FINGERPRINT"] = self.fingerprint
        argv = ["proxmox-backup-client", *args, *(["--ns", self.namespace] if self.namespace else [])]
        try:
            (self.runner or run)(argv, timeout=6 * 3600, env=env)
        except CommandError as exc:
            if exc.returncode == 127:
                raise BackupTargetError("Na węźle brak proxmox-backup-client (pakiet proxmox-backup-client z repozytorium Proxmox).") from exc
            raise BackupTargetError(f"PBS: {(exc.stderr or str(exc)).strip()[:300]}") from exc

    def upload(self, source: Path, key: str) -> None:
        from .shell import run

        raw = source.with_name(source.name + ".raw")
        try:
            (self.runner or run)(["qemu-img", "convert", "-O", "raw", str(source), str(raw)], timeout=6 * 3600)
            self._run("backup", f"disk.img:{raw}", "--backup-type", "vm", "--backup-id", self.backup_id(key),
                      "--backup-time", str(self.BACKUP_TIME))
        finally:
            raw.unlink(missing_ok=True)

    def download(self, key: str, target: Path) -> None:
        from .shell import run

        raw = target.with_name(target.name + ".raw")
        try:
            self._run("restore", self.snapshot(key), "disk.img", str(raw))
            (self.runner or run)(["qemu-img", "convert", "-f", "raw", "-O", "qcow2", str(raw), str(target)], timeout=6 * 3600)
        finally:
            raw.unlink(missing_ok=True)

    def delete(self, key: str) -> None:
        try:
            self._run("snapshot", "forget", self.snapshot(key))
        except BackupTargetError as exc:
            if "does not exist" not in str(exc) and "not found" not in str(exc):
                raise


def build_target(spec: dict[str, Any]) -> Target:
    kind = spec.get("type")
    config = spec.get("config") or {}
    if kind == "local":
        return LocalTarget(config)
    if kind == "s3":
        return S3Target(config)
    if kind == "sftp":
        return SftpTarget(config)
    if kind == "ftp":
        return FtpTarget(config)
    if kind == "rclone":
        return RcloneTarget(config)
    if kind == "pbs":
        return PbsTarget(config)
    raise BackupTargetError(f"Nieznany typ celu kopii: {kind!r}")
