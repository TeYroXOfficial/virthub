"""Zdalne magazyny: zasoby montowane przez agenta i dyski blokowe StorPool/Lightbits.

Montowane (NFS, SMB/CIFS, CephFS, iSCSI z systemem plików) — agent montuje zasób
pod `<katalog stanu>/mnt/<id>` przy pierwszym użyciu i po starcie węzła, dalej
dyski to zwykłe pliki qcow2 (jak magazyn katalogowy: migawki, Elevate, kopie
przyrostowe). Hasła i klucze trafiają do plików tymczasowych 0600, nigdy do
argumentów polecenia.

Blokowe (StorPool, Lightbits NVMe/TCP) — każdy dysk to wolumin w systemie
zewnętrznym podłączony do węzła jako urządzenie blokowe; QEMU dostaje
<disk type='block'>. Polecenia CLI obu systemów są zgodne z ich dokumentacją,
ale nie były sprawdzane na prawdziwym klastrze — traktuj je jako eksperymentalne.
"""

from __future__ import annotations

import contextlib
import hashlib
import json
import logging
import os
import re
import tempfile
import time
from pathlib import Path
from typing import Any, Callable, Iterator
from xml.sax.saxutils import quoteattr

from .config import Settings
from .schemas import DatastoreSpec
from .shell import CommandError, run
from .storage import StorageDriver, StorageError

log = logging.getLogger("virthub.remote_storage")

GIB = 1024**3


@contextlib.contextmanager
def secret_file(content: str) -> Iterator[str]:
    fd, path = tempfile.mkstemp(prefix="vh-ds-")  # 0600
    try:
        with os.fdopen(fd, "w") as fh:
            fh.write(content)
        yield path
    finally:
        os.unlink(path)


def _missing(tool: str, package: str) -> StorageError:
    return StorageError(f"Na węźle brakuje programu {tool} — zainstaluj: apt install {package} (albo scripts/setup-remote-storage.sh).")


def _host(address: str) -> str:
    """Adres bez portu, IPv6 w nawiasach zostaje w nawiasach."""
    match = re.fullmatch(r"(\[[0-9A-Fa-f:.]+\])(?::\d+)?", address)
    if match:
        return match.group(1)
    return address.split(":")[0] if address.count(":") == 1 else address


class RemoteMount:
    """Montowanie zasobu sieciowego magazynu (idempotentne)."""

    def __init__(self, settings: Settings, spec: DatastoreSpec, runner: Callable[..., str] | None = None,
                 is_mount: Callable[[str], bool] | None = None):
        self.settings = settings
        self.spec = spec
        self.runner = runner or run
        self.is_mount = is_mount or os.path.ismount

    @property
    def mountpoint(self) -> Path:
        return self.settings.state_db.parent / "mnt" / str(self.spec.id)

    def ensure(self) -> Path:
        target = self.mountpoint
        if self.is_mount(str(target)):
            return target
        target.mkdir(parents=True, exist_ok=True)
        try:
            getattr(self, f"_mount_{self.spec.type}")(str(target))
        except CommandError as exc:
            if exc.returncode == 127:
                raise _missing(exc.argv[0], {"iscsiadm": "open-iscsi"}.get(exc.argv[0], "nfs-common cifs-utils ceph-common")) from exc
            raise StorageError(f"Nie udało się zamontować magazynu {self.spec.id} ({self.spec.type}): {exc.stderr.strip()[:300]}") from exc
        log.info("Zamontowano magazyn %s (%s) w %s", self.spec.id, self.spec.type, target)
        return target

    def _options(self, *base: str) -> list[str]:
        opts = [o for o in (*base, *self.spec.options.split(",")) if o]
        return ["-o", ",".join(opts)] if opts else []

    def _mount_nfs(self, target: str) -> None:
        export = "/" + (self.spec.export or "").lstrip("/")
        self.runner(["mount", "-t", "nfs", *self._options("hard", "_netdev"), f"{self.spec.server}:{export}", target], timeout=120)

    def _mount_cifs(self, target: str) -> None:
        share = (self.spec.export or "").strip("/")
        creds = f"username={self.spec.username or 'guest'}\npassword={self.spec.password or ''}\n"
        with secret_file(creds) as path:
            # Pliki dysków należą do QEMU — właściciel z mount.cifs, nie z serwera.
            self.runner(["mount", "-t", "cifs", *self._options(f"credentials={path}", "vers=3.0", "_netdev"),
                         f"//{_host(self.spec.server or '')}/{share}", target], timeout=120)

    def _mount_cephfs(self, target: str) -> None:
        from .datastores import parse_monitor

        mons = ",".join(f"{h if ':' not in h else '[' + h + ']'}:{p}" for h, p in map(parse_monitor, self.spec.monitors))
        path = "/" + (self.spec.export or "").lstrip("/")
        with secret_file(self.spec.key or "") as keyfile:
            self.runner(["mount", "-t", "ceph", *self._options(f"name={self.spec.user}", f"secretfile={keyfile}", "_netdev"),
                         f"{mons}:{path}", target], timeout=120)

    def iscsi_device(self) -> Path:
        portal = self.spec.portal or ""
        if ":" not in portal.rsplit("]", 1)[-1]:
            portal += ":3260"
        return Path(f"/dev/disk/by-path/ip-{portal}-iscsi-{self.spec.iqn}-lun-{self.spec.lun}")

    def _mount_iscsi(self, target: str) -> None:
        portal, iqn = self.spec.portal or "", self.spec.iqn or ""
        device = self.iscsi_device()
        if not device.exists():
            self.runner(["iscsiadm", "-m", "discovery", "-t", "sendtargets", "-p", portal], timeout=60)
            node = ["iscsiadm", "-m", "node", "-T", iqn, "-p", portal]
            if self.spec.username:
                for key, value in (("node.session.auth.authmethod", "CHAP"), ("node.session.auth.username", self.spec.username),
                                   ("node.session.auth.password", self.spec.password or "")):
                    self.runner([*node, "-o", "update", "-n", key, "-v", value], timeout=30)
            self.runner([*node, "-o", "update", "-n", "node.startup", "-v", "automatic"], timeout=30)
            with contextlib.suppress(CommandError):  # już zalogowany
                self.runner([*node, "--login"], timeout=120)
            for _ in range(20):
                if device.exists():
                    break
                time.sleep(0.5)
        # LUN musi mieć system plików — klastrowy (OCFS2/GFS2), jeśli korzysta z niego kilka węzłów.
        self.runner(["mount", *self._options("_netdev"), str(device), target], timeout=120)


# --- dyski blokowe ------------------------------------------------------------------


class BlockVolumeDriver(StorageDriver):
    """Wspólna część magazynów, w których dysk to urządzenie blokowe na węźle."""

    file_based = False

    def __init__(self, settings: Settings, spec: DatastoreSpec, runner: Callable[..., str] | None = None):
        self.settings = settings
        self.spec = spec
        self.runner = runner or run

    # Do zaimplementowania przez konkretny system.
    def _create(self, name: str, disk_gb: int) -> None: raise NotImplementedError
    def _exists(self, name: str) -> bool: raise NotImplementedError
    def device(self, name: str) -> Path: raise NotImplementedError
    def _detach(self, name: str) -> None: raise NotImplementedError
    def _delete(self, name: str) -> None: raise NotImplementedError
    def _grow(self, name: str, disk_gb: int) -> None: raise NotImplementedError

    def _create_from_template(self, name: str, template: Path, disk_gb: int) -> None:
        """Domyślnie pełna kopia szablonu na nowy wolumin."""
        self._create(name, disk_gb)
        self.runner(["qemu-img", "convert", "-n", "-O", "raw", str(template), str(self.device(name))], timeout=7200)

    def _template(self, template: str) -> Path:
        source = self.settings.template_dir / Path(template).name
        if not source.exists():
            raise StorageError(f"Nie znaleziono szablonu {source.name} w {self.settings.template_dir}.")
        return source

    def volume_path(self, name: str) -> Path:
        raise StorageError("Dysk w magazynie blokowym nie jest plikiem na węźle — ta operacja wymaga magazynu plikowego.")

    def overlay_path(self, name: str) -> Path:
        raise StorageError("Elevate działa tylko dla dysków w magazynie plikowym (qcow2).")

    def has_overlay(self, name: str) -> bool:
        return False

    def drop_overlay(self, name: str) -> None:
        return None

    def create_volume(self, name: str, template: str, disk_gb: int) -> Path:
        if self._exists(name):
            raise StorageError(f"Wolumin {name} już istnieje — odmawiam nadpisania.")
        source = self._template(template)
        info = json.loads(self.runner(["qemu-img", "info", "--output=json", str(source)], timeout=60) or "{}")
        base_gb = -(-int(info.get("virtual-size", 0)) // GIB)
        if disk_gb < base_gb:
            raise StorageError(f"Szablon ma {base_gb} GB, a dysk maszyny {disk_gb} GB.")
        self._create_from_template(name, source, disk_gb)
        return self.device(name)

    def create_blank(self, name: str, disk_gb: int) -> Path:
        if self._exists(name):
            raise StorageError(f"Wolumin {name} już istnieje — odmawiam nadpisania.")
        self._create(name, disk_gb)
        return self.device(name)

    def size_gb(self, name: str) -> int:
        return int(self.runner(["blockdev", "--getsize64", str(self.device(name))], timeout=30).strip() or 0) // GIB

    def resize_volume(self, name: str, disk_gb: int) -> None:
        current = self.size_gb(name)
        if disk_gb < current:
            raise StorageError(f"Zmniejszenie dysku z {current}GB do {disk_gb}GB nie jest wspierane.")
        if disk_gb > current:
            self._grow(name, disk_gb)

    def delete_volume(self, name: str) -> None:
        if not self._exists(name):
            return
        with contextlib.suppress(CommandError):
            self._detach(name)
        self._delete(name)
        log.info("Usunięto wolumin %s (%s)", name, self.spec.type)

    def export_image(self, name: str, target: Path, compress: bool = True, after_snapshot: Any = None) -> None:
        # Bez migawki po stronie systemu kopia trwa przy zamrożonych systemach plików gościa.
        try:
            self.runner(["qemu-img", "convert", "-f", "raw", "-O", "qcow2", *(["-c"] if compress else []),
                         str(self.device(name)), str(target)], timeout=6 * 3600)
        finally:
            if after_snapshot is not None:
                after_snapshot()

    def import_image(self, name: str, source: Path) -> None:
        self.runner(["qemu-img", "convert", "-n", "-O", "raw", str(source), str(self.device(name))], timeout=6 * 3600)

    def libvirt_prepare(self, conn: Any) -> None:
        return None

    def disk_xml(self, name: str, dev: str = "vda") -> str:
        return (
            "    <disk type='block' device='disk'>\n"
            "      <driver name='qemu' type='raw' cache='none' io='native' discard='unmap'/>\n"
            f"      <source dev={quoteattr(str(self.device(name)))}/>\n"
            f"      <target dev={quoteattr(dev)} bus='virtio'/>\n"
            "    </disk>"
        )

    def snapshot_volume(self, name: str, snapshot: str) -> Path:
        raise StorageError(f"Migawki nie są obsługiwane w magazynie {self.spec.type}.")

    def restore_volume(self, name: str, snapshot: str) -> None:
        raise StorageError(f"Migawki nie są obsługiwane w magazynie {self.spec.type}.")


def _volume_name(name: str) -> str:
    # StorPool i Lightbits: litery, cyfry, „-”, „_”, „.”.
    return re.sub(r"[^A-Za-z0-9_.-]", "-", name)[:64]


class StorPoolDriver(BlockVolumeDriver):
    """StorPool: woluminy z szablonu StorPool, podpięte do węzła (`attach … here`).

    Szablon systemu wgrywany raz jako wolumin, z którego robiona jest migawka
    bazowa; dyski maszyn to klony tej migawki (`parent`), więc tworzenie jest
    natychmiastowe i cienkie."""

    def _sp(self, *args: str, timeout: int = 300) -> str:
        try:
            return self.runner(["storpool", *args], timeout=timeout)
        except CommandError as exc:
            if exc.returncode == 127:
                raise StorageError("Na węźle brakuje klienta StorPool (storpool) — węzeł musi być klientem klastra StorPool.") from exc
            raise

    def device(self, name: str) -> Path:
        dev = Path("/dev/storpool") / _volume_name(name)
        if not dev.exists():
            self._sp("attach", "volume", _volume_name(name), "here", "timeout", "30")
        return dev

    def _exists(self, name: str) -> bool:
        try:
            self._sp("volume", _volume_name(name), "info", timeout=60)
            return True
        except CommandError as exc:
            if "does not exist" in exc.stderr:
                return False
            raise

    def _create(self, name: str, disk_gb: int) -> None:
        self._sp("volume", _volume_name(name), "create", "size", f"{disk_gb}G", "template", self.spec.template or "")

    def _snapshot_name(self, name: str, snapshot: str) -> str:
        return f"{_volume_name(name)[:40]}-{_volume_name(snapshot)[:22]}"

    def _base_snapshot(self, template: Path) -> str:
        digest = hashlib.sha1(template.name.encode()).hexdigest()[:8]
        snap = f"vh-tpl-{_volume_name(template.stem)[:40]}-{digest}"
        try:
            self._sp("snapshot", snap, "info", timeout=60)
            return snap
        except CommandError:
            pass
        info = json.loads(self.runner(["qemu-img", "info", "--output=json", str(template)], timeout=60) or "{}")
        size_gb = max(1, -(-int(info.get("virtual-size", 0)) // GIB))
        tmp = f"{snap}-import"
        with contextlib.suppress(CommandError):
            self._sp("detach", "volume", tmp, "all")
            self._sp("volume", tmp, "delete", tmp)
        self._create(tmp, size_gb)
        try:
            self.runner(["qemu-img", "convert", "-n", "-O", "raw", str(template), str(self.device(tmp))], timeout=7200)
            self._sp("detach", "volume", tmp, "here")
            self._sp("volume", tmp, "snapshot", snap)
        finally:
            with contextlib.suppress(CommandError):
                self._sp("volume", tmp, "delete", tmp)
        log.info("Zaimportowano szablon %s do StorPool jako %s", template.name, snap)
        return snap

    def _create_from_template(self, name: str, template: Path, disk_gb: int) -> None:
        snap = self._base_snapshot(template)
        self._sp("volume", _volume_name(name), "create", "size", f"{disk_gb}G", "template", self.spec.template or "", "parent", snap)

    def _detach(self, name: str) -> None:
        self._sp("detach", "volume", _volume_name(name), "all")

    def _delete(self, name: str) -> None:
        vol = _volume_name(name)
        self._sp("volume", vol, "delete", vol)

    def _grow(self, name: str, disk_gb: int) -> None:
        self._sp("volume", _volume_name(name), "update", "size", f"{disk_gb}G")

    def snapshot_volume(self, name: str, snapshot: str) -> Path:
        self._sp("volume", _volume_name(name), "snapshot", self._snapshot_name(name, snapshot))
        return Path("/dev/storpool") / self._snapshot_name(name, snapshot)

    def restore_volume(self, name: str, snapshot: str) -> None:
        self._sp("volume", _volume_name(name), "revertToSnapshot", self._snapshot_name(name, snapshot), timeout=3600)

    def capacity(self) -> tuple[int, int]:
        data = json.loads(self._sp("-j", "template", "status", timeout=60) or "{}")
        rows = data.get("data", data) if isinstance(data, dict) else data
        for row in rows if isinstance(rows, list) else []:
            if row.get("name") == self.spec.template:
                stored = row.get("stored", {}) if isinstance(row.get("stored"), dict) else {}
                free = int(row.get("availablePlaceAll", row.get("free", stored.get("free", 0))) or 0)
                total = int(row.get("capacityPlaceAll", row.get("capacity", stored.get("capacity", 0))) or 0)
                return total // GIB, free // GIB
        raise StorageError(f"Klaster StorPool nie ma szablonu {self.spec.template}.")


class LightbitsDriver(BlockVolumeDriver):
    """Lightbits: woluminy tworzone przez `lbcli`, podłączone przez NVMe/TCP.

    Węzeł łączy się z podsystemem (NQN) przez `nvme connect` na wszystkich
    adresach danych; wolumin ma ACL równą NQN hosta, więc widzi go tylko ten
    węzeł. Urządzenie znajdujemy po UUID woluminu w /dev/disk/by-id."""

    def _lb(self, *args: str, timeout: int = 300) -> str:
        env = {**os.environ, "LIGHTOS_JWT": self.spec.jwt or ""}
        try:
            return self.runner(["lbcli", "--endpoint", self.spec.endpoint or "", *args, "-o", "json"], timeout=timeout, env=env)
        except CommandError as exc:
            if exc.returncode == 127:
                raise StorageError("Na węźle brakuje lbcli (klient Lightbits) — zainstaluj go z repozytorium Lightbits.") from exc
            raise

    def _hostnqn(self) -> str:
        try:
            return Path("/etc/nvme/hostnqn").read_text().strip()
        except FileNotFoundError as exc:
            raise StorageError("Brak /etc/nvme/hostnqn — zainstaluj nvme-cli (apt install nvme-cli).") from exc

    def _get(self, name: str) -> dict[str, Any] | None:
        try:
            return json.loads(self._lb("get", "volume", f"--name={_volume_name(name)}", f"--project-name={self.spec.project}", timeout=60) or "{}")
        except CommandError as exc:
            if "not found" in exc.stderr.lower() or exc.returncode == 5:
                return None
            raise

    def _exists(self, name: str) -> bool:
        return self._get(name) is not None

    def _connect(self) -> None:
        for target in self.spec.targets:
            host, _, port = target.rpartition(":") if target.count(":") == 1 or target.startswith("[") else (target, "", "")
            with contextlib.suppress(CommandError):  # już połączony z tym adresem
                self.runner(["nvme", "connect", "-t", "tcp", "-a", (host or target).strip("[]"), "-s", port or "4420",
                             "-n", self.spec.nqn or "", "-q", self._hostnqn(), "--ctrl-loss-tmo", "-1"], timeout=60)

    def device(self, name: str) -> Path:
        info = self._get(name)
        if info is None:
            raise StorageError(f"Brak woluminu {_volume_name(name)} w Lightbits.")
        dev = Path(f"/dev/disk/by-id/nvme-uuid.{str(info.get('UUID') or info.get('uuid', '')).lower()}")
        if not dev.exists():
            self._connect()
            for _ in range(20):
                if dev.exists():
                    break
                time.sleep(0.5)
        return dev

    def _create(self, name: str, disk_gb: int) -> None:
        self._lb("create", "volume", f"--name={_volume_name(name)}", f"--size={disk_gb} GiB", f"--acl={self._hostnqn()}",
                 f"--replica-count={self.spec.replicas}", f"--project-name={self.spec.project}")

    def _detach(self, name: str) -> None:
        return None  # urządzenie znika razem z woluminem

    def _delete(self, name: str) -> None:
        self._lb("delete", "volume", f"--name={_volume_name(name)}", f"--project-name={self.spec.project}")

    def _grow(self, name: str, disk_gb: int) -> None:
        self._lb("update", "volume", f"--name={_volume_name(name)}", f"--size={disk_gb} GiB", f"--project-name={self.spec.project}")

    def capacity(self) -> tuple[int, int]:
        data = json.loads(self._lb("get", "cluster", timeout=60) or "{}")
        stats = data.get("statistics", data)
        total = int(stats.get("effectivePhysicalStorage", stats.get("installedPhysicalStorage", 0)) or 0)
        free = int(stats.get("freePhysicalStorage", max(0, total - int(stats.get("physicalUsedStorage", 0) or 0))) or 0)
        if total <= 0:
            raise StorageError("Lightbits nie zwrócił pojemności klastra (lbcli get cluster).")
        return total // GIB, free // GIB
