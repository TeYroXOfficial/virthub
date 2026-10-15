"""Sterownik hypervisora — jedyne miejsce, które rozmawia z libvirt.

Dwie implementacje wspólnego interfejsu:

* `LibvirtDriver` — produkcyjny, na hypervisorze z KVM.
* `MockDriver` — stan w pliku JSON, żeby panel dało się rozwijać i testować
  end-to-end na maszynie bez wirtualizacji (Windows, macOS, CI).

Control plane nie widzi różnicy — ta sama odpowiedź HTTP w obu trybach.
"""

from __future__ import annotations

import json
import logging
import math
import os
import platform
import re
import secrets
import socket
import time
import uuid as uuidlib
from abc import ABC, abstractmethod
from pathlib import Path
from xml.sax.saxutils import escape
from typing import Any

from .cloudinit import CloudInitBuilder
from .console import ConsoleTarget, TerminalTarget, VncTarget
from .guest_os import cpu_model, from_guest_agent, outbound_ipv4, parse_os_release
from .isos import IsoLibrary
from .jobs import progress
from .config import Settings
from .domain_xml import CPU_PERIOD_US, build_domain_xml, cpu_quota_us, disk_dev, domain_name, with_hardware
from .nat import host_networks
from . import private_net
from .network import NetworkManager, interface_name, mac_address
from .schemas import (
    GuestOs,
    CreateVmRequest,
    HostHealth,
    IsoMountRequest,
    NetworkConfigRequest,
    PowerAction,
    RebuildVmRequest,
    ResizeVmRequest,
    VmStats,
)
from .shell import run
from .storage import StorageError, build_storage_driver

log = logging.getLogger("virthub.driver")

AGENT_VERSION = "0.1.0"


class DriverError(RuntimeError):
    pass


class VmNotFound(DriverError):
    def __init__(self, uuid: str):
        super().__init__(f"Nie znaleziono maszyny o UUID {uuid} na tym hypervisorze.")


def server_id_from_name(name: str) -> int:
    """`virthub-42` -> 42."""
    try:
        return int(name.rsplit("-", 1)[1])
    except (IndexError, ValueError) as exc:
        raise DriverError(f"Nieoczekiwana nazwa domeny: {name!r}") from exc


def apparmor_enabled(path: Path = Path("/sys/module/apparmor/parameters/enabled")) -> bool:
    """Incus i libvirt zamykają gości profilem AppArmora — bez niego izolacja jest słabsza."""
    try:
        return path.read_text().strip().upper().startswith("Y")
    except OSError:
        return False


class HypervisorDriver(ABC):
    def __init__(self, settings: Settings):
        self.settings = settings
        self.storage = build_storage_driver(settings)
        self.network = NetworkManager(settings)
        self.cloudinit = CloudInitBuilder(settings)
        self.isos = IsoLibrary(settings)

    @abstractmethod
    def create_vm(self, req: CreateVmRequest) -> dict[str, Any]: ...

    # --- disaster recovery ----------------------------------------------------

    def recover_vm(self, req: Any) -> dict[str, Any]:
        """Maszyna z kopii na celu zewnętrznym: dysk z kopii zamiast z szablonu,
        reszta (sieć, cloud-init, domena) jak przy tworzeniu."""
        base = CreateVmRequest(**req.model_dump(exclude={"backup"}))
        return self.create_vm(base, restore=req.backup)

    def _restore_disk(self, name: str, req: CreateVmRequest, restore: Any) -> Path:
        """Pobiera dysk maszyny z kopii (z weryfikacją sumy) i dopasowuje rozmiar."""
        if not self.storage.is_file_based(name):
            raise DriverError("Odtwarzanie z kopii wymaga magazynu katalogowego (qcow2), a dysk ma trafić do Cepha.")
        path = self.storage.volume_path(name)
        if path.exists():
            raise DriverError(f"Dysk {path} już istnieje — odmawiam nadpisania.")
        path.parent.mkdir(parents=True, exist_ok=True)
        incoming = path.with_name(f".{name}.recover.qcow2")
        if getattr(restore, "url", None):  # migracja: dysk prosto z węzła źródłowego
            from .migration import TransferError, download

            try:
                download(restore, incoming)
            except TransferError as exc:
                raise DriverError(str(exc)) from exc
        else:
            self._fetch_chain(restore, incoming)
        if not self.settings.is_mock:
            import json as _json

            info = _json.loads(run(["qemu-img", "info", "--output=json", str(incoming)]))
            if int(info["virtual-size"]) < req.disk_gb * 1024**3:
                run(["qemu-img", "resize", str(incoming), f"{req.disk_gb}G"])
        os.replace(incoming, path)
        return path

    def dr_state(self) -> list[dict[str, Any]]:
        """Domeny węzła z pełną konfiguracją (XML) — do zrzutu DR."""
        return []

    # --- migracje między węzłami ---------------------------------------------

    def import_vm(self, req: Any) -> dict[str, Any]:
        """Migracja na zimno, strona docelowa: dysk z węzła źródłowego, reszta jak przy tworzeniu."""
        base = CreateVmRequest(**req.model_dump(exclude={"source"}))
        return self.create_vm(base, restore=req.source)

    def _flatten_for_export(self, name: str) -> Path:
        """Dysk jako samodzielny plik qcow2 (bez łańcucha backing) do wystawienia."""
        if not self.storage.is_file_based(name):
            raise DriverError("Migracja na zimno wymaga dysku w magazynie katalogowym (qcow2).")
        source = self.storage.volume_path(name)
        export = source.with_name(f".{name}.export-{secrets.token_hex(4)}.qcow2")
        if self.settings.is_mock:
            export.write_bytes(source.read_bytes())
        else:
            run(["qemu-img", "convert", "-O", "qcow2", str(source), str(export)], timeout=6 * 3600)
        return export

    def _publish_export(self, name: str, export: Path, was_running: bool) -> dict[str, Any]:
        from .migration import TransferStore, sha256_file

        progress("export", 90)
        token = TransferStore(self.settings).register(export, name)
        return {"token": token, "size_bytes": export.stat().st_size, "sha256": sha256_file(export), "was_running": was_running}

    def export_vm(self, uuid: str) -> dict[str, Any]:
        raise DriverError("Ten węzeł nie obsługuje migracji maszyn.")

    def prepare_incoming(self, req: CreateVmRequest) -> dict[str, Any]:
        raise DriverError("Ten węzeł nie obsługuje migracji na żywo.")

    def migrate_live(self, uuid: str, req: Any) -> dict[str, Any]:
        raise DriverError("Ten węzeł nie obsługuje migracji na żywo.")

    def dr_snapshot(self, req: Any) -> dict[str, Any]:
        """Konfiguracja i stan węzła w jednym pliku JSON na celu kopii.

        Źródłem prawdy przy odtwarzaniu jest panel; zrzut służy do wglądu i
        ręcznego odtworzenia elementów spoza panelu (np. własnych reguł)."""
        import json as _json
        import socket

        from . import __version__

        registry = {}
        for name, spec in (getattr(self.storage, "_load", lambda: {})() or {}).items():
            registry[name] = {k: v for k, v in spec.items() if k != "key"}  # bez kluczy Cepha
        state_files = {}
        for directory in (self.settings.network_state_dir, self.settings.nat_state_dir):
            if directory.is_dir():
                for file in sorted(directory.glob("*.json")):
                    state_files[f"{directory.name}/{file.name}"] = file.read_text(encoding="utf-8")
        snapshot = {
            "format": "virthub-dr/1",
            "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            "hostname": socket.gethostname(),
            "agent_version": __version__,
            "driver": self.settings.driver,
            "domains": self.dr_state(),
            "datastores": registry,
            "state_files": state_files,
        }
        tmp = self.settings.image_dir / f".dr-{secrets.token_hex(4)}.json"
        tmp.write_text(_json.dumps(snapshot, indent=1), encoding="utf-8")
        result = self._ship(tmp, req)
        return result | {"domains": len(snapshot["domains"])}

    @abstractmethod
    def power(self, uuid: str, action: PowerAction) -> dict[str, Any]: ...

    @abstractmethod
    def rebuild(self, uuid: str, req: RebuildVmRequest) -> dict[str, Any]: ...

    @abstractmethod
    def resize(self, uuid: str, req: ResizeVmRequest) -> dict[str, Any]: ...

    @abstractmethod
    def delete(self, uuid: str) -> dict[str, Any]: ...

    @abstractmethod
    def snapshot(self, uuid: str, name: str) -> dict[str, Any]: ...

    @abstractmethod
    def restore(self, uuid: str, name: str) -> dict[str, Any]: ...

    @abstractmethod
    def configure_network(self, uuid: str, req: NetworkConfigRequest) -> dict[str, Any]: ...

    @abstractmethod
    def stats(self, uuid: str) -> VmStats: ...

    @abstractmethod
    def health(self) -> HostHealth: ...

    @abstractmethod
    def console_target(self, uuid: str) -> ConsoleTarget:
        """Dokąd prowadzi konsola maszyny: gniazdo VNC albo polecenie terminala."""

    def reset_password(self, uuid: str, password: str, username: str = "root") -> dict[str, Any]:
        raise DriverError("Ten węzeł nie obsługuje zmiany hasła.")

    def set_cpu_limit(self, uuid: str, cpu_limit_percent: int | None) -> dict[str, Any]:
        """Twardy limit procesora (procent jednego rdzenia), od razu i na stałe."""
        raise DriverError("Ten węzeł nie obsługuje limitu procesora.")

    def set_mac(self, uuid: str, mac: str | None) -> dict[str, Any]:
        """MAC karty sieciowej (None = domyślny z puli 52:54:00). Działa od następnego uruchomienia."""
        raise DriverError("Ten węzeł nie obsługuje zmiany MAC.")

    def guest_os(self, uuid: str) -> GuestOs:
        """System zainstalowany w maszynie (może różnić się od szablonu — np. po instalacji z ISO)."""
        raise DriverError("Ten węzeł nie odczytuje systemu gościa.")

    def mount_iso(self, uuid: str, req: IsoMountRequest) -> dict[str, Any]:
        """Płyta ISO i rozruch z niej — tylko maszyny wirtualne."""
        raise DriverError("Obrazy ISO są dostępne tylko dla maszyn wirtualnych KVM.")

    def backup(self, uuid: str, req: Any) -> dict[str, Any]:
        """Kopia dysku na zewnętrzny cel — tylko maszyny KVM."""
        raise DriverError("Kopie zapasowe na zewnętrzne cele są dostępne tylko dla maszyn KVM.")

    def restore_backup(self, uuid: str, req: Any) -> dict[str, Any]:
        raise DriverError("Kopie zapasowe na zewnętrzne cele są dostępne tylko dla maszyn KVM.")

    def _fetch_chain(self, req: Any, incoming: Path) -> None:
        """Kopia pełna albo łańcuch pełna + przyrostowe spłaszczony do jednego pliku."""
        chain = list(getattr(req, "chain", None) or [])
        if not chain:
            self._fetch(req, incoming)
            return
        links = [*chain, req]
        parts = [incoming.with_name(f"{incoming.name}.part{i}") for i in range(len(links))]
        try:
            for i, (link, part) in enumerate(zip(links, parts)):
                progress("download", 10 + int(60 * i / len(links)))
                self._fetch(type("Link", (), {"key": link.key, "sha256": link.sha256, "target": req.target})(), part)
            if self.settings.is_mock:
                os.replace(parts[-1], incoming)
                return
            # Ogniwo przyrostowe ma tylko zmienione klastry — podpinamy je pod poprzednie i spłaszczamy.
            for prev, cur in zip(parts, parts[1:]):
                run(["qemu-img", "rebase", "-u", "-F", "qcow2", "-b", str(prev), str(cur)])
            progress("restore", 80)
            run(["qemu-img", "convert", "-O", "qcow2", str(parts[-1]), str(incoming)], timeout=6 * 3600)
        finally:
            for part in parts:
                part.unlink(missing_ok=True)

    def delete_backups(self, req: Any) -> dict[str, Any]:
        from .backup_targets import BackupTargetError, build_target

        try:
            target = build_target(req.target.model_dump())
            for key in req.keys:
                target.delete(key)
        except BackupTargetError as exc:
            raise DriverError(str(exc)) from exc
        return {"deleted": len(req.keys)}

    @staticmethod
    def _sha256(path: Path) -> str:
        import hashlib

        digest = hashlib.sha256()
        with path.open("rb") as fh:
            while chunk := fh.read(1024 * 1024):
                digest.update(chunk)
        return digest.hexdigest()

    def _ship(self, image: Path, req: Any) -> dict[str, Any]:
        """Wysyłka gotowego obrazu na cel i sprzątanie pliku tymczasowego."""
        from .backup_targets import BackupTargetError, build_target

        try:
            sha = self._sha256(image)
            size = image.stat().st_size
            progress("upload", 60)
            build_target(req.target.model_dump()).upload(image, req.key)
        except BackupTargetError as exc:
            raise DriverError(f"Wysyłka kopii nie powiodła się: {exc}") from exc
        finally:
            image.unlink(missing_ok=True)
        progress("upload", 100)
        return {"key": req.key, "size_bytes": size, "sha256": sha}

    def _fetch(self, req: Any, target: Path) -> None:
        from .backup_targets import BackupTargetError, build_target

        try:
            progress("download", 10)
            build_target(req.target.model_dump()).download(req.key, target)
        except BackupTargetError as exc:
            target.unlink(missing_ok=True)
            raise DriverError(f"Pobranie kopii nie powiodło się: {exc}") from exc
        if req.sha256 and self._sha256(target) != req.sha256:
            target.unlink(missing_ok=True)
            raise DriverError("Suma kontrolna kopii się nie zgadza — plik uszkodzony.")

    def set_private_nics(self, uuid: str, req: Any) -> dict[str, Any]:
        """Karty w sieciach prywatnych — tylko maszyny wirtualne KVM."""
        raise DriverError("Sieci prywatne są dostępne tylko dla maszyn KVM.")

    def set_disks(self, uuid: str, req: Any) -> dict[str, Any]:
        """Dodatkowe dyski i powiększanie dysków — tylko maszyny wirtualne KVM."""
        raise DriverError("Dodatkowe dyski są dostępne tylko dla maszyn KVM.")

    def set_hardware(self, uuid: str, req: Any) -> dict[str, Any]:
        raise DriverError("Ustawienia sprzętu dotyczą maszyn wirtualnych KVM.")

    @staticmethod
    def extra_disk_name(name: str, slot: int) -> str:
        return f"{name}-d{slot}"

    def set_hostdevs(self, uuid: str, req: Any) -> dict[str, Any]:
        """Urządzenia PCI (GPU, karty) — tylko maszyny wirtualne KVM."""
        raise DriverError("Przekazywanie urządzeń PCI jest dostępne tylko dla maszyn KVM.")

    def pci_devices(self) -> dict[str, Any]:
        return {"iommu": False, "devices": []}

    def elevate(self, uuid: str, action: str) -> dict[str, Any]:
        """Elevate (nakładka na dysk) — tylko maszyny wirtualne KVM."""
        raise DriverError("Elevate jest dostępny tylko dla maszyn wirtualnych KVM.")

    def _assert_not_elevated(self, name: str) -> None:
        if self.storage.has_overlay(name):
            raise DriverError("Maszyna ma aktywny Elevate — najpierw zatwierdź albo odrzuć zmiany.")

    def prefetch_image(self, alias: str) -> dict[str, Any]:
        """Pobiera szablon na węzeł z wyprzedzeniem. Dotyczy kontenerów —
        maszyny KVM korzystają z obrazów qcow2 wgrywanych do katalogu szablonów."""
        raise DriverError(
            "Ten węzeł uruchamia maszyny KVM — szablony kontenerów go nie dotyczą."
        )

    # --- wspólne dla wszystkich implementacji -------------------------------

    def _host_metrics(self) -> dict[str, Any]:
        import psutil

        mem = psutil.virtual_memory()
        disk_total, disk_free = self.storage.capacity()
        try:
            load1 = psutil.getloadavg()[0]
        except (AttributeError, OSError):
            load1 = 0.0

        return {
            "hostname": socket.gethostname(),
            "cpu_cores_total": psutil.cpu_count(logical=True) or 0,
            "cpu_model": cpu_model(),
            "public_ipv4": outbound_ipv4(),
            "host_networks": [] if self.settings.is_mock else host_networks(),
            "cpu_load_1m": round(load1, 2),
            "ram_mb_total": mem.total // (1024**2),
            "ram_mb_free": mem.available // (1024**2),
            "disk_gb_total": disk_total,
            "disk_gb_free": disk_free,
        }


class LibvirtDriver(HypervisorDriver):
    """Produkcyjna ścieżka: libvirt + QEMU-KVM."""

    # Mapowanie kodów stanu libvirt na słownik zrozumiały dla panelu.
    _STATES = {
        0: "nostate",
        1: "running",
        2: "blocked",
        3: "paused",
        4: "shutting-down",
        5: "stopped",
        6: "crashed",
        7: "suspended",
    }

    def __init__(self, settings: Settings):
        super().__init__(settings)
        self._conn = None

    # --- połączenie ---------------------------------------------------------

    @property
    def conn(self):
        """Leniwe połączenie z reconnectem — libvirtd bywa restartowany przy
        aktualizacjach, a agent nie może przez to paść."""
        import libvirt

        if self._conn is not None:
            try:
                if self._conn.isAlive():
                    return self._conn
            except libvirt.libvirtError:
                pass
            self._conn = None

        try:
            self._conn = libvirt.open(self.settings.libvirt_uri)
        except libvirt.libvirtError as exc:
            raise DriverError(
                f"Brak połączenia z libvirt ({self.settings.libvirt_uri}): {exc}. "
                f"Sprawdź, czy usługa libvirtd działa i czy agent ma dostęp do gniazda."
            ) from exc
        return self._conn

    def _domain(self, uuid: str):
        import libvirt

        try:
            return self.conn.lookupByUUIDString(uuid)
        except libvirt.libvirtError as exc:
            raise VmNotFound(uuid) from exc

    # --- cykl życia ---------------------------------------------------------

    def create_vm(self, req: CreateVmRequest, restore: Any = None) -> dict[str, Any]:
        name = domain_name(req.server_id)
        vm_uuid = str(uuidlib.uuid4())
        mac = req.mac or mac_address(req.server_id)
        target = interface_name(req.server_id)
        vnc_password = secrets.token_urlsafe(12)

        created: list[str] = []
        try:
            progress("image", 20)
            self.storage.bind(name, req.storage)
            created.append("binding")
            disk = self._restore_disk(name, req, restore) if restore else self.storage.create_volume(name, req.template, req.disk_gb)
            created.append("volume")
            for extra in req.extra_disks:
                self.storage.create_blank(self.extra_disk_name(name, extra.slot), extra.size_gb)
                created.append(f"disk:{extra.slot}")

            progress("network", 55)
            seed = self.cloudinit.build(
                name=name,
                server_id=req.server_id,
                hostname=req.hostname,
                interfaces=req.interfaces,
                nameservers=req.nameservers,
                ssh_keys=req.ssh_keys,
                root_password=req.root_password,
                mac=mac,
                os_type=req.os_type,
                scripts=req.scripts,
                private_nics=req.private_nics,
            )
            created.append("seed")

            # Adres prywatny → mostek NAT, publiczny → mostek z kartą fizyczną.
            bridge = self.network.prepare(req.interfaces)
            private_net.ensure_bridges(self.settings, req.private_nics)

            xml = build_domain_xml(
                name=name,
                uuid=vm_uuid,
                vcpu=req.vcpu,
                ram_mb=req.ram_mb,
                cpu_limit_percent=req.cpu_limit_percent,
                disk_path=str(disk),
                disk_xml=self.storage.disk_xml(name),
                seed_path=str(seed),
                bridge=bridge,
                mac=mac,
                interface_target=target,
                vnc_listen=self.settings.vnc_listen,
                vnc_password=vnc_password,
                windows=req.os_type == "windows",
                allow_nested=self.nested_enabled(),
                extra_interfaces=[private_net.interface_xml(req.server_id, n) for n in req.private_nics],
                extra_disks=[self.storage.disk_xml(self.extra_disk_name(name, d.slot), disk_dev(d.slot)) for d in req.extra_disks],
                hardware=req.hardware,
            )

            self.storage.libvirt_prepare(self.conn, name)
            domain = self.conn.defineXML(xml)
            created.append("domain")

            self.network.configure(req.server_id, req.interfaces, firewall=[], net_filter=req.filter)
            created.append("network")

            progress("boot", 85)
            if getattr(restore, "start", True):
                domain.create()  # start
        except Exception as exc:
            # Rollback: nieudany provisioning nie może zostawić półmaszyny,
            # bo control plane zwolni zarezerwowane zasoby i IP.
            log.error("Provisioning VPS %s nieudany (%s) — sprzątam", req.server_id, exc)
            self._rollback(req.server_id, name, created)
            raise

        return {
            "uuid": vm_uuid,
            "name": name,
            "mac": mac,
            "interface": target,
            "vnc_port": self._vnc_port(domain),
            "vnc_password": vnc_password,
            "state": self._state(domain),
        }

    def export_vm(self, uuid: str) -> dict[str, Any]:
        domain = self._domain(uuid)
        name = domain.name()
        self._assert_not_elevated(name)
        was_running = bool(domain.isActive())
        if was_running:
            progress("stop", 5)
            domain.shutdown()
            for _ in range(90):
                if not domain.isActive():
                    break
                time.sleep(1)
            if domain.isActive():
                domain.destroy()
        progress("export", 20)
        return self._publish_export(name, self._flatten_for_export(name), was_running)

    def prepare_incoming(self, req: CreateVmRequest) -> dict[str, Any]:
        """Migracja na żywo, strona docelowa: sieć, nośnik cloud-init i pusty dysk o tym samym rozmiarze."""
        name = domain_name(req.server_id)
        self.storage.bind(name, req.storage)
        if not self.storage.is_file_based(name):
            raise DriverError("Migracja na żywo z kopiowaniem dysku wymaga magazynu katalogowego (qcow2).")
        path = self.storage.volume_path(name)
        if path.exists():
            raise DriverError(f"Dysk {path} już istnieje na węźle docelowym.")
        path.parent.mkdir(parents=True, exist_ok=True)
        run(["qemu-img", "create", "-f", "qcow2", str(path), f"{req.disk_gb}G"])
        self.cloudinit.build(
            name=name, server_id=req.server_id, hostname=req.hostname, interfaces=req.interfaces,
            nameservers=req.nameservers, ssh_keys=[], root_password=None,
            mac=req.mac or mac_address(req.server_id), os_type=req.os_type, scripts=[], private_nics=req.private_nics,
        )
        self.network.prepare(req.interfaces)
        private_net.ensure_bridges(self.settings, req.private_nics)
        self.network.configure(req.server_id, req.interfaces, firewall=[], net_filter=req.filter)
        return {"ready": True, "disk": str(path)}

    def migrate_live(self, uuid: str, req: Any) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        name = domain.name()
        self._assert_not_elevated(name)
        if not domain.isActive():
            raise DriverError("Migracja na żywo dotyczy działającej maszyny — zatrzymaną przenieś na zimno.")
        uri = self.settings.migration_uri.format(host=req.host)
        flags = (libvirt.VIR_MIGRATE_LIVE | libvirt.VIR_MIGRATE_PEER2PEER | libvirt.VIR_MIGRATE_PERSIST_DEST
                 | libvirt.VIR_MIGRATE_UNDEFINE_SOURCE | libvirt.VIR_MIGRATE_NON_SHARED_DISK
                 | libvirt.VIR_MIGRATE_AUTO_CONVERGE)
        params = {libvirt.VIR_MIGRATE_PARAM_BANDWIDTH: req.bandwidth_mbps // 8} if req.bandwidth_mbps else {}
        progress("migrate", 10)
        try:
            domain.migrateToURI3(uri, params, flags)
        except libvirt.libvirtError as exc:
            raise DriverError(f"Migracja na żywo nie powiodła się: {exc}") from exc
        # Domena działa już na węźle docelowym — tu zostają tylko pliki i reguły.
        self.network.teardown(server_id_from_name(name))
        self.storage.delete_volume(name)
        self.storage.unbind(name)
        return {"uuid": uuid, "migrated": True, "uri": uri}

    def dr_state(self) -> list[dict[str, Any]]:
        out = []
        for domain in self.conn.listAllDomains():
            if domain.name().startswith("virthub-"):
                out.append({"name": domain.name(), "uuid": domain.UUIDString(), "state": self._state(domain), "xml": domain.XMLDesc(0)})
        return out

    def _rollback(self, server_id: int, name: str, created: list[str]) -> None:
        import libvirt

        if "network" in created:
            try:
                self.network.teardown(server_id)
            except Exception:
                log.exception("Rollback: nie udało się usunąć reguł sieciowych")
        if "domain" in created:
            try:
                dom = self.conn.lookupByName(name)
                if dom.isActive():
                    dom.destroy()
                self._undefine(dom)
            except libvirt.libvirtError:
                log.exception("Rollback: nie udało się usunąć domeny %s", name)
        for item in created:
            if item.startswith("disk:"):
                try:
                    self.storage.delete_volume(self.extra_disk_name(name, int(item[5:])))
                except Exception:
                    log.exception("Rollback: nie udało się usunąć dodatkowego dysku %s", item)
        if "volume" in created or "seed" in created:
            try:
                self.storage.delete_volume(name)
            except Exception:
                log.exception("Rollback: nie udało się usunąć dysku %s", name)
        if "binding" in created:
            self.storage.unbind(name)

    def power(self, uuid: str, action: PowerAction) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        active = domain.isActive()
        if not active and action in (PowerAction.START, PowerAction.REBOOT):
            self._ensure_domain_bridges(domain)

        try:
            if action is PowerAction.START:
                if not active:
                    domain.create()
            elif action is PowerAction.STOP:
                if active:
                    domain.shutdown()  # ACPI — gość zamyka się sam
            elif action is PowerAction.REBOOT:
                if active:
                    domain.reboot(0)
                else:
                    domain.create()
            elif action is PowerAction.FORCE_OFF:
                if active:
                    domain.destroy()  # odcięcie zasilania
        except libvirt.libvirtError as exc:
            raise DriverError(f"Operacja zasilania {action.value} nie powiodła się: {exc}") from exc

        return {"uuid": uuid, "state": self._state(domain)}

    def rebuild(self, uuid: str, req: RebuildVmRequest) -> dict[str, Any]:
        domain = self._domain(uuid)
        name = domain.name()
        self._assert_not_elevated(name)
        server_id = server_id_from_name(name)
        was_running = bool(domain.isActive())

        progress("stop", 5)
        if was_running:
            domain.destroy()

        interfaces = req.interfaces if req.interfaces is not None else self._interfaces_from_domain(domain)
        disk_gb = self._disk_size_gb(name)

        # MAC z panelu (adres IP dostawcy z wirtualnym MAC) albo ten, który karta ma teraz.
        current_mac = self._domain_mac(domain)
        mac = req.mac or current_mac or mac_address(server_id)
        if mac != current_mac:
            self._define_mac(domain, mac)
        # vmx/svm zgodnie z hostem (starsze maszyny dostają przy reinstalacji tę samą ochronę).
        self._apply_cpu_policy(domain, self.nested_enabled())
        if req.private_nics is not None:
            self._sync_private_nics(domain, server_id, req.private_nics, live=False)

        progress("image", 20)
        self.storage.delete_volume(name)
        self.storage.create_volume(name, req.template, disk_gb)
        progress("network", 60)
        self.cloudinit.build(
            name=name,
            server_id=server_id,
            hostname=req.hostname or name,
            interfaces=interfaces,
            nameservers=req.nameservers or ["1.1.1.1", "9.9.9.9"],
            ssh_keys=req.ssh_keys,
            root_password=req.root_password,
            mac=mac,
            os_type=req.os_type,
            scripts=req.scripts,
            private_nics=req.private_nics,
        )

        progress("boot", 85)
        domain.create()
        return {"uuid": uuid, "state": self._state(domain), "template": req.template}

    def resize(self, uuid: str, req: ResizeVmRequest) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        self._assert_not_elevated(domain.name())
        if domain.isActive():
            raise DriverError(
                "Zmiana pakietu wymaga zatrzymanej maszyny — zatrzymaj VPS i ponów operację."
            )

        name = domain.name()
        progress("disk", 30)
        self.storage.resize_volume(name, req.disk_gb)

        progress("resources", 75)
        try:
            domain.setMemoryFlags(req.ram_mb * 1024, libvirt.VIR_DOMAIN_AFFECT_CONFIG)
            domain.setMaxMemory(req.ram_mb * 1024)
            domain.setVcpusFlags(req.vcpu, libvirt.VIR_DOMAIN_AFFECT_CONFIG
                                 | libvirt.VIR_DOMAIN_VCPU_MAXIMUM)
            domain.setVcpusFlags(req.vcpu, libvirt.VIR_DOMAIN_AFFECT_CONFIG)
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się zmienić zasobów maszyny: {exc}") from exc

        if "cpu_limit_percent" in req.model_fields_set:
            self._apply_cpu_limit(domain, req.cpu_limit_percent)

        return {"uuid": uuid, "vcpu": req.vcpu, "ram_mb": req.ram_mb, "disk_gb": req.disk_gb}

    def set_cpu_limit(self, uuid: str, cpu_limit_percent: int | None) -> dict[str, Any]:
        self._apply_cpu_limit(self._domain(uuid), cpu_limit_percent)
        return {"uuid": uuid, "cpu_limit_percent": cpu_limit_percent}

    def set_mac(self, uuid: str, mac: str | None) -> dict[str, Any]:
        """Nowy MAC w trwałej definicji domeny. QEMU działającej maszyny trzyma
        stary — zmiana działa po wyłączeniu i włączeniu (nie po restarcie z systemu)."""
        domain = self._domain(uuid)
        target = mac or mac_address(server_id_from_name(domain.name()))
        changed = target != self._domain_mac(domain)
        if changed:
            self._define_mac(domain, target)
        return {"uuid": uuid, "mac": target, "changed": changed,
                "restart_required": changed and bool(domain.isActive())}

    def _domain_mac(self, domain: Any) -> str | None:
        import xml.etree.ElementTree as ET

        import libvirt

        root = ET.fromstring(domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))
        node = root.find("./devices/interface/mac")
        return node.get("address").lower() if node is not None and node.get("address") else None

    def nested_enabled(self) -> bool:
        """Czy host ma włączoną zagnieżdżoną wirtualizację (ustawia ją harden-host.sh
        wg polityki: automatycznie na jądrze z poprawkami albo decyzją administratora)."""
        if self.settings.allow_nested:
            return True
        for module in ("kvm_intel", "kvm_amd"):
            try:
                value = Path(f"/sys/module/{module}/parameters/nested").read_text().strip()
            except OSError:
                continue
            return value in ("1", "Y", "y")
        return False

    def _apply_cpu_policy(self, domain: Any, nested: bool) -> bool:
        """vmx/svm w procesorze gościa tylko wtedy, gdy host pozwala na zagnieżdżanie.
        Zmienia trwałą definicję — działająca maszyna dostanie ją po wyłączeniu i włączeniu."""
        import xml.etree.ElementTree as ET

        import libvirt

        root = ET.fromstring(domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))
        cpu = root.find("./cpu")
        if cpu is None:
            cpu = ET.SubElement(root, "cpu", {"mode": "host-passthrough", "check": "none"})
        disabled = {f.get("name") for f in cpu.findall("feature") if f.get("policy") == "disable"}
        if nested and not disabled & {"vmx", "svm"}:
            return False
        if not nested and {"vmx", "svm"} <= disabled:
            return False
        for f in list(cpu.findall("feature")):
            if f.get("name") in ("vmx", "svm"):
                cpu.remove(f)
        if not nested:
            for name in ("vmx", "svm"):
                ET.SubElement(cpu, "feature", {"policy": "disable", "name": name})
        try:
            self.conn.defineXML(ET.tostring(root, encoding="unicode"))
        except libvirt.libvirtError as exc:
            log.warning("Nie udało się zmienić vmx/svm w %s: %s", domain.name(), exc)
            return False
        return True

    def sync_guest_cpu(self) -> int:
        """Dopasowuje vmx/svm wszystkich maszyn do stanu hosta — wołane przy raporcie
        zdrowia, ale tylko gdy stan zagnieżdżania się zmienił."""
        nested = self.nested_enabled()
        if getattr(self, "_cpu_policy_applied", None) == nested:
            return 0
        changed = 0
        try:
            for domain in self.conn.listAllDomains() or []:
                changed += int(self._apply_cpu_policy(domain, nested))
        except Exception as exc:  # libvirt chwilowo niedostępny — spróbujemy przy następnym raporcie
            log.warning("Nie udało się dopasować vmx/svm maszyn: %s", exc)
            return 0
        self._cpu_policy_applied = nested
        if changed:
            log.info("Zagnieżdżanie %s: zmieniono definicję %d maszyn (zadziała po wyłączeniu i włączeniu)",
                     "włączone" if nested else "wyłączone", changed)
        return changed

    def _define_mac(self, domain: Any, mac: str) -> None:
        import xml.etree.ElementTree as ET

        import libvirt

        root = ET.fromstring(domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))
        node = root.find("./devices/interface/mac")
        if node is None:
            raise DriverError("Maszyna nie ma karty sieciowej — nie ma czemu zmienić MAC.")
        node.set("address", mac)
        try:
            self.conn.defineXML(ET.tostring(root, encoding="unicode"))
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się zapisać nowego MAC: {exc}") from exc

    @staticmethod
    def _apply_cpu_limit(domain: Any, cpu_limit_percent: int | None) -> None:
        """Ta sama wartość co `<cputune>` w XML — dla działającej maszyny także
        na żywo, bez restartu (cgroup cpu.max całej domeny)."""
        import libvirt

        flags = libvirt.VIR_DOMAIN_AFFECT_CONFIG
        if domain.isActive():
            flags |= libvirt.VIR_DOMAIN_AFFECT_LIVE
        try:
            domain.setSchedulerParametersFlags(
                {"global_period": CPU_PERIOD_US, "global_quota": cpu_quota_us(cpu_limit_percent)},
                flags,
            )
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się ustawić limitu procesora: {exc}") from exc

    def delete(self, uuid: str) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        name = domain.name()
        server_id = server_id_from_name(name)

        if domain.isActive():
            domain.destroy()

        try:
            self._undefine(domain)
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się usunąć definicji domeny: {exc}") from exc

        self.network.teardown(server_id)
        private_net.prune_bridges(self.settings)
        for slot in range(1, 8):
            self.storage.delete_volume(self.extra_disk_name(name, slot))
        self.storage.delete_volume(name)
        self.storage.unbind(name)
        return {"uuid": uuid, "deleted": True}

    # --- kopie na zewnętrzne cele ------------------------------------------

    def backup(self, uuid: str, req: Any) -> dict[str, Any]:
        """Spójny obraz dysku: działająca maszyna pisze chwilowo do nakładki,
        a my kopiujemy (i kompresujemy) zamrożony dysk bazowy."""
        import libvirt

        domain = self._domain(uuid)
        name = domain.name()
        self._assert_not_elevated(name)
        if req.checkpoint and domain.isActive():
            return self._backup_with_checkpoint(domain, req)
        # Kopia bez checkpointu (maszyna zatrzymana): stare checkpointy tracą sens — następna będzie pełna.
        self._drop_checkpoints(domain)
        base = self.storage.volume_path(name)
        overlay = base.with_name(f"{name}.backup-overlay.qcow2")
        image = base.with_name(f".{name}.backup-{secrets.token_hex(4)}.qcow2")
        frozen = False
        try:
            if domain.isActive():
                self._external_overlay(domain, overlay, "virthub-backup")
                frozen = True
            progress("export", 5)
            run(["qemu-img", "convert", "-O", "qcow2", *(["-c"] if req.compress else []), str(base), str(image)], timeout=6 * 3600)
        except libvirt.libvirtError as exc:
            image.unlink(missing_ok=True)
            raise DriverError(f"Nie udało się zamrozić dysku do kopii: {exc}") from exc
        except Exception:
            image.unlink(missing_ok=True)
            raise
        finally:
            if frozen:
                self._block_commit(domain)
                overlay.unlink(missing_ok=True)
        return self._ship(image, req) | {"kind": "full", "checkpoint": None}

    def _drop_checkpoints(self, domain: Any, keep: str | None = None) -> None:
        import libvirt

        try:
            for checkpoint in domain.listAllCheckpoints():
                if checkpoint.getName() != keep:
                    checkpoint.delete()
        except (AttributeError, libvirt.libvirtError):
            pass

    def _backup_with_checkpoint(self, domain: Any, req: Any) -> dict[str, Any]:
        """Kopia przez libvirt backupBegin: pełna albo tylko bloki zmienione od checkpointu poprzedniej kopii."""
        import libvirt
        from xml.etree import ElementTree as ET

        name = domain.name()
        base = self.storage.volume_path(name)
        image = base.with_name(f".{name}.backup-{secrets.token_hex(4)}.qcow2")
        parent = None
        if req.incremental_from:
            try:
                domain.checkpointLookupByName(req.incremental_from)
                parent = req.incremental_from
            except libvirt.libvirtError:
                parent = None  # checkpointu nie ma (np. po przywróceniu) — kopia pełna
        if parent is None:
            self._drop_checkpoints(domain)
        others = "".join(
            f"<disk name='{escape(t.get('dev'))}' {{attr}}='no'/>"
            for d in ET.fromstring(domain.XMLDesc(0)).find("devices").findall("disk")
            if (t := d.find("target")) is not None and t.get("dev") != "vda"
        )
        backup_xml = (
            "<domainbackup>" + (f"<incremental>{escape(parent)}</incremental>" if parent else "")
            + f"<disks><disk name='vda' backup='yes' type='file'><driver type='qcow2'/><target file='{escape(str(image))}'/></disk>"
            + others.replace("{attr}", "backup") + "</disks></domainbackup>"
        )
        checkpoint_xml = (
            f"<domaincheckpoint><name>{escape(req.checkpoint)}</name><disks><disk name='vda' checkpoint='bitmap'/>"
            + others.replace("{attr}", "checkpoint") + "</disks></domaincheckpoint>"
        )
        progress("export", 5)
        try:
            domain.backupBegin(backup_xml, checkpoint_xml, 0)
            deadline = time.monotonic() + 6 * 3600
            while True:
                info = domain.jobInfo()
                if info[0] == libvirt.VIR_DOMAIN_JOB_NONE:
                    break
                if info[3]:
                    progress("export", 5 + int(50 * info[4] / max(1, info[3])))
                if time.monotonic() > deadline:
                    domain.abortJob()
                    raise DriverError("Kopia trwa zbyt długo — przerwano.")
                time.sleep(1)
            stats = domain.jobStats(libvirt.VIR_DOMAIN_JOB_STATS_COMPLETED)
            if stats.get("type") != libvirt.VIR_DOMAIN_JOB_COMPLETED:
                raise DriverError(f"Zadanie kopii libvirt nie zakończyło się poprawnie: {stats.get('errmsg', stats.get('type'))}")
        except libvirt.libvirtError as exc:
            image.unlink(missing_ok=True)
            raise DriverError(f"Kopia przez libvirt nie powiodła się: {exc}") from exc
        except Exception:
            image.unlink(missing_ok=True)
            raise
        # Zostaje tylko najnowszy checkpoint — od niego liczy się następna kopia przyrostowa.
        self._drop_checkpoints(domain, keep=req.checkpoint)
        if parent is None and req.compress:
            packed = image.with_name(image.name + ".c")
            run(["qemu-img", "convert", "-c", "-O", "qcow2", str(image), str(packed)], timeout=6 * 3600)
            os.replace(packed, image)
        return self._ship(image, req) | {"kind": "incremental" if parent else "full", "checkpoint": req.checkpoint, "parent": parent}

    def restore_backup(self, uuid: str, req: Any) -> dict[str, Any]:
        domain = self._domain(uuid)
        name = domain.name()
        self._assert_not_elevated(name)
        base = self.storage.volume_path(name)
        incoming = base.with_name(f".{name}.restore.qcow2")
        self._fetch_chain(req, incoming)
        was_running = bool(domain.isActive())
        if was_running:
            domain.destroy()
        # Nowy dysk nie ma bitmap starych checkpointów — następna kopia będzie pełna.
        self._drop_checkpoints(domain)
        os.replace(incoming, base)
        if was_running:
            domain.create()
        return {"uuid": uuid, "key": req.key, "state": self._state(domain)}

    @staticmethod
    def _other_disks_excluded(domain: Any) -> str:
        """Migawka dyskowa obejmuje tylko vda — bez jawnego snapshot='no' libvirt
        założyłby nakładki także na dodatkowych dyskach."""
        import xml.etree.ElementTree as ET

        import libvirt

        root = ET.fromstring(domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))
        devs = [t.get("dev") for d in root.findall("./devices/disk")
                if d.get("device") == "disk" and (t := d.find("target")) is not None and t.get("dev") != "vda"]
        return "".join(f"<disk name='{escape(dev)}' snapshot='no'/>" for dev in devs)

    def _external_overlay(self, domain: Any, overlay: Path, snap_name: str) -> None:
        import libvirt

        snap = (
            f"<domainsnapshot><name>{snap_name}</name><disks>"
            f"<disk name='vda' snapshot='external'><driver type='qcow2'/><source file='{escape(str(overlay))}'/></disk>"
            + self._other_disks_excluded(domain)
            + "</disks></domainsnapshot>"
        )
        domain.snapshotCreateXML(
            snap,
            libvirt.VIR_DOMAIN_SNAPSHOT_CREATE_DISK_ONLY
            | libvirt.VIR_DOMAIN_SNAPSHOT_CREATE_NO_METADATA
            | libvirt.VIR_DOMAIN_SNAPSHOT_CREATE_ATOMIC,
        )

    # --- urządzenia PCI ------------------------------------------------------

    def pci_devices(self) -> dict[str, Any]:
        from . import pci

        return {"iommu": pci.iommu_enabled(), "devices": pci.list_devices()}

    @staticmethod
    def _undefine(domain: Any) -> None:
        """Usunięcie definicji razem ze zmiennymi UEFI (NVRAM) i stanem vTPM —
        zwykłe undefine() odmawia dla maszyny z UEFI."""
        import libvirt

        flags = getattr(libvirt, "VIR_DOMAIN_UNDEFINE_NVRAM", 4) | getattr(libvirt, "VIR_DOMAIN_UNDEFINE_TPM", 0)
        try:
            domain.undefineFlags(flags)
        except (AttributeError, TypeError):
            domain.undefine()

    def set_hardware(self, uuid: str, req: Any) -> dict[str, Any]:
        """Nowy sprzęt w trwałej definicji — działa po wyłączeniu i włączeniu maszyny."""
        import libvirt

        domain = self._domain(uuid)
        xml = domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE)
        try:
            new_xml = with_hardware(xml, req.hardware, self.nested_enabled())
            self.conn.defineXML(new_xml)
        except (ValueError, libvirt.libvirtError) as exc:
            raise DriverError(f"Nie udało się zmienić sprzętu maszyny: {exc}") from exc
        changed = new_xml != xml
        return {"uuid": uuid, "changed": changed, "restart_required": changed and bool(domain.isActive()),
                "state": self._state(domain)}

    def set_disks(self, uuid: str, req: Any) -> dict[str, Any]:
        """Dodatkowe dyski (vdb–vdh) i powiększanie — na żywo, jeśli maszyna działa."""
        import xml.etree.ElementTree as ET

        import libvirt

        domain = self._domain(uuid)
        name = domain.name()
        self._assert_not_elevated(name)
        live = bool(domain.isActive())
        flags = libvirt.VIR_DOMAIN_AFFECT_CONFIG | (libvirt.VIR_DOMAIN_AFFECT_LIVE if live else 0)
        root = ET.fromstring(domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))
        current: dict[int, ET.Element] = {}
        bus = "virtio"
        for disk in root.findall("./devices/disk"):
            target = disk.find("target")
            if disk.get("device") != "disk" or target is None:
                continue
            dev = target.get("dev", "")
            if dev == "vda":
                bus = target.get("bus") or bus
            elif re.fullmatch(r"vd[b-h]", dev):
                current["abcdefgh".index(dev[2])] = disk
        wanted = {d.slot: d for d in req.disks}
        removed = sorted(set(current) - set(wanted))
        if removed and not req.delete_removed:
            raise DriverError("Usunięcie dysku kasuje jego dane — potwierdź usunięcie dysków " + ", ".join(disk_dev(s) for s in removed) + ".")

        def grow(volume: str, dev: str, size_gb: int) -> bool:
            current_gb = self.storage.size_gb(volume)
            if size_gb < current_gb:
                raise DriverError(f"Dysk {dev} ma {current_gb} GB — zmniejszanie dysków nie jest wspierane.")
            if size_gb == current_gb:
                return False
            if live:
                domain.blockResize(dev, size_gb * 1024**3, libvirt.VIR_DOMAIN_BLOCK_RESIZE_BYTES)
            else:
                self.storage.resize_volume(volume, size_gb)
            return True

        changed: list[str] = []
        try:
            if req.primary_gb and grow(name, "vda", req.primary_gb):
                changed.append("vda")
            for slot in removed:
                domain.detachDeviceFlags(ET.tostring(current[slot], encoding="unicode"), flags)
                self.storage.delete_volume(self.extra_disk_name(name, slot))
                changed.append(disk_dev(slot))
            for slot, spec in sorted(wanted.items()):
                volume, dev = self.extra_disk_name(name, slot), disk_dev(slot)
                if slot in current:
                    if grow(volume, dev, spec.size_gb):
                        changed.append(dev)
                    continue
                self.storage.create_blank(volume, spec.size_gb)
                element = ET.fromstring(self.storage.disk_xml(volume, dev))
                element.find("target").set("bus", bus)
                domain.attachDeviceFlags(ET.tostring(element, encoding="unicode"), flags)
                changed.append(dev)
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się zmienić dysków maszyny: {exc}") from exc
        return {"uuid": uuid, "changed": changed, "disks": self._disk_sizes(name, wanted), "state": self._state(domain)}

    def _disk_sizes(self, name: str, wanted: dict[int, Any]) -> list[dict[str, Any]]:
        out = [{"slot": 0, "dev": "vda", "size_gb": self.storage.size_gb(name)}]
        for slot in sorted(wanted):
            out.append({"slot": slot, "dev": disk_dev(slot), "size_gb": self.storage.size_gb(self.extra_disk_name(name, slot))})
        return out

    def _ensure_domain_bridges(self, domain: Any) -> int:
        """Mostki sieci prywatnych z definicji domeny (`ip link` nie przetrwa restartu hosta)."""
        import libvirt

        ids = {int(m) for m in re.findall(r"<source bridge=['\"]vhp(\d+)['\"]", domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))}
        private_net.ensure_bridges(self.settings, [type("Nic", (), {"network_id": i})() for i in ids])
        return len(ids)

    def restore_private_bridges(self) -> int:
        restored = 0
        for domain in self.conn.listAllDomains():
            try:
                restored += self._ensure_domain_bridges(domain)
            except Exception:
                log.exception("Nie udało się odtworzyć mostków sieci prywatnych domeny %s", domain.name())
        return restored

    def set_private_nics(self, uuid: str, req: Any) -> dict[str, Any]:
        domain = self._domain(uuid)
        server_id = server_id_from_name(domain.name())
        nics = self._sync_private_nics(domain, server_id, req.nics, live=bool(domain.isActive()))
        return {"uuid": uuid, "nics": nics, "state": self._state(domain)}

    def _sync_private_nics(self, domain: Any, server_id: int, wanted: list, live: bool) -> list[dict[str, Any]]:
        """Dołącza brakujące karty, odłącza zbędne i zmienione (na żywo i w definicji)."""
        import xml.etree.ElementTree as ET

        import libvirt

        private_net.ensure_bridges(self.settings, wanted)
        root = ET.fromstring(domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE))
        current: dict[int, ET.Element] = {}
        for iface in root.findall("./devices/interface"):
            target = iface.find("target")
            parsed = private_net.parse_target(target.get("dev") if target is not None else None)
            if parsed and parsed[0] == server_id:
                current[parsed[1]] = iface
        desired = {n.slot: n for n in wanted}
        flags = libvirt.VIR_DOMAIN_AFFECT_CONFIG | (libvirt.VIR_DOMAIN_AFFECT_LIVE if live else 0)

        def same(element: ET.Element, nic: Any) -> bool:
            source = element.find("source")
            mac = element.find("mac")
            return (source is not None and source.get("bridge") == private_net.bridge_name(nic.network_id)
                    and mac is not None and (mac.get("address") or "").lower() == (nic.mac or private_net.nic_mac(server_id, nic.slot)).lower())

        try:
            for slot, element in current.items():
                if slot not in desired or not same(element, desired[slot]):
                    domain.detachDeviceFlags(ET.tostring(element, encoding="unicode"), flags)
            for slot, nic in sorted(desired.items()):
                if slot not in current or not same(current[slot], nic):
                    domain.attachDeviceFlags(private_net.interface_xml(server_id, nic), flags)
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się zmienić kart sieciowych: {exc}") from exc
        private_net.prune_bridges(self.settings)
        return [{"slot": n.slot, "network_id": n.network_id, "interface": private_net.nic_target(server_id, n.slot),
                 "mac": (n.mac or private_net.nic_mac(server_id, n.slot)).lower()} for n in sorted(wanted, key=lambda n: n.slot)]

    def set_hostdevs(self, uuid: str, req: Any) -> dict[str, Any]:
        import libvirt

        from . import pci

        domain = self._domain(uuid)
        xml = domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE)
        try:
            new_xml = pci.with_hostdevs(xml, [(d.address, d.xml) for d in req.devices])
        except pci.PciError as exc:
            raise DriverError(str(exc)) from exc
        if req.devices and not pci.iommu_enabled():
            raise DriverError("IOMMU jest wyłączone na hoście — włącz intel_iommu=on / amd_iommu=on w jądrze i zrestartuj węzeł.")
        try:
            self.conn.defineXML(new_xml)
            restarted = False
            if req.restart and domain.isActive():
                domain.destroy()
                domain.create()
                restarted = True
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się ustawić urządzeń PCI: {exc}") from exc
        return {"uuid": uuid, "devices": pci.hostdev_addresses(new_xml), "restarted": restarted, "state": self._state(domain)}

    # --- Elevate (nakładka na dysk) -----------------------------------------

    ELEVATE_COMMIT_TIMEOUT = 7200

    def elevate(self, uuid: str, action: str) -> dict[str, Any]:
        import libvirt

        from .domain_xml import with_disk_source

        domain = self._domain(uuid)
        name = domain.name()
        overlay = self.storage.overlay_path(name)
        base = self.storage.volume_path(name)
        active = bool(domain.isActive())

        def define_with(path: Path) -> None:
            xml = domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE)
            self.conn.defineXML(with_disk_source(xml, str(path)))

        try:
            if action == "start":
                if overlay.exists():
                    raise DriverError("Elevate już trwa — zatwierdź albo odrzuć zmiany.")
                if active:
                    # Zewnętrzny snapshot dyskowy: od teraz zapisy idą do nakładki,
                    # dysk bazowy zostaje nietknięty. libvirt podmienia też definicję.
                    snap = (
                        "<domainsnapshot><name>virthub-elevate</name><disks>"
                        f"<disk name='vda' snapshot='external'><driver type='qcow2'/><source file='{escape(str(overlay))}'/></disk>"
                        + self._other_disks_excluded(domain)
                        + "</disks></domainsnapshot>"
                    )
                    domain.snapshotCreateXML(
                        snap,
                        libvirt.VIR_DOMAIN_SNAPSHOT_CREATE_DISK_ONLY
                        | libvirt.VIR_DOMAIN_SNAPSHOT_CREATE_NO_METADATA
                        | libvirt.VIR_DOMAIN_SNAPSHOT_CREATE_ATOMIC,
                    )
                else:
                    self.storage.create_overlay(name)
                    define_with(overlay)
            elif action == "commit":
                if not overlay.exists():
                    raise DriverError("Brak aktywnego Elevate.")
                if active:
                    self._block_commit(domain)
                    define_with(base)
                    overlay.unlink(missing_ok=True)
                else:
                    self.storage.commit_overlay(name)
                    define_with(base)
            elif action == "discard":
                if not overlay.exists():
                    raise DriverError("Brak aktywnego Elevate.")
                # Działająca maszyna ma w pamięci stan z nakładki — po odrzuceniu
                # musi wystartować od nowa z dysku bazowego.
                if active:
                    domain.destroy()
                define_with(base)
                overlay.unlink(missing_ok=True)
                if active:
                    domain.create()
            else:
                raise DriverError(f"Nieznana akcja Elevate: {action}")
        except libvirt.libvirtError as exc:
            raise DriverError(f"Elevate ({action}) nie powiódł się: {exc}") from exc

        return {"uuid": uuid, "elevated": overlay.exists(), "state": self._state(domain)}

    def _block_commit(self, domain: Any) -> None:
        """Zapis nakładki do dysku bazowego na żywo i przełączenie z powrotem na bazę."""
        import libvirt

        domain.blockCommit("vda", None, None, 0, libvirt.VIR_DOMAIN_BLOCK_COMMIT_ACTIVE)
        deadline = time.monotonic() + self.ELEVATE_COMMIT_TIMEOUT
        while time.monotonic() < deadline:
            info = domain.blockJobInfo("vda", 0)
            if info and info.get("end", 0) > 0:
                progress("commit", min(99, int(info["cur"] * 100 / info["end"])))
                if info["cur"] == info["end"]:
                    domain.blockJobAbort("vda", libvirt.VIR_DOMAIN_BLOCK_JOB_ABORT_PIVOT)
                    return
            time.sleep(1)
        domain.blockJobAbort("vda", 0)
        raise DriverError("Zapis zmian Elevate trwał zbyt długo — przerwano, maszyna działa dalej na nakładce.")

    # --- snapshoty ----------------------------------------------------------

    def snapshot(self, uuid: str, name: str) -> dict[str, Any]:
        domain = self._domain(uuid)
        self._assert_not_elevated(domain.name())
        if domain.isActive():
            raise DriverError(
                "Snapshot spójny dyskowo wymaga zatrzymanej maszyny. Zatrzymaj VPS "
                "albo poczekaj na wsparcie snapshotów live (planowane z QEMU guest agent)."
            )
        path = self.storage.snapshot_volume(domain.name(), name)
        return {"uuid": uuid, "snapshot": name, "path": str(path)}

    def restore(self, uuid: str, name: str) -> dict[str, Any]:
        domain = self._domain(uuid)
        self._assert_not_elevated(domain.name())
        if domain.isActive():
            domain.destroy()
        self.storage.restore_volume(domain.name(), name)
        domain.create()
        return {"uuid": uuid, "snapshot": name, "state": self._state(domain)}

    # --- sieć ---------------------------------------------------------------

    def configure_network(self, uuid: str, req: NetworkConfigRequest) -> dict[str, Any]:
        domain = self._domain(uuid)
        server_id = server_id_from_name(domain.name())
        self.network.configure(server_id, req.interfaces, req.firewall, req.policy, req.filter)
        return {
            "uuid": uuid,
            "interfaces": len(req.interfaces),
            "firewall_rules": len(req.firewall),
        }

    # --- telemetria ---------------------------------------------------------

    def stats(self, uuid: str) -> VmStats:
        domain = self._domain(uuid)
        state = self._state(domain)

        if not domain.isActive():
            return VmStats(uuid=uuid, state=state)

        raw = self.conn.domainListGetStats([domain], 0)
        metrics = raw[0][1] if raw else {}

        ram_total_kb = metrics.get("balloon.maximum", 0)
        ram_used_kb = metrics.get("balloon.rss", 0)

        return VmStats(
            uuid=uuid,
            state=state,
            cpu_time_ns=metrics.get("cpu.time", 0),
            ram_total_mb=ram_total_kb // 1024,
            ram_used_mb=ram_used_kb // 1024,
            disk_read_bytes=metrics.get("block.0.rd.bytes", 0),
            disk_write_bytes=metrics.get("block.0.wr.bytes", 0),
            net_rx_bytes=metrics.get("net.0.rx.bytes", 0),
            net_tx_bytes=metrics.get("net.0.tx.bytes", 0),
        )

    def mount_iso(self, uuid: str, req: IsoMountRequest) -> dict[str, Any]:
        import libvirt

        from .domain_xml import with_iso

        iso_path = None
        if req.iso is not None:
            path = self.isos.path(req.iso)
            if not path.is_file():
                raise DriverError(f"Obrazu {req.iso} nie ma jeszcze na tym węźle — poczekaj, aż się pobierze.")
            iso_path = str(path)

        domain = self._domain(uuid)
        xml = domain.XMLDesc(libvirt.VIR_DOMAIN_XML_INACTIVE)
        try:
            self.conn.defineXML(with_iso(xml, iso_path, req.boot))
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się zmienić płyty w maszynie: {exc}") from exc

        # Zmiana kolejności rozruchu i nowy napęd działają dopiero po ponownym
        # uruchomieniu QEMU — reboot gościa ich nie wczyta.
        if req.restart:
            if domain.isActive():
                domain.destroy()
            domain.create()

        return {
            "uuid": uuid,
            "iso": req.iso,
            "boot": req.boot and req.iso is not None,
            "restarted": req.restart,
            "state": self._state(domain),
        }

    def guest_os(self, uuid: str) -> GuestOs:
        import libvirt

        domain = self._domain(uuid)
        if not domain.isActive():
            raise DriverError("Maszyna jest wyłączona — system odczytamy po jej uruchomieniu.")
        try:
            info = domain.guestInfo(libvirt.VIR_DOMAIN_GUEST_INFO_OS, 0)
        except (libvirt.libvirtError, AttributeError) as exc:
            raise DriverError(f"qemu-guest-agent w maszynie nie odpowiada: {exc}") from exc
        return GuestOs(**from_guest_agent(info), source="guest-agent")

    def reset_password(self, uuid: str, password: str, username: str = "root") -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        if not domain.isActive():
            raise DriverError("Maszyna musi działać, żeby zmienić hasło — uruchom ją i spróbuj ponownie.")
        try:
            # Przez qemu-guest-agent w gościu: hasło nie przechodzi przez sieć
            # ani przez linię poleceń.
            domain.setUserPassword(username, password, 0)
        except libvirt.libvirtError as exc:
            raise DriverError(
                "Nie udało się zmienić hasła — w maszynie musi działać qemu-guest-agent "
                "(apt install qemu-guest-agent && systemctl enable --now qemu-guest-agent). "
                f"Szczegóły: {exc}"
            ) from exc
        return {"uuid": uuid, "state": self._state(domain)}

    def console_target(self, uuid: str) -> ConsoleTarget:
        domain = self._domain(uuid)
        if not domain.isActive():
            raise DriverError("Konsola jest dostępna tylko dla uruchomionej maszyny.")
        port = self._vnc_port(domain)
        if port is None:
            raise DriverError("Maszyna nie ma aktywnego ekranu VNC.")
        return VncTarget(host=self.settings.vnc_listen, port=port)

    def health(self) -> HostHealth:
        self.sync_guest_cpu()
        metrics = self._host_metrics()
        connected = True
        running = 0
        try:
            running = len(self.conn.listDomainsID() or [])
        except DriverError:
            connected = False

        return HostHealth(
            agent_version=AGENT_VERSION,
            driver="libvirt",
            virtualization="kvm",
            libvirt_connected=connected,
            running_vms=running,
            security={"apparmor": apparmor_enabled(), "host_guard": self.settings.guard_host},
            **metrics,
        )

    # --- pomocnicze ---------------------------------------------------------

    def _state(self, domain) -> str:
        return self._STATES.get(domain.state()[0], "unknown")

    def _vnc_port(self, domain) -> int | None:
        """Port przydzielony przez libvirt (autoport) — znany dopiero po starcie."""
        from xml.etree import ElementTree

        root = ElementTree.fromstring(domain.XMLDesc(0))
        graphics = root.find("./devices/graphics[@type='vnc']")
        if graphics is None:
            return None
        port = graphics.get("port")
        return int(port) if port and port != "-1" else None

    def _interfaces_from_domain(self, domain) -> list:
        """Przy przebudowie odtwarzamy adresację z aktualnej konfiguracji sieci
        hosta — control plane i tak przyśle świeżą konfigurację zaraz po tym."""
        return []

    def _disk_size_gb(self, name: str) -> int:
        return self.storage.size_gb(name)


class MockDriver(HypervisorDriver):
    """Stan trzymany w pliku JSON. Pozwala rozwijać panel bez hypervisora."""

    def __init__(self, settings: Settings):
        super().__init__(settings)
        self._state_file: Path = settings.state_db.parent / "mock-domains.json"

    def dr_state(self) -> list[dict[str, Any]]:
        return [{"name": r["name"], "uuid": uuid, "state": r["state"], "xml": None} for uuid, r in self._load().items()]

    def export_vm(self, uuid: str) -> dict[str, Any]:
        data = self._load()
        record = self._get(uuid)
        was_running = record["state"] == "running"
        record["state"] = "stopped"
        data[uuid] = record
        self._save(data)
        return self._publish_export(record["name"], self._flatten_for_export(record["name"]), was_running)

    def prepare_incoming(self, req: CreateVmRequest) -> dict[str, Any]:
        name = domain_name(req.server_id)
        self.storage.bind(name, req.storage)
        path = self.storage.volume_path(name)
        if path.exists():
            raise DriverError(f"Dysk {path} już istnieje na węźle docelowym.")
        self.storage.create_volume(name, req.template, req.disk_gb)
        return {"ready": True, "disk": str(path)}

    def migrate_live(self, uuid: str, req: Any) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        if record["state"] != "running":
            raise DriverError("Migracja na żywo dotyczy działającej maszyny — zatrzymaną przenieś na zimno.")
        del data[uuid]
        self._save(data)
        self.storage.delete_volume(record["name"])
        self.storage.unbind(record["name"])
        return {"uuid": uuid, "migrated": True, "uri": self.settings.migration_uri.format(host=req.host)}

    def _load(self) -> dict[str, dict]:
        if not self._state_file.exists():
            return {}
        return json.loads(self._state_file.read_text(encoding="utf-8"))

    def _save(self, data: dict[str, dict]) -> None:
        self._state_file.parent.mkdir(parents=True, exist_ok=True)
        self._state_file.write_text(json.dumps(data, indent=2), encoding="utf-8")

    def _get(self, uuid: str) -> dict:
        data = self._load()
        if uuid not in data:
            raise VmNotFound(uuid)
        return data[uuid]

    @staticmethod
    def _stage(stage: str, percent: int) -> None:
        """Etap jak w prawdziwym driverze; VH_MOCK_STEP_DELAY spowalnia go na
        potrzeby podglądu animacji w panelu."""
        progress(stage, percent)
        delay = float(os.environ.get("VH_MOCK_STEP_DELAY", "0") or 0)
        if delay > 0:
            time.sleep(delay)

    def create_vm(self, req: CreateVmRequest, restore: Any = None) -> dict[str, Any]:
        name = domain_name(req.server_id)
        vm_uuid = str(uuidlib.uuid4())
        self._stage("prepare", 5)
        self._stage("image", 20)
        self.storage.bind(name, req.storage)
        disk = self._restore_disk(name, req, restore) if restore else self.storage.create_volume(name, req.template, req.disk_gb)
        self._stage("network", 55)
        self.cloudinit.build(
            name=name,
            server_id=req.server_id,
            hostname=req.hostname,
            interfaces=req.interfaces,
            nameservers=req.nameservers,
            ssh_keys=req.ssh_keys,
            root_password=req.root_password,
            mac=req.mac or mac_address(req.server_id),
            os_type=req.os_type,
            scripts=req.scripts,
            private_nics=req.private_nics,
        )

        record = {
            "uuid": vm_uuid,
            "name": name,
            "server_id": req.server_id,
            "state": "running",
            "vcpu": req.vcpu,
            "cpu_limit_percent": req.cpu_limit_percent,
            "ram_mb": req.ram_mb,
            "disk_gb": req.disk_gb,
            "template": req.template,
            "mac": req.mac or mac_address(req.server_id),
            "interface": interface_name(req.server_id),
            "vnc_port": 5900 + (req.server_id % 100),
            "vnc_password": secrets.token_urlsafe(12),
            "disk_path": str(disk),
            "datastore_id": req.storage.id if req.storage else None,
            "private_nics": self._nic_records(req.server_id, req.private_nics),
            "extra_disks": {str(d.slot): d.size_gb for d in req.extra_disks},
            "hardware": req.hardware.model_dump() if req.hardware else None,
        }
        for extra in req.extra_disks:
            self.storage.create_blank(self.extra_disk_name(name, extra.slot), extra.size_gb)
        if not getattr(restore, "start", True):
            record["state"] = "stopped"
        self._stage("boot", 85)
        data = self._load()
        data[vm_uuid] = record
        self._save(data)
        return record

    def power(self, uuid: str, action: PowerAction) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        record["state"] = {
            PowerAction.START: "running",
            PowerAction.STOP: "stopped",
            PowerAction.FORCE_OFF: "stopped",
            PowerAction.REBOOT: "running",
        }[action]
        self._save(data)
        return {"uuid": uuid, "state": record["state"]}

    def rebuild(self, uuid: str, req: RebuildVmRequest) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid) or {}
        if not record:
            raise VmNotFound(uuid)
        self._stage("stop", 5)
        self._stage("image", 20)
        self._stage("network", 60)
        self._stage("boot", 85)
        record["template"] = req.template
        record["state"] = "running"
        if req.mac:
            record["mac"] = req.mac
        if req.private_nics is not None:
            record["private_nics"] = self._nic_records(record["server_id"], req.private_nics)
        self._save(data)
        return {"uuid": uuid, "state": "running", "template": req.template}

    def resize(self, uuid: str, req: ResizeVmRequest) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        if record["state"] == "running":
            raise DriverError(
                "Zmiana pakietu wymaga zatrzymanej maszyny — zatrzymaj VPS i ponów operację."
            )
        record.update(vcpu=req.vcpu, ram_mb=req.ram_mb, disk_gb=req.disk_gb)
        if "cpu_limit_percent" in req.model_fields_set:
            record["cpu_limit_percent"] = req.cpu_limit_percent
        self._save(data)
        return {"uuid": uuid, **{k: record[k] for k in ("vcpu", "ram_mb", "disk_gb")}}

    def set_cpu_limit(self, uuid: str, cpu_limit_percent: int | None) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        record["cpu_limit_percent"] = cpu_limit_percent
        self._save(data)
        return {"uuid": uuid, "cpu_limit_percent": cpu_limit_percent}

    def set_mac(self, uuid: str, mac: str | None) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        target = mac or mac_address(record["server_id"])
        changed = record.get("mac") != target
        record["mac"] = target
        self._save(data)
        return {"uuid": uuid, "mac": target, "changed": changed,
                "restart_required": changed and record.get("state") == "running"}

    def delete(self, uuid: str) -> dict[str, Any]:
        data = self._load()
        record = data.pop(uuid, None)
        if record is None:
            raise VmNotFound(uuid)
        for slot in range(1, 8):
            self.storage.delete_volume(self.extra_disk_name(record["name"], slot))
        self.storage.delete_volume(record["name"])
        self.storage.unbind(record["name"])
        self._save(data)
        return {"uuid": uuid, "deleted": True}

    def set_hardware(self, uuid: str, req: Any) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        changed = record.get("hardware") != req.hardware.model_dump()
        record["hardware"] = req.hardware.model_dump()
        self._save(data)
        return {"uuid": uuid, "changed": changed, "restart_required": changed and record["state"] == "running", "state": record["state"]}

    def set_disks(self, uuid: str, req: Any) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        name = record["name"]
        self._assert_not_elevated(name)
        current = {int(k): v for k, v in (record.get("extra_disks") or {}).items()}
        wanted = {d.slot: d.size_gb for d in req.disks}
        removed = sorted(set(current) - set(wanted))
        if removed and not req.delete_removed:
            raise DriverError("Usunięcie dysku kasuje jego dane — potwierdź usunięcie dysków " + ", ".join(disk_dev(s) for s in removed) + ".")
        for slot, size in wanted.items():
            if size < current.get(slot, 0):
                raise DriverError(f"Dysk {disk_dev(slot)} ma {current[slot]} GB — zmniejszanie dysków nie jest wspierane.")
        if req.primary_gb and req.primary_gb < record["disk_gb"]:
            raise DriverError(f"Dysk vda ma {record['disk_gb']} GB — zmniejszanie dysków nie jest wspierane.")
        changed = []
        if req.primary_gb and req.primary_gb != record["disk_gb"]:
            record["disk_gb"] = req.primary_gb
            self.storage.resize_volume(name, req.primary_gb)
            changed.append("vda")
        for slot in removed:
            self.storage.delete_volume(self.extra_disk_name(name, slot))
            changed.append(disk_dev(slot))
        for slot, size in sorted(wanted.items()):
            volume = self.extra_disk_name(name, slot)
            if slot not in current:
                self.storage.create_blank(volume, size)
            elif size != current[slot]:
                self.storage.resize_volume(volume, size)
            else:
                continue
            changed.append(disk_dev(slot))
        record["extra_disks"] = {str(k): v for k, v in wanted.items()}
        self._save(data)
        disks = [{"slot": 0, "dev": "vda", "size_gb": record["disk_gb"]}] + [
            {"slot": s, "dev": disk_dev(s), "size_gb": wanted[s]} for s in sorted(wanted)]
        return {"uuid": uuid, "changed": changed, "disks": disks, "state": record["state"]}

    def snapshot(self, uuid: str, name: str) -> dict[str, Any]:
        record = self._get(uuid)
        self._assert_not_elevated(record["name"])
        path = self.storage.snapshot_volume(record["name"], name)
        return {"uuid": uuid, "snapshot": name, "path": str(path)}

    def restore(self, uuid: str, name: str) -> dict[str, Any]:
        record = self._get(uuid)
        self._assert_not_elevated(record["name"])
        self.storage.restore_volume(record["name"], name)
        return {"uuid": uuid, "snapshot": name, "state": record["state"]}

    def configure_network(self, uuid: str, req: NetworkConfigRequest) -> dict[str, Any]:
        self._get(uuid)
        return {
            "uuid": uuid,
            "interfaces": len(req.interfaces),
            "firewall_rules": len(req.firewall),
        }

    def stats(self, uuid: str) -> VmStats:
        record = self._get(uuid)
        # Prawdopodobne, zmienne w czasie wartości — panel ma co rysować na
        # żywo. Liczniki rosną jak w prawdziwej maszynie (narastająco), więc
        # panel liczy z nich szybkość tą samą metodą co dla libvirt i Incusa.
        seed = record["server_id"]
        now = time.time()
        wave = (math.sin(now / 20 + seed) + 1) / 2
        # Całka z fali: licznik rośnie zawsze, a jego pochodna (szybkość,
        # którą liczy panel) waha się łagodnie między base a base + amp.
        area = (now - 20 * math.cos(now / 20 + seed)) / 2

        def counter(base: int, amp: int) -> int:
            return int(base * now + amp * area)

        return VmStats(
            uuid=uuid,
            state=record["state"],
            cpu_percent=round(5 + 55 * wave, 2),
            cpu_time_ns=counter(int(0.3e9 * record["vcpu"]), int(0.5e9 * record["vcpu"])),
            ram_used_mb=int(record["ram_mb"] * (0.35 + 0.2 * wave)),
            ram_total_mb=record["ram_mb"],
            disk_read_bytes=counter(400_000, 300_000),
            disk_write_bytes=counter(150_000, 100_000),
            net_rx_bytes=counter(900_000, 500_000),
            net_tx_bytes=counter(300_000, 200_000),
        )

    def prefetch_image(self, alias: str) -> dict[str, Any]:
        # Postęp jak przy prawdziwym pobieraniu — do podglądu paska w panelu.
        for pct in range(0, 101, 20):
            self._stage("download", pct) if pct < 100 else progress("download", 100, "Obraz na węźle")
        return {"alias": alias, "downloaded": True, "cached": False}

    def guest_os(self, uuid: str) -> GuestOs:
        record = self._get(uuid)
        # Tryb deweloperski: „system" wynika z nazwy szablonu (ubuntu-24.04.qcow2, debian/12/cloud).
        stem = record.get("template", "linux").replace(".qcow2", "").replace("/cloud", "")
        family, _, version = stem.replace("/", "-").partition("-")
        family = {"rockylinux": "rocky"}.get(family, family)
        pretty = f"{family.capitalize()} {version}".strip() if family != "ubuntu" else f"Ubuntu {version} LTS"
        return GuestOs(id=family, name=family.capitalize(), version=version or None, pretty_name=pretty, source="os-release")

    def reset_password(self, uuid: str, password: str, username: str = "root") -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        if record["state"] != "running":
            raise DriverError("Maszyna musi działać, żeby zmienić hasło — uruchom ją i spróbuj ponownie.")
        record["password_changes"] = record.get("password_changes", 0) + 1
        self._save(data)
        return {"uuid": uuid, "state": record["state"]}

    def backup(self, uuid: str, req: Any) -> dict[str, Any]:
        data = self._load()
        record = self._get(uuid)
        self._assert_not_elevated(record["name"])
        running = record["state"] == "running"
        checkpoints = record.get("checkpoints", [])
        parent = req.incremental_from if running and req.checkpoint and req.incremental_from in checkpoints else None
        kind = "incremental" if parent else "full"
        record["checkpoints"] = [req.checkpoint] if running and req.checkpoint else []
        data[uuid] = record
        self._save(data)
        image = self.settings.image_dir / f".{record['name']}.backup-{secrets.token_hex(4)}.qcow2"
        image.write_bytes(f"mock {kind} backup of {record['name']} cp={req.checkpoint} parent={parent}\n".encode())
        return self._ship(image, req) | {"kind": kind, "checkpoint": record["checkpoints"][0] if record["checkpoints"] else None, "parent": parent}

    def restore_backup(self, uuid: str, req: Any) -> dict[str, Any]:
        record = self._get(uuid)
        self._assert_not_elevated(record["name"])
        incoming = self.settings.image_dir / f".{record['name']}.restore.qcow2"
        self._fetch_chain(req, incoming)
        os.replace(incoming, self.storage.volume_path(record["name"]))
        data = self._load()
        data[uuid]["checkpoints"] = []
        self._save(data)
        return {"uuid": uuid, "key": req.key, "state": record["state"]}

    def pci_devices(self) -> dict[str, Any]:
        return {"iommu": True, "devices": [
            {"address": "0000:41:00.0", "vendor_id": "10de", "device_id": "2204", "class": "0300", "type": "vga",
             "driver": "vfio-pci", "iommu_group": 31, "name": "NVIDIA Corporation GA102 [GeForce RTX 3090]"},
            {"address": "0000:41:00.1", "vendor_id": "10de", "device_id": "1aef", "class": "0403", "type": "audio",
             "driver": "vfio-pci", "iommu_group": 31, "name": "NVIDIA Corporation GA102 High Definition Audio"},
        ]}

    @staticmethod
    def _nic_records(server_id: int, nics: list) -> list[dict[str, Any]]:
        return [{"slot": n.slot, "network_id": n.network_id, "interface": private_net.nic_target(server_id, n.slot),
                 "mac": (n.mac or private_net.nic_mac(server_id, n.slot)).lower(), "address": n.address}
                for n in sorted(nics, key=lambda n: n.slot)]

    def set_private_nics(self, uuid: str, req: Any) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        record["private_nics"] = self._nic_records(record["server_id"], req.nics)
        self._save(data)
        return {"uuid": uuid, "nics": record["private_nics"], "state": record["state"]}

    def set_hostdevs(self, uuid: str, req: Any) -> dict[str, Any]:
        from . import pci

        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        try:
            for device in req.devices:
                pci.hostdev_element(device.address, device.xml)
        except pci.PciError as exc:
            raise DriverError(str(exc)) from exc
        record["hostdevs"] = [d.address for d in req.devices]
        self._save(data)
        return {"uuid": uuid, "devices": record["hostdevs"], "restarted": bool(req.restart), "state": record["state"]}

    def elevate(self, uuid: str, action: str) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        name = record["name"]
        try:
            if action == "start":
                self.storage.create_overlay(name)
            elif action == "commit":
                self.storage.commit_overlay(name)
            elif action == "discard":
                if not self.storage.has_overlay(name):
                    raise DriverError("Brak aktywnego Elevate.")
                self.storage.drop_overlay(name)
            else:
                raise DriverError(f"Nieznana akcja Elevate: {action}")
        except StorageError as exc:
            raise DriverError(str(exc)) from exc
        return {"uuid": uuid, "elevated": self.storage.has_overlay(name), "state": record["state"]}

    def mount_iso(self, uuid: str, req: IsoMountRequest) -> dict[str, Any]:
        data = self._load()
        record = data.get(uuid)
        if record is None:
            raise VmNotFound(uuid)
        if req.iso is not None and not self.isos.exists(req.iso):
            raise DriverError(f"Obrazu {req.iso} nie ma jeszcze na tym węźle — poczekaj, aż się pobierze.")
        record["iso"] = req.iso
        record["boot_from_iso"] = bool(req.boot and req.iso)
        if req.restart:
            record["state"] = "running"
        self._save(data)
        return {"uuid": uuid, "iso": req.iso, "boot": record["boot_from_iso"],
                "restarted": req.restart, "state": record["state"]}

    def console_target(self, uuid: str) -> ConsoleTarget:
        """Stacja developerska: terminal-echo zamiast prawdziwej maszyny."""
        self._get(uuid)
        return TerminalTarget(argv=[
            "/bin/sh", "-c", "echo 'Konsola testowa VirtHub (tryb mock).'; exec cat",
        ])

    def health(self) -> HostHealth:
        return HostHealth(
            agent_version=AGENT_VERSION,
            driver="mock",
            virtualization="mock",
            libvirt_connected=False,
            running_vms=sum(1 for r in self._load().values() if r["state"] == "running"),
            **self._host_metrics(),
        )


def build_driver(settings: Settings) -> HypervisorDriver:
    if settings.is_mock:
        log.warning(
            "Agent startuje w trybie MOCK na %s — maszyny nie są realnie tworzone.",
            platform.system(),
        )
        return MockDriver(settings)
    if settings.driver == "lxc":
        from .lxc_driver import IncusDriver

        return IncusDriver(settings)
    return LibvirtDriver(settings)
