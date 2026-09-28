"""Serwer SFTP aplikacji (jak SFTP w Wings).

Klient loguje się nazwą `u<id użytkownika panelu>.<8 znaków UUID aplikacji>`
i hasłem do panelu. Agent nie zna haseł: pyta panel (podpisane żądanie
sekretem węzła), czy ten użytkownik może zarządzać tą aplikacją. Pozytywny
wynik trzymamy krótko w pamięci, żeby klient SFTP otwierający kilka połączeń
nie odpytywał panelu za każdym razem.

Po zalogowaniu `/` to katalog aplikacji. Każda operacja przechodzi po
katalogach przez deskryptory z O_NOFOLLOW (jak menedżer plików w panelu) —
dowiązanie symboliczne utworzone przez aplikację nie wyprowadzi klienta
poza jej katalog. Tworzenia dowiązań, powłoki, exec i przekierowań portów
nie ma wcale.
"""

from __future__ import annotations

import asyncio
import errno
import hashlib
import logging
import os
import posixpath
import re
import stat
import time
from pathlib import Path
from typing import Any, AsyncIterator

from .apps import AppError, AppFiles, AppManager
from .config import Settings
from .panel import PanelUnavailable, signed_post

log = logging.getLogger("virthub.sftp")

AUTH_PATH = "/api/internal/agent/sftp-auth"
USERNAME = re.compile(r"^u(\d{1,12})\.([0-9a-f]{8})$")
CACHE_TTL = 120

try:  # asyncssh jest zależnością agenta; bez niej SFTP po prostu nie wstaje.
    import asyncssh
    from asyncssh import SFTPAttrs, SFTPError, SFTPName, SFTPServer
    from asyncssh.sftp import (
        FX_FAILURE, FX_NO_SUCH_FILE, FX_OP_UNSUPPORTED, FX_PERMISSION_DENIED,
        FXF_APPEND, FXF_CREAT, FXF_EXCL, FXF_READ, FXF_TRUNC, FXF_WRITE,
    )
except ImportError:  # pragma: no cover
    asyncssh = None  # type: ignore[assignment]
    SFTPServer = object  # type: ignore[assignment,misc]


class SftpAuth:
    """Sprawdzanie haseł w panelu z krótkim cache pozytywnych wyników."""

    def __init__(self, settings: Settings, apps: AppManager):
        self.settings = settings
        self.apps = apps
        self._cache: dict[tuple[str, str], tuple[float, str]] = {}

    def resolve_uuid(self, short: str) -> str | None:
        """Pełny UUID aplikacji z 8-znakowego prefiksu — tylko aplikacje tego węzła."""
        try:
            matches = [p.name for p in self.settings.apps_dir.iterdir()
                       if p.is_dir() and p.name.startswith(short) and not p.name.startswith(".")]
        except OSError:
            return None
        return matches[0] if len(matches) == 1 else None

    def check(self, username: str, password: str) -> str | None:
        """UUID aplikacji, jeśli panel potwierdzi dostęp; inaczej None."""
        match = USERNAME.match(username)
        if not match or not password or len(password) > 1024:
            return None
        uuid = self.resolve_uuid(match.group(2))
        if uuid is None:
            return None

        key = (username, hashlib.sha256(password.encode()).hexdigest())
        cached = self._cache.get(key)
        if cached and time.time() - cached[0] < CACHE_TTL:
            return cached[1]

        if self._ask_panel(uuid, int(match.group(1)), password):
            self._cache[key] = (time.time(), uuid)
            return uuid
        self._cache.pop(key, None)
        return None

    def _ask_panel(self, uuid: str, user_id: int, password: str) -> bool:
        try:
            response = signed_post(self.settings, AUTH_PATH, {"uuid": uuid, "user_id": user_id, "password": password})
        except PanelUnavailable as exc:
            log.warning("SFTP: panel nieosiągalny (%s)", exc)
            return False
        return response.status_code == 200 and bool(response.json().get("allowed"))


def _to_sftp_error(exc: BaseException) -> Exception:
    if isinstance(exc, SFTPError):
        return exc
    if isinstance(exc, FileNotFoundError):
        return SFTPError(FX_NO_SUCH_FILE, "Brak takiego pliku")
    if isinstance(exc, (PermissionError, AppError)):
        return SFTPError(FX_PERMISSION_DENIED, str(exc) or "Brak dostępu")
    if isinstance(exc, OSError):
        if exc.errno == errno.ELOOP:
            return SFTPError(FX_PERMISSION_DENIED, "Dowiązania symboliczne są niedostępne")
        if exc.errno == errno.ENOENT:
            return SFTPError(FX_NO_SUCH_FILE, "Brak takiego pliku")
        if exc.errno in (errno.EACCES, errno.EPERM):
            return SFTPError(FX_PERMISSION_DENIED, "Brak dostępu")
        return SFTPError(FX_FAILURE, exc.strerror or str(exc))
    return SFTPError(FX_FAILURE, str(exc))


class AppSFTPServer(SFTPServer):  # type: ignore[misc,valid-type]
    """SFTP zamknięte w katalogu jednej aplikacji, bez podążania za dowiązaniami."""

    def __init__(self, chan: Any, root: Path, apps: AppManager, uuid: str):
        super().__init__(chan)
        self.files = AppFiles(root)
        self.apps = apps
        self.uuid = uuid

    # --- ścieżki ------------------------------------------------------------

    @staticmethod
    def _norm(path: bytes) -> str:
        text = path.decode("utf-8", errors="surrogateescape")
        return posixpath.normpath("/" + text).lstrip("/")  # „..” ponad korzeń zostaje na korzeniu

    def _parent(self, path: bytes) -> tuple[int, str]:
        rel = self._norm(path)
        if not rel:
            raise SFTPError(FX_PERMISSION_DENIED, "Operacja niedozwolona na katalogu głównym")
        parts = rel.split("/")
        return self.files._open_dir(parts[:-1]), parts[-1]

    def _call(self, fn, *args):
        try:
            return fn(*args)
        except Exception as exc:  # noqa: BLE001 — każdy błąd zamieniamy na kod SFTP
            raise _to_sftp_error(exc) from None

    # --- informacje o plikach ------------------------------------------------

    def realpath(self, path: bytes) -> bytes:
        return ("/" + self._norm(path)).encode("utf-8", errors="surrogateescape")

    def _stat(self, path: bytes, follow: bool) -> SFTPAttrs:
        def op():
            rel = self._norm(path)
            if not rel:
                return SFTPAttrs.from_local(os.stat(self.files.root))
            dfd, name = self._parent(path)
            try:
                return SFTPAttrs.from_local(os.stat(name, dir_fd=dfd, follow_symlinks=False))
            finally:
                os.close(dfd)
        return self._call(op)

    def stat(self, path: bytes) -> SFTPAttrs:
        return self._stat(path, True)

    def lstat(self, path: bytes) -> SFTPAttrs:
        return self._stat(path, False)

    def fstat(self, file_obj: object) -> SFTPAttrs:
        return self._call(lambda: SFTPAttrs.from_local(os.fstat(file_obj)))  # type: ignore[arg-type]

    def setstat(self, path: bytes, attrs: SFTPAttrs) -> None:
        """Czas modyfikacji i prawa ustawiane przez klientów po wgraniu — obsługujemy
        tylko skracanie pliku; resztę pomijamy (bez błędu, żeby wgrywanie działało)."""
        if attrs.size is not None:
            def op():
                dfd, name = self._parent(path)
                try:
                    fd = os.open(name, os.O_WRONLY | os.O_NOFOLLOW, dir_fd=dfd)
                    try:
                        os.ftruncate(fd, attrs.size)
                    finally:
                        os.close(fd)
                finally:
                    os.close(dfd)
            self._call(op)

    def fsetstat(self, file_obj: object, attrs: SFTPAttrs) -> None:
        if attrs.size is not None:
            self._call(lambda: os.ftruncate(file_obj, attrs.size))  # type: ignore[arg-type]

    def statvfs(self, path: bytes) -> Any:
        return self._call(lambda: asyncssh.SFTPVFSAttrs.from_local(os.statvfs(self.files.root)))

    async def scandir(self, path: bytes) -> AsyncIterator[SFTPName]:
        rel = self._norm(path)
        try:
            dfd = self.files._open_dir(rel.split("/") if rel else [])
        except Exception as exc:  # noqa: BLE001
            raise _to_sftp_error(exc) from None
        try:
            names = [b".", b".."]
            with os.scandir(dfd) as it:
                entries = [(e.name, e.stat(follow_symlinks=False)) for e in it]
            for name in names:
                yield SFTPName(name, attrs=SFTPAttrs.from_local(os.fstat(dfd)))
            for name, st in entries:
                yield SFTPName(name.encode("utf-8", errors="surrogateescape"), attrs=SFTPAttrs.from_local(st))
        finally:
            os.close(dfd)

    def readlink(self, path: bytes) -> bytes:
        def op():
            dfd, name = self._parent(path)
            try:
                return os.fsencode(os.readlink(name, dir_fd=dfd))
            finally:
                os.close(dfd)
        return self._call(op)

    # --- pliki -----------------------------------------------------------------

    def open(self, path: bytes, pflags: int, attrs: SFTPAttrs) -> object:
        writing = bool(pflags & (FXF_WRITE | FXF_APPEND | FXF_CREAT | FXF_TRUNC))
        if writing:
            self._check_quota()
        flags = os.O_NOFOLLOW | os.O_CLOEXEC
        if pflags & FXF_READ and writing:
            flags |= os.O_RDWR
        elif writing:
            flags |= os.O_WRONLY
        else:
            flags |= os.O_RDONLY
        if pflags & FXF_APPEND:
            flags |= os.O_APPEND
        if pflags & FXF_CREAT:
            flags |= os.O_CREAT
        if pflags & FXF_TRUNC:
            flags |= os.O_TRUNC
        if pflags & FXF_EXCL:
            flags |= os.O_EXCL

        def op():
            dfd, name = self._parent(path)
            try:
                fd = os.open(name, flags, 0o644, dir_fd=dfd)
            finally:
                os.close(dfd)
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                os.close(fd)
                raise SFTPError(FX_PERMISSION_DENIED, "To nie jest zwykły plik")
            return fd
        return self._call(op)

    def close(self, file_obj: object) -> None:
        self._call(lambda: os.close(file_obj))  # type: ignore[arg-type]

    def read(self, file_obj: object, offset: int, size: int) -> bytes:
        return self._call(lambda: os.pread(file_obj, size, offset))  # type: ignore[arg-type]

    def write(self, file_obj: object, offset: int, data: bytes) -> int:
        return self._call(lambda: os.pwrite(file_obj, data, offset))  # type: ignore[arg-type]

    def _check_quota(self) -> None:
        try:
            spec = self.apps.load_spec(self.uuid)
        except AppError:
            return
        used = self.apps._disk_usage(self.uuid) or 0
        if spec.disk_mb and used >= spec.disk_mb * 1024 * 1024:
            raise SFTPError(FX_FAILURE, f"Brak miejsca: limit dysku aplikacji to {spec.disk_mb} MB")

    # --- katalogi i nazwy ----------------------------------------------------------

    def mkdir(self, path: bytes, attrs: SFTPAttrs) -> None:
        def op():
            dfd, name = self._parent(path)
            try:
                os.mkdir(name, 0o755, dir_fd=dfd)
            finally:
                os.close(dfd)
        self._call(op)

    def rmdir(self, path: bytes) -> None:
        def op():
            dfd, name = self._parent(path)
            try:
                os.rmdir(name, dir_fd=dfd)
            finally:
                os.close(dfd)
        self._call(op)

    def remove(self, path: bytes) -> None:
        def op():
            dfd, name = self._parent(path)
            try:
                os.unlink(name, dir_fd=dfd)  # dowiązanie znika samo, cel zostaje
            finally:
                os.close(dfd)
        self._call(op)

    def rename(self, oldpath: bytes, newpath: bytes) -> None:
        def op():
            sfd, sname = self._parent(oldpath)
            try:
                tfd, tname = self._parent(newpath)
                try:
                    os.rename(sname, tname, src_dir_fd=sfd, dst_dir_fd=tfd)
                finally:
                    os.close(tfd)
            finally:
                os.close(sfd)
        self._call(op)

    posix_rename = rename

    def symlink(self, oldpath: bytes, newpath: bytes) -> None:
        raise SFTPError(FX_OP_UNSUPPORTED, "Tworzenie dowiązań jest wyłączone")

    def link(self, oldpath: bytes, newpath: bytes) -> None:
        raise SFTPError(FX_OP_UNSUPPORTED, "Tworzenie dowiązań jest wyłączone")


class SftpService:
    """Serwer SSH przyjmujący wyłącznie podsystem SFTP."""

    def __init__(self, settings: Settings, apps: AppManager, port: int, host: str = "0.0.0.0"):
        self.settings = settings
        self.apps = apps
        self.port = port
        self.host = host
        self.auth = SftpAuth(settings, apps)
        self._acceptor: Any = None
        self._sessions: dict[str, str] = {}  # nazwa użytkownika → UUID po udanym logowaniu

    def host_key_path(self) -> Path:
        return self.settings.state_db.parent / "sftp_host_ed25519"

    def _host_key(self) -> Any:
        path = self.host_key_path()
        if path.exists():
            return asyncssh.read_private_key(str(path))
        key = asyncssh.generate_private_key("ssh-ed25519")
        path.parent.mkdir(parents=True, exist_ok=True)
        key.write_private_key(str(path))
        os.chmod(path, 0o600)
        return key

    async def start(self) -> bool:
        if asyncssh is None:
            log.warning("Brak biblioteki asyncssh — SFTP aplikacji wyłączone")
            return False
        service = self

        class Server(asyncssh.SSHServer):
            def __init__(self) -> None:
                self._conn: Any = None

            def connection_made(self, conn: Any) -> None:
                self._conn = conn

            def begin_auth(self, username: str) -> bool:
                return True

            def password_auth_supported(self) -> bool:
                return True

            async def validate_password(self, username: str, password: str) -> bool:
                uuid = await asyncio.to_thread(service.auth.check, username, password)
                if uuid is None:
                    peer = self._conn.get_extra_info("peername") if self._conn else None
                    log.info("SFTP: odrzucono logowanie %s z %s", username, peer[0] if peer else "?")
                    await asyncio.sleep(1)  # spowolnienie zgadywania haseł
                    return False
                service._sessions[username] = uuid
                return True

        def sftp_factory(chan: Any) -> AppSFTPServer:
            username = chan.get_extra_info("username")
            uuid = service._sessions[username]
            return AppSFTPServer(chan, service.apps.data_dir(uuid), service.apps, uuid)

        try:
            self._acceptor = await asyncssh.create_server(
                Server, self.host, self.port,
                server_host_keys=[self._host_key()],
                sftp_factory=sftp_factory,
                allow_scp=False,
                agent_forwarding=False,
                x11_forwarding=False,
                login_timeout=30,
                keepalive_interval=30,
            )
        except OSError as exc:
            log.warning("SFTP aplikacji nie wystartował na porcie %s: %s", self.port, exc)
            return False
        log.info("SFTP aplikacji nasłuchuje na %s:%s", self.host, self.port)
        return True

    def stop(self) -> None:
        if self._acceptor is not None:
            self._acceptor.close()
            self._acceptor = None
