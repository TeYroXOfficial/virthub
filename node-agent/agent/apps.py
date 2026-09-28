"""Aplikacje: serwery gier, boty Discord i inne usługi w kontenerach Dockera.

Odpowiednik Wings z Pterodactyla, wbudowany w agenta węzła. Szablonem
aplikacji jest egg (format Pterodactyla), więc działają te same obrazy
(ghcr.io/pterodactyl/yolks, installers) i te same skrypty instalacyjne:

* instalacja — skrypt eggu w osobnym kontenerze (jako root), z katalogiem
  aplikacji pod /mnt/server; na koniec pliki przechodzą na użytkownika agenta;
* uruchomienie — kontener z obrazem wybranym przez klienta, katalog aplikacji
  pod /home/container, polecenie startowe w zmiennej STARTUP (obrazy yolks
  podstawiają w nim {{ZMIENNE}} i uruchamiają je same);
* konsola — logi kontenera i polecenia wpisywane na jego stdin.

Kontener działa jako użytkownik agenta (jak Wings z użytkownikiem
pterodactyl), więc agent — który nie jest rootem — może zarządzać plikami.
Pliki obsługujemy wyłącznie przez deskryptory katalogów z O_NOFOLLOW: klient
kontroluje zawartość katalogu i mógłby podmienić katalog na dowiązanie do
/etc między sprawdzeniem ścieżki a jej otwarciem.
"""

from __future__ import annotations

import base64
import errno
import hashlib
import io
import json
import logging
import os
import re
import shutil
import signal as signals
import stat
import tarfile
import threading
import time
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from .config import Settings
from .jobs import progress
from .schemas import AppConfigFile, AppSpec

log = logging.getLogger("virthub.apps")

CONTAINER_PREFIX = "vh-app-"
LABEL = "virthub.app"
SPEC_LABEL = "virthub.spec"
DATA_MOUNT = "/home/container"
INSTALL_TIMEOUT = 45 * 60
STOP_TIMEOUT = 30
MAX_READ = 10 * 1024 * 1024
MAX_WRITE = 50 * 1024 * 1024
MAX_LIST = 5000
MAX_EXTRACT_ENTRIES = 50_000

# Jak Wings: bez uprawnień, których serwer gry nie potrzebuje, a które
# ułatwiają ucieczkę z kontenera albo podsłuch sieci.
CAP_DROP = [
    "setpcap", "mknod", "audit_write", "net_raw", "dac_override", "fowner",
    "fsetid", "net_bind_service", "sys_chroot", "setfcap",
]


class AppError(RuntimeError):
    """Błąd z czytelnym powodem — trafia do panelu jako 409."""


class AppNotFound(AppError):
    pass


# --- pliki: operacje odporne na dowiązania symboliczne ------------------------------

class AppFiles:
    """Pliki jednej aplikacji. Każda ścieżka jest rozkładana na składowe i
    otwierana od katalogu głównego przez deskryptory z O_NOFOLLOW — dowiązanie
    symboliczne w ścieżce kończy operację błędem zamiast wyjść poza katalog."""

    DIR_FLAGS = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW | os.O_CLOEXEC

    def __init__(self, root: Path):
        self.root = root

    @staticmethod
    def parts(path: str) -> list[str]:
        parts = [p for p in path.replace("\\", "/").split("/") if p not in ("", ".")]
        if any(p == ".." for p in parts):
            raise AppError("Ścieżka nie może zawierać „..”.")
        return parts

    def _open_dir(self, parts: list[str], create: bool = False) -> int:
        fd = os.open(self.root, self.DIR_FLAGS)
        try:
            for part in parts:
                try:
                    nxt = os.open(part, self.DIR_FLAGS, dir_fd=fd)
                except FileNotFoundError:
                    if not create:
                        raise AppError(f"Katalog {part} nie istnieje.") from None
                    os.mkdir(part, 0o755, dir_fd=fd)
                    nxt = os.open(part, self.DIR_FLAGS, dir_fd=fd)
                except OSError as exc:
                    if exc.errno in (errno.ELOOP, errno.ENOTDIR):
                        raise AppError(f"{part} nie jest katalogiem.") from None
                    raise
                os.close(fd)
                fd = nxt
            return fd
        except BaseException:
            os.close(fd)
            raise

    def _split(self, path: str) -> tuple[list[str], str]:
        parts = self.parts(path)
        if not parts:
            raise AppError("Podaj nazwę pliku.")
        return parts[:-1], parts[-1]

    def list(self, path: str = "") -> list[dict[str, Any]]:
        fd = self._open_dir(self.parts(path))
        try:
            entries = []
            with os.scandir(fd) as it:
                for entry in it:
                    if len(entries) >= MAX_LIST:
                        break
                    st = entry.stat(follow_symlinks=False)
                    entries.append({
                        "name": entry.name,
                        "directory": stat.S_ISDIR(st.st_mode),
                        "symlink": stat.S_ISLNK(st.st_mode),
                        "size": st.st_size,
                        "modified": int(st.st_mtime),
                        "mode": stat.filemode(st.st_mode),
                    })
        finally:
            os.close(fd)
        entries.sort(key=lambda e: (not e["directory"], e["name"].lower()))
        return entries

    def read(self, path: str, limit: int = MAX_READ) -> bytes:
        parents, name = self._split(path)
        dfd = self._open_dir(parents)
        try:
            try:
                fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=dfd)
            except FileNotFoundError:
                raise AppError(f"Plik {path} nie istnieje.") from None
            except OSError as exc:
                if exc.errno == errno.ELOOP:
                    raise AppError("Dowiązań symbolicznych nie da się otworzyć w panelu.") from None
                raise
            with os.fdopen(fd, "rb") as fh:
                if not stat.S_ISREG(os.fstat(fh.fileno()).st_mode):
                    raise AppError(f"{path} nie jest zwykłym plikiem.")
                data = fh.read(limit + 1)
        finally:
            os.close(dfd)
        if len(data) > limit:
            raise AppError(f"Plik jest większy niż {limit // (1024 * 1024)} MB — pobierz go przez SFTP albo spakuj.")
        return data

    def write(self, path: str, data: bytes) -> None:
        if len(data) > MAX_WRITE:
            raise AppError(f"Plik może mieć najwyżej {MAX_WRITE // (1024 * 1024)} MB.")
        parents, name = self._split(path)
        dfd = self._open_dir(parents, create=True)
        try:
            self._write_at(dfd, name, data)
        finally:
            os.close(dfd)

    @staticmethod
    def _write_at(dfd: int, name: str, data: bytes | io.BufferedReader, mode: int = 0o644) -> None:
        try:
            fd = os.open(name, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW | os.O_CLOEXEC, mode, dir_fd=dfd)
        except OSError as exc:
            if exc.errno == errno.ELOOP:
                raise AppError("Nie można nadpisać dowiązania symbolicznego.") from None
            if exc.errno == errno.EISDIR:
                raise AppError(f"{name} jest katalogiem.") from None
            raise
        with os.fdopen(fd, "wb") as fh:
            if isinstance(data, (bytes, bytearray)):
                fh.write(data)
            else:
                shutil.copyfileobj(data, fh, 1024 * 1024)

    def mkdir(self, path: str) -> None:
        parents, name = self._split(path)
        dfd = self._open_dir(parents, create=True)
        try:
            os.mkdir(name, 0o755, dir_fd=dfd)
        except FileExistsError:
            raise AppError(f"{path} już istnieje.") from None
        finally:
            os.close(dfd)

    def delete(self, path: str) -> None:
        parents, name = self._split(path)
        dfd = self._open_dir(parents)
        try:
            try:
                st = os.stat(name, dir_fd=dfd, follow_symlinks=False)
            except FileNotFoundError:
                return
            if stat.S_ISDIR(st.st_mode):
                shutil.rmtree(name, dir_fd=dfd)
            else:
                os.unlink(name, dir_fd=dfd)  # dowiązanie usuwamy samo, bez celu
        finally:
            os.close(dfd)

    def rename(self, source: str, target: str) -> None:
        sp, sn = self._split(source)
        tp, tn = self._split(target)
        sfd = self._open_dir(sp)
        try:
            tfd = self._open_dir(tp, create=True)
            try:
                try:
                    os.stat(tn, dir_fd=tfd, follow_symlinks=False)
                    raise AppError(f"{target} już istnieje.")
                except FileNotFoundError:
                    pass
                try:
                    os.rename(sn, tn, src_dir_fd=sfd, dst_dir_fd=tfd)
                except FileNotFoundError:
                    raise AppError(f"{source} nie istnieje.") from None
            finally:
                os.close(tfd)
        finally:
            os.close(sfd)

    def extract(self, path: str, limit_bytes: int | None = None) -> int:
        """Rozpakowuje .zip/.tar(.gz/.bz2/.xz) do katalogu archiwum.

        Każdy wpis przechodzi przez tę samą ścieżkę co zapis z panelu —
        „../” i dowiązania w archiwum nie wyjdą poza katalog aplikacji.
        """
        parents, name = self._split(path)
        base = "/".join(parents)
        written = 0
        count = 0

        def target(member: str) -> str:
            member = member.replace("\\", "/").lstrip("/")
            self.parts(member)  # odrzuca „..”
            return f"{base}/{member}" if base else member

        def account(size: int) -> None:
            nonlocal written, count
            written += size
            count += 1
            if count > MAX_EXTRACT_ENTRIES:
                raise AppError("Archiwum ma za dużo plików.")
            if limit_bytes and written > limit_bytes:
                raise AppError("Rozpakowane pliki nie zmieszczą się w limicie dysku.")

        lower = name.lower()
        if not (lower.endswith(".zip") or re.search(r"\.(tar|tar\.gz|tgz|tar\.bz2|tar\.xz|txz)$", lower)):
            raise AppError("Obsługiwane archiwa: .zip, .tar, .tar.gz, .tgz, .tar.bz2, .tar.xz.")
        with self._open_file(parents, name) as fh:
            if lower.endswith(".zip"):
                self._extract_zip(fh, target, account)
            else:
                self._extract_tar(fh, target, account)
        return count

    def _extract_zip(self, fh: Any, target: Any, account: Any) -> None:
        with zipfile.ZipFile(fh) as archive:
            for info in archive.infolist():
                dest = target(info.filename)
                if info.is_dir():
                    self._open_close_dir(dest)
                    continue
                if stat.S_ISLNK(info.external_attr >> 16):
                    continue  # dowiązań z archiwum nie odtwarzamy
                account(info.file_size)
                with archive.open(info) as src:
                    self._write_stream(dest, src)

    def _extract_tar(self, fh: Any, target: Any, account: Any) -> None:
        with tarfile.open(fileobj=fh) as archive:
            for member in archive:
                dest = target(member.name)
                if member.isdir():
                    self._open_close_dir(dest)
                elif member.isfile():
                    account(member.size)
                    src = archive.extractfile(member)
                    if src is not None:
                        self._write_stream(dest, src, member.mode & 0o755 | 0o600)
                # dowiązania, urządzenia i FIFO pomijamy

    def _open_file(self, parents: list[str], name: str) -> Any:
        dfd = self._open_dir(parents)
        try:
            fd = os.open(name, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC, dir_fd=dfd)
        except FileNotFoundError:
            raise AppError(f"Plik {name} nie istnieje.") from None
        except OSError as exc:
            if exc.errno == errno.ELOOP:
                raise AppError("Dowiązań symbolicznych nie da się otworzyć w panelu.") from None
            raise
        finally:
            os.close(dfd)
        return os.fdopen(fd, "rb")

    def _open_close_dir(self, path: str) -> None:
        if self.parts(path):
            os.close(self._open_dir(self.parts(path), create=True))

    def _write_stream(self, path: str, src: Any, mode: int = 0o644) -> None:
        parents, name = self._split(path)
        dfd = self._open_dir(parents, create=True)
        try:
            self._write_at(dfd, name, src, mode)
        finally:
            os.close(dfd)

    def usage_bytes(self) -> int:
        total = 0
        for dirpath, dirnames, filenames in os.walk(self.root, followlinks=False):
            for name in filenames:
                try:
                    total += os.lstat(os.path.join(dirpath, name)).st_blocks * 512
                except OSError:
                    pass
        return total


# --- pliki konfiguracyjne (config.files z eggów) -------------------------------------

def apply_config_file(files: AppFiles, cfg: AppConfigFile) -> None:
    """Ustawia wartości w pliku konfiguracyjnym przed startem — np. port z
    przydziału w server.properties. Brakujący plik powstaje."""
    try:
        text = files.read(cfg.file, limit=2 * 1024 * 1024).decode("utf-8", errors="replace")
    except AppError:
        text = ""

    if cfg.parser in ("properties", "ini", "file"):
        lines = text.splitlines()
        for key, value in cfg.replace.items():
            if cfg.parser == "file":
                new = value
                match = lambda line, k=key: line.startswith(k)
            else:
                sep = " = " if cfg.parser == "ini" else "="
                new = f"{key}{sep}{value}"
                pattern = re.compile(rf"^\s*{re.escape(key)}\s*[=:]")
                match = lambda line, p=pattern: bool(p.match(line))
            for i, line in enumerate(lines):
                if match(line):
                    lines[i] = new
                    break
            else:
                lines.append(new)
        out = "\n".join(lines) + "\n"
    elif cfg.parser == "json":
        try:
            doc = json.loads(text) if text.strip() else {}
        except ValueError:
            log.warning("Plik %s nie jest poprawnym JSON-em — pomijam", cfg.file)
            return
        for key, value in cfg.replace.items():
            _set_path(doc, key, _typed(value))
        out = json.dumps(doc, indent=2, ensure_ascii=False) + "\n"
    else:  # yaml
        try:
            import yaml  # type: ignore[import-not-found]
        except ImportError:
            log.warning("Brak PyYAML — pomijam plik %s", cfg.file)
            return
        doc = yaml.safe_load(text) if text.strip() else {}
        doc = doc if isinstance(doc, dict) else {}
        for key, value in cfg.replace.items():
            _set_path(doc, key, _typed(value))
        out = yaml.safe_dump(doc, sort_keys=False, allow_unicode=True)

    files.write(cfg.file, out.encode("utf-8"))


def _typed(value: str) -> Any:
    if value.isdigit():
        return int(value)
    if value.lower() in ("true", "false"):
        return value.lower() == "true"
    return value


def _set_path(doc: dict, dotted: str, value: Any) -> None:
    node = doc
    keys = dotted.split(".")
    for key in keys[:-1]:
        if not isinstance(node.get(key), dict):
            node[key] = {}
        node = node[key]
    node[keys[-1]] = value


# --- menedżer ---------------------------------------------------------------------

def spec_hash(spec: AppSpec) -> str:
    """Skrót tego, co wymaga nowego kontenera (obraz, zmienne, zasoby, porty)."""
    relevant = spec.model_dump(exclude={"install", "config_files"})
    return hashlib.sha256(json.dumps(relevant, sort_keys=True).encode()).hexdigest()[:16]


class AppManager:
    def __init__(self, settings: Settings, client: Any = None):
        self.settings = settings
        self._client = client
        self._installing: set[str] = set()
        self._lock = threading.Lock()
        self._usage_cache: dict[str, tuple[float, int]] = {}
        self.uid = os.getuid()
        self.gid = os.getgid()

    # --- dostęp do Dockera --------------------------------------------------

    @property
    def client(self) -> Any:
        if self._client is None:
            try:
                import docker  # type: ignore[import-not-found]
            except ImportError as exc:
                raise AppError("Na węźle brakuje biblioteki docker — zaktualizuj węzeł.") from exc
            try:
                self._client = docker.from_env(timeout=120)
                self._client.ping()
            except Exception as exc:
                self._client = None
                raise AppError(
                    "Docker na węźle nie odpowiada. Zainstaluj go poleceniem aktualizacji węzła "
                    "z VH_APPS=1 i sprawdź, czy agent należy do grupy docker."
                ) from exc
        return self._client

    def health(self) -> dict[str, Any]:
        try:
            version = self.client.version()
            containers = self.client.containers.list(all=True, filters={"label": LABEL})
            return {
                "available": True,
                "docker_version": version.get("Version"),
                "apps": len(containers),
                "running": sum(1 for c in containers if c.status == "running"),
            }
        except Exception as exc:
            return {"available": False, "error": str(exc)[:300]}

    # --- ścieżki i stan ------------------------------------------------------

    def data_dir(self, uuid: str) -> Path:
        return self.settings.apps_dir / uuid

    def files(self, uuid: str) -> AppFiles:
        root = self.data_dir(uuid)
        if not root.is_dir():
            raise AppNotFound(f"Aplikacja {uuid} nie istnieje na tym węźle.")
        return AppFiles(root)

    def _meta_dir(self) -> Path:
        path = self.settings.apps_dir / ".virthub"
        path.mkdir(parents=True, exist_ok=True, mode=0o700)
        return path

    def _spec_file(self, uuid: str) -> Path:
        return self._meta_dir() / f"{uuid}.json"

    def install_log_file(self, uuid: str) -> Path:
        return self._meta_dir() / f"{uuid}.install.log"

    def load_spec(self, uuid: str) -> AppSpec:
        path = self._spec_file(uuid)
        if not path.exists():
            raise AppNotFound(f"Aplikacja {uuid} nie istnieje na tym węźle.")
        return AppSpec(**json.loads(path.read_text(encoding="utf-8")))

    def save_spec(self, spec: AppSpec) -> None:
        path = self._spec_file(spec.uuid)
        tmp = path.with_suffix(".tmp")
        tmp.write_text(spec.model_dump_json(), encoding="utf-8")
        tmp.replace(path)

    def _container(self, uuid: str) -> Any | None:
        import docker.errors  # type: ignore[import-not-found]

        try:
            return self.client.containers.get(CONTAINER_PREFIX + uuid)
        except docker.errors.NotFound:
            return None

    def _network(self) -> str:
        """Własna sieć aplikacji bez komunikacji między kontenerami (ICC) — boty
        klientów nie widzą się nawzajem, tylko świat i swoje porty."""
        import docker.errors  # type: ignore[import-not-found]

        name = self.settings.apps_network
        try:
            self.client.networks.get(name)
        except docker.errors.NotFound:
            self.client.networks.create(
                name, driver="bridge",
                options={"com.docker.network.bridge.enable_icc": "false"},
                labels={LABEL: "network"},
            )
        return name

    def _pull(self, image: str, stage: str, low: int, high: int) -> None:
        """Pobiera obraz, raportując postęp warstw (jak `docker pull`)."""
        progress(stage, low, image)
        layers: dict[str, tuple[int, int]] = {}
        last = 0.0
        if "@" in image:
            repository, tag = image.split("@", 1)
        elif ":" in image.split("/")[-1]:
            repository, _, tag = image.rpartition(":")
        else:
            repository, tag = image, "latest"
        try:
            for event in self.client.api.pull(repository, tag=tag or "latest", stream=True, decode=True):
                if "error" in event:
                    raise AppError(f"Nie udało się pobrać obrazu {image}: {event['error']}")
                detail = event.get("progressDetail") or {}
                if event.get("id") and detail.get("total"):
                    layers[event["id"]] = (detail.get("current", 0), detail["total"])
                if layers and time.time() - last > 1:
                    done = sum(c for c, _ in layers.values())
                    total = sum(t for _, t in layers.values()) or 1
                    progress(stage, low + int((high - low) * done / total), image)
                    last = time.time()
        except AppError:
            raise
        except Exception as exc:
            # Bez sieci obraz może już być na węźle — wtedy instalacja idzie dalej.
            try:
                self.client.images.get(image)
                log.warning("Nie udało się odświeżyć obrazu %s (%s) — używam lokalnego", image, exc)
            except Exception:
                raise AppError(f"Nie udało się pobrać obrazu {image}: {exc}") from exc
        progress(stage, high, image)

    # --- instalacja ----------------------------------------------------------

    def install(self, spec: AppSpec, reinstall: bool = False) -> dict[str, Any]:
        with self._lock:
            if spec.uuid in self._installing:
                raise AppError("Instalacja tej aplikacji już trwa.")
            self._installing.add(spec.uuid)
        try:
            root = self.data_dir(spec.uuid)
            root.mkdir(parents=True, exist_ok=True, mode=0o750)
            self.save_spec(spec)
            self._network()

            existing = self._container(spec.uuid)
            if existing is not None:
                if existing.status == "running":
                    progress("stop", 5)
                    self._stop_container(existing, spec, timeout=STOP_TIMEOUT)
                existing.remove(force=True)

            log_file = self.install_log_file(spec.uuid)
            if spec.install and spec.install.script.strip():
                self._run_installer(spec, log_file)
            else:
                log_file.write_text("Egg nie ma skryptu instalacyjnego.\n", encoding="utf-8")

            self._pull(spec.image, "image", 70, 95)
            self._create(spec)
            progress("done", 100)
            return {"uuid": spec.uuid, "state": "offline", "installed": True, "reinstall": reinstall}
        finally:
            with self._lock:
                self._installing.discard(spec.uuid)

    def _run_installer(self, spec: AppSpec, log_file: Path) -> None:
        assert spec.install is not None
        self._pull(spec.install.image, "installer", 5, 25)

        script_dir = self._meta_dir() / f"{spec.uuid}.installer"
        shutil.rmtree(script_dir, ignore_errors=True)
        script_dir.mkdir(mode=0o755)
        script = spec.install.script.replace("\r\n", "\n")
        (script_dir / "install.sh").write_text(script, encoding="utf-8")
        os.chmod(script_dir, 0o755)
        os.chmod(script_dir / "install.sh", 0o755)

        # Skrypt działa jako root (instaluje pakiety), więc na koniec oddajemy
        # pliki użytkownikowi, jako który chodzi aplikacja i sam agent.
        command = (
            f"{spec.install.entrypoint} /mnt/install/install.sh; rc=$?; "
            f"chown -R {self.uid}:{self.gid} /mnt/server; exit $rc"
        )
        progress("script", 30, "skrypt instalacyjny")
        container = self.client.containers.run(
            spec.install.image,
            entrypoint=["/bin/sh", "-c"],
            command=[command],
            name=f"{CONTAINER_PREFIX}{spec.uuid}-install",
            environment=self._environment(spec),
            mounts=self._mounts([
                (str(self.data_dir(spec.uuid)), "/mnt/server", False),
                (str(script_dir), "/mnt/install", True),
            ]),
            network=self.settings.apps_network,
            mem_limit=f"{max(spec.memory_mb, 1024)}m",
            pids_limit=4096,
            labels={LABEL: spec.uuid, "virthub.role": "installer"},
            detach=True,
            auto_remove=False,
            working_dir="/mnt/server",
        )
        deadline = time.time() + INSTALL_TIMEOUT
        with log_file.open("wb") as out:
            try:
                for chunk in container.logs(stream=True, follow=True):
                    out.write(chunk)
                    out.flush()
                    if time.time() > deadline:
                        container.kill()
                        raise AppError("Instalacja trwała dłużej niż 45 minut i została przerwana.")
                result = container.wait(timeout=60)
            finally:
                try:
                    container.remove(force=True)
                except Exception:
                    pass
                shutil.rmtree(script_dir, ignore_errors=True)

        code = int(result.get("StatusCode", 1))
        if code != 0:
            tail = log_file.read_bytes()[-800:].decode("utf-8", errors="replace").strip()
            raise AppError(f"Skrypt instalacyjny zakończył się kodem {code}. Ostatnie linie:\n{tail}")
        progress("script", 65, "skrypt zakończony")

    @staticmethod
    def _mounts(entries: list[tuple[str, str, bool]]) -> list[Any]:
        from docker.types import Mount  # type: ignore[import-not-found]

        return [Mount(target=t, source=s, type="bind", read_only=ro) for s, t, ro in entries]

    def _environment(self, spec: AppSpec) -> dict[str, str]:
        primary = spec.allocations[0] if spec.allocations else None
        env = {
            "TZ": "UTC",
            "STARTUP": spec.startup,
            "SERVER_MEMORY": str(spec.memory_mb),
            "SERVER_IP": "0.0.0.0",
            "SERVER_PORT": str(primary.port) if primary else "",
            "P_SERVER_UUID": spec.uuid,
            "P_SERVER_ALLOCATION_LIMIT": str(len(spec.allocations)),
        }
        env.update(spec.environment)
        return env

    def _create(self, spec: AppSpec) -> Any:
        ports: dict[str, Any] = {}
        for alloc in spec.allocations:
            for proto in ("tcp", "udp"):
                ports[f"{alloc.port}/{proto}"] = (alloc.ip, alloc.port)

        kwargs: dict[str, Any] = dict(
            name=CONTAINER_PREFIX + spec.uuid,
            environment=self._environment(spec),
            user=f"{self.uid}:{self.gid}",
            working_dir=DATA_MOUNT,
            mounts=self._mounts([(str(self.data_dir(spec.uuid)), DATA_MOUNT, False)]),
            tmpfs={"/tmp": "rw,exec,nosuid,size=100m"},
            ports=ports,
            network=self.settings.apps_network,
            mem_limit=f"{spec.memory_mb}m",
            memswap_limit=f"{spec.memory_mb + spec.swap_mb}m",
            pids_limit=spec.pids_limit,
            cap_drop=CAP_DROP,
            security_opt=["no-new-privileges"],
            stdin_open=True,
            tty=True,
            labels={LABEL: spec.uuid, SPEC_LABEL: spec_hash(spec)},
            log_config={"type": "json-file", "config": {"max-size": "5m", "max-file": "2"}},
            restart_policy={"Name": "no"},
            detach=True,
        )
        if spec.cpu_percent:
            kwargs["nano_cpus"] = spec.cpu_percent * 10_000_000
        return self.client.containers.create(spec.image, **kwargs)

    # --- zmiana ustawień ------------------------------------------------------

    def update(self, spec: AppSpec) -> dict[str, Any]:
        """Nowe ustawienia (zasoby, obraz, zmienne). Działający kontener dostaje
        limity od razu; resztę zobaczy przy następnym starcie."""
        old = self.load_spec(spec.uuid) if self._spec_file(spec.uuid).exists() else None
        self.save_spec(spec)
        container = self._container(spec.uuid)
        if container is not None and container.status == "running" and old is not None:
            try:
                container.update(
                    mem_limit=f"{spec.memory_mb}m",
                    memswap_limit=f"{spec.memory_mb + spec.swap_mb}m",
                    nano_cpus=spec.cpu_percent * 10_000_000 if spec.cpu_percent else 0,
                    pids_limit=spec.pids_limit,
                )
            except Exception as exc:
                log.warning("Nie udało się zmienić limitów działającej aplikacji %s: %s", spec.uuid, exc)
        return {"uuid": spec.uuid, "restart_required": container is not None and spec_hash(spec) != container.labels.get(SPEC_LABEL)}

    # --- zasilanie ------------------------------------------------------------

    def power(self, uuid: str, action: str) -> dict[str, Any]:
        if uuid in self._installing:
            raise AppError("Aplikacja się instaluje — poczekaj na koniec instalacji.")
        spec = self.load_spec(uuid)
        container = self._container(uuid)

        if action in ("stop", "restart", "kill") and container is not None:
            container.reload()
            if container.status == "running":
                if action == "kill":
                    container.kill()
                else:
                    self._stop_container(container, spec, timeout=STOP_TIMEOUT)
        if action in ("start", "restart"):
            self._start(spec, container)
        return self.status(uuid)

    def _start(self, spec: AppSpec, container: Any | None) -> None:
        files = self.files(spec.uuid)
        if spec.disk_mb:
            used = files.usage_bytes()
            self._usage_cache[spec.uuid] = (time.time(), used)
            if used > spec.disk_mb * 1024 * 1024:
                raise AppError(
                    f"Aplikacja zajmuje {used // (1024 * 1024)} MB z limitu {spec.disk_mb} MB — "
                    "usuń zbędne pliki, żeby ją uruchomić."
                )
        for cfg in spec.config_files:
            try:
                apply_config_file(files, cfg)
            except AppError as exc:
                log.warning("Pominięto plik konfiguracyjny %s: %s", cfg.file, exc)

        if container is not None:
            container.reload()
            if container.status == "running":
                return
            if container.labels.get(SPEC_LABEL) != spec_hash(spec):
                container.remove(force=True)
                container = None
        if container is None:
            self._network()
            container = self._create(spec)
        container.start()

    def _stop_container(self, container: Any, spec: AppSpec, timeout: int) -> None:
        stop = (spec.stop or "^C").strip()
        try:
            if stop.startswith("^"):
                name = stop[1:].upper()
                sig = {"C": "SIGINT", "X": "SIGTERM", "": "SIGTERM"}.get(name, name if name.startswith("SIG") else f"SIG{name}")
                container.kill(signal=sig)
            else:
                self._write_stdin(container, stop)
            container.wait(timeout=timeout)
        except Exception:
            # Nie zatrzymała się w czasie (albo polecenie nie dotarło) — jak Wings: kill.
            try:
                container.kill()
            except Exception:
                pass

    # --- konsola --------------------------------------------------------------

    def command(self, uuid: str, command: str) -> None:
        container = self._container(uuid)
        if container is None or container.status != "running":
            raise AppError("Aplikacja nie działa — uruchom ją, żeby wysłać polecenie.")
        self._write_stdin(container, command)

    @staticmethod
    def _write_stdin(container: Any, text: str) -> None:
        sock = container.attach_socket(params={"stdin": 1, "stream": 1})
        raw = getattr(sock, "_sock", sock)
        try:
            raw.sendall((text + "\n").encode("utf-8"))
        finally:
            try:
                raw.close()
            except Exception:
                pass

    def logs(self, uuid: str, since: float | None = None, tail: int = 200) -> dict[str, Any]:
        container = self._container(uuid)
        if container is None or uuid in self._installing:
            path = self.install_log_file(uuid)
            text = path.read_bytes()[-200_000:].decode("utf-8", errors="replace") if path.exists() else ""
            lines = text.splitlines()[-tail:]
            return {"source": "install", "lines": lines, "cursor": None}

        kwargs: dict[str, Any] = {"timestamps": True, "tail": tail}
        if since:
            kwargs["since"] = int(since)
        raw = container.logs(**kwargs).decode("utf-8", errors="replace")
        lines: list[str] = []
        cursor = since
        for line in raw.splitlines():
            stamp, _, text = line.partition(" ")
            ts = _parse_ts(stamp)
            if ts is None:
                lines.append(line)
                continue
            if since and ts <= since:
                continue
            lines.append(text.rstrip("\r"))
            cursor = ts
        return {"source": "console", "lines": lines, "cursor": cursor}

    # --- stan i statystyki ----------------------------------------------------

    def status(self, uuid: str) -> dict[str, Any]:
        if uuid in self._installing:
            return {"uuid": uuid, "state": "installing"}
        container = self._container(uuid)
        if container is None:
            exists = self._spec_file(uuid).exists()
            return {"uuid": uuid, "state": "offline" if exists else "missing"}

        container.reload()
        state = {"running": "running", "restarting": "starting", "paused": "offline"}.get(container.status, "offline")
        result: dict[str, Any] = {
            "uuid": uuid,
            "state": state,
            "exit_code": container.attrs.get("State", {}).get("ExitCode"),
            "oom_killed": bool(container.attrs.get("State", {}).get("OOMKilled")),
            "started_at": container.attrs.get("State", {}).get("StartedAt"),
            "disk_bytes": self._disk_usage(uuid),
        }
        if state == "running":
            result.update(self._stats(container))
        return result

    def _disk_usage(self, uuid: str) -> int | None:
        cached = self._usage_cache.get(uuid)
        if cached and time.time() - cached[0] < 60:
            return cached[1]
        try:
            used = self.files(uuid).usage_bytes()
        except AppError:
            return None
        self._usage_cache[uuid] = (time.time(), used)
        return used

    @staticmethod
    def _stats(container: Any) -> dict[str, Any]:
        try:
            s = container.stats(stream=False)
        except Exception:
            return {}
        cpu = s.get("cpu_stats", {})
        pre = s.get("precpu_stats", {})
        cpu_delta = cpu.get("cpu_usage", {}).get("total_usage", 0) - pre.get("cpu_usage", {}).get("total_usage", 0)
        sys_delta = cpu.get("system_cpu_usage", 0) - pre.get("system_cpu_usage", 0)
        cores = cpu.get("online_cpus") or len(cpu.get("cpu_usage", {}).get("percpu_usage") or []) or 1
        cpu_percent = round(cpu_delta / sys_delta * cores * 100, 1) if sys_delta > 0 and cpu_delta > 0 else 0.0
        mem = s.get("memory_stats", {})
        cache = (mem.get("stats") or {}).get("inactive_file", 0) or (mem.get("stats") or {}).get("cache", 0)
        rx = sum(n.get("rx_bytes", 0) for n in (s.get("networks") or {}).values())
        tx = sum(n.get("tx_bytes", 0) for n in (s.get("networks") or {}).values())
        return {
            "cpu_percent": cpu_percent,
            "memory_bytes": max(0, mem.get("usage", 0) - cache),
            "memory_limit_bytes": mem.get("limit"),
            "rx_bytes": rx,
            "tx_bytes": tx,
        }

    # --- usuwanie -------------------------------------------------------------

    def delete(self, uuid: str) -> dict[str, Any]:
        import docker.errors  # type: ignore[import-not-found]

        for name in (CONTAINER_PREFIX + uuid, f"{CONTAINER_PREFIX}{uuid}-install"):
            try:
                self.client.containers.get(name).remove(force=True)
            except docker.errors.NotFound:
                pass
        shutil.rmtree(self.data_dir(uuid), ignore_errors=True)
        for path in (self._spec_file(uuid), self.install_log_file(uuid)):
            path.unlink(missing_ok=True)
        self._usage_cache.pop(uuid, None)
        return {"uuid": uuid, "deleted": True}

    # --- pliki (API) ----------------------------------------------------------

    def read_file(self, uuid: str, path: str) -> bytes:
        return self.files(uuid).read(path)

    def write_file(self, uuid: str, path: str, content_base64: str) -> dict[str, Any]:
        try:
            data = base64.b64decode(content_base64, validate=True)
        except ValueError:
            raise AppError("Treść pliku nie jest poprawnym base64.") from None
        self._check_quota(uuid, len(data))
        self.files(uuid).write(path, data)
        return {"path": path, "size": len(data)}

    def decompress(self, uuid: str, path: str) -> dict[str, Any]:
        spec = self.load_spec(uuid)
        limit = None
        if spec.disk_mb:
            limit = max(0, spec.disk_mb * 1024 * 1024 - (self._disk_usage(uuid) or 0))
        count = self.files(uuid).extract(path, limit)
        self._usage_cache.pop(uuid, None)
        return {"path": path, "entries": count}

    def _check_quota(self, uuid: str, incoming: int) -> None:
        try:
            spec = self.load_spec(uuid)
        except AppNotFound:
            return
        if spec.disk_mb and (self._disk_usage(uuid) or 0) + incoming > spec.disk_mb * 1024 * 1024:
            raise AppError(f"Brak miejsca: limit dysku aplikacji to {spec.disk_mb} MB.")
        self._usage_cache.pop(uuid, None)


def _parse_ts(stamp: str) -> float | None:
    """Znacznik z `docker logs --timestamps` (RFC 3339 z nanosekundami)."""
    if not stamp.endswith("Z") or "T" not in stamp:
        return None
    main, _, frac = stamp[:-1].partition(".")
    try:
        base = datetime.strptime(main, "%Y-%m-%dT%H:%M:%S").replace(tzinfo=timezone.utc).timestamp()
    except ValueError:
        return None
    return base + (float(f"0.{frac}") if frac.isdigit() else 0.0)
