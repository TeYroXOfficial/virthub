"""Magazyny danych (datastore'y): gdzie leży dysk każdej maszyny.

Panel przypina magazyn do maszyny przy tworzeniu (pole `storage` zlecenia).
Agent zapamiętuje to przypisanie w pliku obok swojej bazy zadań, więc kolejne
operacje (reinstalacja, zmiana rozmiaru, migawki, usunięcie) trafiają do tego
samego magazynu bez ponownego przesyłania konfiguracji.

Rodzaje:

* katalog — pliki qcow2 w podanym katalogu (np. drugi dysk, NFS, iSCSI z
  systemem plików), cienkie (backing file na szablonie) albo grube (pełna kopia
  z rezerwacją miejsca);
* zasoby montowane (NFS, SMB/CIFS, CephFS, iSCSI) i dyski blokowe StorPool /
  Lightbits — zob. remote_storage.py;
* Ceph RBD — szablon importowany raz do puli jako obraz ze snapshotem `base`,
  dysk maszyny to jego klon (cienki z natury). QEMU łączy się z klastrem sam,
  klucz trafia do sekretu libvirt; narzędzia `rbd`/`ceph` dostają go z pliku
  tymczasowego, nigdy w argumentach polecenia.
"""

from __future__ import annotations

import base64
import contextlib
import fcntl
import hashlib
import json
import logging
import os
import re
import tempfile
import uuid as uuidlib
from pathlib import Path
from typing import Any, Callable, Iterator
from xml.sax.saxutils import escape, quoteattr

from .config import Settings
from .schemas import DatastoreSpec
from .shell import CommandError, run
from .remote_storage import BlockVolumeDriver, LightbitsDriver, RemoteMount, StorPoolDriver
from .storage import MockStorageDriver, Qcow2LocalDriver, StorageDriver, StorageError, build_default_driver

log = logging.getLogger("virthub.datastores")

GIB = 1024**3


def parse_monitor(monitor: str) -> tuple[str, str]:
    """„10.0.0.1”, „ceph1:6789”, „[2001:db8::1]:3300” → (host, port)."""
    match = re.fullmatch(r"\[([0-9A-Fa-f:.]+)\](?::(\d+))?", monitor)
    if match:
        return match.group(1), match.group(2) or "6789"
    if monitor.count(":") == 1:
        host, port = monitor.split(":")
        return host, port
    return monitor, "6789"


class CephRbdDriver(StorageDriver):
    file_based = False

    def __init__(self, settings: Settings, spec: DatastoreSpec, runner: Callable[..., str] = run):
        self.settings = settings
        self.spec = spec
        self.pool = spec.pool or ""
        self.runner = runner

    # --- narzędzia ------------------------------------------------------------

    @contextlib.contextmanager
    def _keyfile(self) -> Iterator[str]:
        fd, path = tempfile.mkstemp(prefix="vh-ceph-")  # 0600
        try:
            with os.fdopen(fd, "w") as fh:
                fh.write(self.spec.key or "")
            yield path
        finally:
            os.unlink(path)

    def _cli(self, tool: str, *args: str, timeout: int = 600) -> str:
        with self._keyfile() as keyfile:
            try:
                return self.runner([tool, "--id", self.spec.user, "-m", ",".join(self.spec.monitors),
                                    "--keyfile", keyfile, *args], timeout=timeout)
            except CommandError as exc:
                if exc.returncode == 127:
                    raise StorageError(
                        f"Na węźle brakuje programu {tool} — zainstaluj: apt install ceph-common qemu-block-extra"
                    ) from exc
                raise

    def _rbd(self, *args: str, timeout: int = 600) -> str:
        return self._cli("rbd", *args, timeout=timeout)

    def _image(self, name: str) -> str:
        return f"{self.pool}/{name}"

    def _info(self, image: str) -> dict[str, Any] | None:
        try:
            return json.loads(self._rbd("info", "--format", "json", image, timeout=60))
        except CommandError as exc:
            if exc.returncode == 2 or "No such file" in exc.stderr or "error opening" in exc.stderr:
                return None
            raise

    @staticmethod
    def template_image(template: str) -> str:
        stem = re.sub(r"[^A-Za-z0-9_-]", "-", Path(template).stem)[:48]
        return f"vh-tpl-{stem}-{hashlib.sha1(template.encode()).hexdigest()[:8]}"

    @property
    def secret_uuid(self) -> str:
        return str(uuidlib.uuid5(uuidlib.NAMESPACE_URL, f"virthub:ceph:{self.spec.id}:{self.spec.user}"))

    def _ensure_template(self, template: str) -> str:
        """Obraz szablonu w puli ze snapshotem `base` (import przy pierwszym użyciu)."""
        image = self._image(self.template_image(template))
        if self._info(f"{image}@base") is not None:
            return f"{image}@base"
        source = self.settings.template_dir / Path(template).name
        if not source.exists():
            raise StorageError(f"Nie znaleziono szablonu {source.name} w {self.settings.template_dir}.")
        lock_path = self.settings.state_db.parent / f".{self.template_image(template)}.lock"
        with open(lock_path, "w") as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            if self._info(f"{image}@base") is not None:
                return f"{image}@base"
            if self._info(image) is not None:  # przerwany wcześniej import
                self._rbd("rm", "--no-progress", image)
            raw = self.settings.image_dir / f".{self.template_image(template)}.raw"
            try:
                self.runner(["qemu-img", "convert", "-O", "raw", str(source), str(raw)], timeout=7200)
                self._rbd("import", "--no-progress", "--image-format", "2", str(raw), image, timeout=7200)
                self._rbd("snap", "create", f"{image}@base")
                self._rbd("snap", "protect", f"{image}@base")
            finally:
                raw.unlink(missing_ok=True)
        log.info("Zaimportowano szablon %s do Cepha jako %s", source.name, image)
        return f"{image}@base"

    # --- kontrakt StorageDriver ------------------------------------------------

    def volume_path(self, name: str) -> Path:
        raise StorageError("Dysk w Cephie nie jest plikiem na węźle — ta operacja wymaga magazynu katalogowego.")

    def overlay_path(self, name: str) -> Path:
        raise StorageError("Elevate działa tylko dla dysków w magazynie katalogowym (qcow2).")

    def has_overlay(self, name: str) -> bool:
        return False

    def drop_overlay(self, name: str) -> None:
        return None

    def create_volume(self, name: str, template: str, disk_gb: int) -> Path:
        image = self._image(name)
        if self._info(image) is not None:
            raise StorageError(f"Obraz {image} już istnieje — odmawiam nadpisania.")
        base = self._ensure_template(template)
        base_gb = -(-int((self._info(base) or {}).get("size", 0)) // GIB)
        if disk_gb < base_gb:
            raise StorageError(f"Szablon ma {base_gb} GB, a dysk maszyny {disk_gb} GB.")
        self._rbd("clone", base, image)
        if disk_gb > base_gb:
            self._rbd("resize", "--no-progress", "--size", f"{disk_gb}G", image)
        log.info("Utworzono dysk %s (%sGB, klon %s)", image, disk_gb, base)
        return Path(f"rbd:{image}")

    def export_image(self, name: str, target: Path, compress: bool = True, after_snapshot: Any = None) -> None:
        image = self._image(name)
        snap = f"{image}@vh-backup"
        raw = target.with_name(target.name + ".raw")
        with contextlib.suppress(CommandError):
            self._rbd("snap", "rm", "--no-progress", snap)
        self._rbd("snap", "create", snap)
        try:
            if after_snapshot is not None:
                after_snapshot()
            self._rbd("export", "--no-progress", snap, str(raw), timeout=6 * 3600)
            self.runner(["qemu-img", "convert", "-f", "raw", "-O", "qcow2", *(["-c"] if compress else []), str(raw), str(target)], timeout=6 * 3600)
        finally:
            raw.unlink(missing_ok=True)
            with contextlib.suppress(CommandError):
                self._rbd("snap", "rm", "--no-progress", snap)

    def import_image(self, name: str, source: Path) -> None:
        """Nowy obraz obok starego, zamiana nazw i usunięcie starego — błąd w trakcie nie niszczy dysku."""
        image = self._image(name)
        fresh, old = f"{image}.vhrestore", f"{image}.vhold"
        raw = source.with_name(source.name + ".raw")
        try:
            self.runner(["qemu-img", "convert", "-O", "raw", str(source), str(raw)], timeout=6 * 3600)
            with contextlib.suppress(CommandError):
                self._rbd("rm", "--no-progress", fresh)
            self._rbd("import", "--no-progress", str(raw), fresh, timeout=6 * 3600)
        finally:
            raw.unlink(missing_ok=True)
        self._rbd("mv", image, old)
        self._rbd("mv", fresh, image)
        with contextlib.suppress(CommandError):
            self._rbd("snap", "purge", "--no-progress", old)
            self._rbd("rm", "--no-progress", old, timeout=3600)

    def create_blank(self, name: str, disk_gb: int) -> Path:
        image = self._image(name)
        if self._info(image) is not None:
            raise StorageError(f"Obraz {image} już istnieje — odmawiam nadpisania.")
        self._rbd("create", "--size", f"{disk_gb}G", image)
        return Path(f"rbd:{image}")

    def size_gb(self, name: str) -> int:
        info = self._info(self._image(name))
        if info is None:
            raise StorageError(f"Brak obrazu {self._image(name)}.")
        return int(info["size"]) // GIB

    def resize_volume(self, name: str, disk_gb: int) -> None:
        current = self.size_gb(name)
        if disk_gb < current:
            raise StorageError(f"Zmniejszenie dysku z {current}GB do {disk_gb}GB nie jest wspierane.")
        if disk_gb > current:
            self._rbd("resize", "--no-progress", "--size", f"{disk_gb}G", self._image(name))

    def delete_volume(self, name: str) -> None:
        image = self._image(name)
        if self._info(image) is None:
            return
        with contextlib.suppress(CommandError):
            self._rbd("snap", "purge", "--no-progress", image)
        self._rbd("rm", "--no-progress", image, timeout=3600)
        log.info("Usunięto obraz %s", image)

    def snapshot_volume(self, name: str, snapshot: str) -> Path:
        self._rbd("snap", "create", f"{self._image(name)}@{snapshot}")
        return Path(f"rbd:{self._image(name)}@{snapshot}")

    def restore_volume(self, name: str, snapshot: str) -> None:
        if self._info(f"{self._image(name)}@{snapshot}") is None:
            raise StorageError(f"Nie znaleziono migawki {snapshot} dla {name}.")
        self._rbd("snap", "rollback", "--no-progress", f"{self._image(name)}@{snapshot}", timeout=7200)

    def capacity(self) -> tuple[int, int]:
        data = json.loads(self._cli("ceph", "df", "--format", "json", timeout=60))
        for pool in data.get("pools", []):
            if pool.get("name") == self.pool:
                stats = pool.get("stats", {})
                free = int(stats.get("max_avail", 0))
                used = int(stats.get("stored", stats.get("bytes_used", 0)))
                return (used + free) // GIB, free // GIB
        raise StorageError(f"Klaster Ceph nie ma puli {self.pool}.")

    def libvirt_prepare(self, conn: Any) -> None:
        import libvirt

        try:
            secret = conn.secretLookupByUUIDString(self.secret_uuid)
        except libvirt.libvirtError:
            xml = (f"<secret ephemeral='no' private='yes'><uuid>{self.secret_uuid}</uuid>"
                   f"<usage type='ceph'><name>{escape(f'client.{self.spec.user} virthub-{self.spec.id}')}</name></usage></secret>")
            secret = conn.secretDefineXML(xml)
        secret.setValue(base64.b64decode(self.spec.key or ""))

    def disk_xml(self, name: str, dev: str = "vda") -> str:
        hosts = "".join(
            f"\n        <host name={quoteattr(host)} port={quoteattr(port)}/>"
            for host, port in map(parse_monitor, self.spec.monitors)
        )
        return (
            "    <disk type='network' device='disk'>\n"
            "      <driver name='qemu' type='raw' cache='writeback' discard='unmap'/>\n"
            f"      <auth username={quoteattr(self.spec.user)}>\n"
            f"        <secret type='ceph' uuid='{self.secret_uuid}'/>\n"
            "      </auth>\n"
            f"      <source protocol='rbd' name={quoteattr(self._image(name))}>{hosts}\n"
            "      </source>\n"
            f"      <target dev={quoteattr(dev)} bus='virtio'/>\n"
            "    </disk>"
        )


class StorageRouter:
    """Wybiera magazyn dla dysku danej maszyny; bez przypisania — katalog domyślny."""

    def __init__(self, settings: Settings):
        self.settings = settings
        self.default = build_default_driver(settings)
        self.registry_path = settings.state_db.parent / "datastores.json"

    # --- przypisania ------------------------------------------------------------

    def _load(self) -> dict[str, dict[str, Any]]:
        try:
            return json.loads(self.registry_path.read_text(encoding="utf-8"))
        except (FileNotFoundError, json.JSONDecodeError):
            return {}

    def _save(self, data: dict[str, dict[str, Any]]) -> None:
        self.registry_path.parent.mkdir(parents=True, exist_ok=True)
        tmp = self.registry_path.with_suffix(".tmp")
        fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w", encoding="utf-8") as fh:
            json.dump(data, fh)
        os.replace(tmp, self.registry_path)

    def bind(self, name: str, spec: DatastoreSpec | None) -> None:
        data = self._load()
        if spec is None:
            data.pop(name, None)
        else:
            data[name] = spec.model_dump()
        self._save(data)

    def unbind(self, name: str) -> None:
        self.bind(name, None)

    def spec_for(self, name: str) -> DatastoreSpec | None:
        data = self._load()
        # Dodatkowe dyski (virthub-<id>-d<n>) leżą w magazynie dysku głównego.
        raw = data.get(name) or data.get(re.sub(r"-d[1-7]$", "", name))
        return DatastoreSpec.model_validate(raw) if raw else None

    def driver_for_spec(self, spec: DatastoreSpec | None) -> StorageDriver:
        if spec is None:
            return self.default
        if self.settings.is_mock:
            return MockStorageDriver(self.settings, root=self.settings.image_dir / f"datastore-{spec.id}", thin=spec.thin)
        if spec.type == "directory":
            return Qcow2LocalDriver(self.settings, root=Path(spec.path or ""), thin=spec.thin)
        if spec.mounted:
            # Zasób sieciowy montowany przez agenta; na nim zwykłe pliki qcow2.
            return Qcow2LocalDriver(self.settings, root=RemoteMount(self.settings, spec).ensure(), thin=spec.thin)
        if spec.type == "storpool":
            return StorPoolDriver(self.settings, spec)
        if spec.type == "lightbits":
            return LightbitsDriver(self.settings, spec)
        return CephRbdDriver(self.settings, spec)

    def restore_mounts(self) -> int:
        """Po starcie węzła: montuje zasoby i podłącza woluminy przypisanych dysków,
        zanim libvirt uruchomi maszyny (autostart)."""
        done = 0
        for name, raw in self._load().items():
            try:
                spec = DatastoreSpec.model_validate(raw)
                if spec.type in ("directory", "ceph"):
                    continue
                driver = self.driver_for_spec(spec)
                if isinstance(driver, BlockVolumeDriver):
                    driver.device(name)
                done += 1
            except Exception:  # noqa: BLE001 — jeden niedostępny magazyn nie blokuje reszty
                log.exception("Nie udało się przygotować magazynu dysku %s", name)
        return done

    def for_volume(self, name: str) -> StorageDriver:
        return self.driver_for_spec(self.spec_for(name))

    def status(self, specs: list[DatastoreSpec]) -> list[dict[str, Any]]:
        out = []
        for spec in specs:
            try:
                out.append({"id": spec.id, "ok": True, **self.driver_for_spec(spec).report()})
            except Exception as exc:  # noqa: BLE001 — raport ma pokazać każdy błąd magazynu
                out.append({"id": spec.id, "ok": False, "error": str(exc)[:500]})
        return out

    # --- kontrakt StorageDriver (delegacja do magazynu dysku) --------------------

    def capacity(self) -> tuple[int, int]:
        return self.default.capacity()

    def is_file_based(self, name: str) -> bool:
        return self.for_volume(name).file_based

    def create_volume(self, name: str, template: str, disk_gb: int) -> Path:
        return self.for_volume(name).create_volume(name, template, disk_gb)

    def resize_volume(self, name: str, disk_gb: int) -> None:
        self.for_volume(name).resize_volume(name, disk_gb)

    def delete_volume(self, name: str) -> None:
        self.for_volume(name).delete_volume(name)

    def snapshot_volume(self, name: str, snapshot: str) -> Path:
        return self.for_volume(name).snapshot_volume(name, snapshot)

    def restore_volume(self, name: str, snapshot: str) -> None:
        self.for_volume(name).restore_volume(name, snapshot)

    def volume_path(self, name: str) -> Path:
        return self.for_volume(name).volume_path(name)

    def size_gb(self, name: str) -> int:
        return self.for_volume(name).size_gb(name)

    def disk_xml(self, name: str, dev: str = "vda") -> str:
        return self.for_volume(name).disk_xml(name, dev)

    def create_blank(self, name: str, disk_gb: int) -> Path:
        return self.for_volume(name).create_blank(name, disk_gb)

    def export_image(self, name: str, target: Path, compress: bool = True, after_snapshot: Any = None) -> None:
        self.for_volume(name).export_image(name, target, compress=compress, after_snapshot=after_snapshot)

    def import_image(self, name: str, source: Path) -> None:
        self.for_volume(name).import_image(name, source)

    def libvirt_prepare(self, conn: Any, name: str) -> None:
        self.for_volume(name).libvirt_prepare(conn)

    def overlay_path(self, name: str) -> Path:
        return self.for_volume(name).overlay_path(name)

    def has_overlay(self, name: str) -> bool:
        return self.for_volume(name).has_overlay(name)

    def create_overlay(self, name: str) -> Path:
        return self.for_volume(name).create_overlay(name)

    def commit_overlay(self, name: str) -> None:
        self.for_volume(name).commit_overlay(name)

    def drop_overlay(self, name: str) -> None:
        self.for_volume(name).drop_overlay(name)
