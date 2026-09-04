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
import platform
import secrets
import socket
import uuid as uuidlib
from abc import ABC, abstractmethod
from pathlib import Path
from typing import Any

from .cloudinit import CloudInitBuilder
from .config import Settings
from .domain_xml import build_domain_xml, domain_name
from .network import NetworkManager, interface_name, mac_address
from .schemas import (
    CreateVmRequest,
    HostHealth,
    NetworkConfigRequest,
    PowerAction,
    RebuildVmRequest,
    ResizeVmRequest,
    VmStats,
)
from .storage import build_storage_driver

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


class HypervisorDriver(ABC):
    def __init__(self, settings: Settings):
        self.settings = settings
        self.storage = build_storage_driver(settings)
        self.network = NetworkManager(settings)
        self.cloudinit = CloudInitBuilder(settings)

    @abstractmethod
    def create_vm(self, req: CreateVmRequest) -> dict[str, Any]: ...

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

    # --- wspólne dla obu implementacji --------------------------------------

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

    def create_vm(self, req: CreateVmRequest) -> dict[str, Any]:
        name = domain_name(req.server_id)
        vm_uuid = str(uuidlib.uuid4())
        mac = mac_address(req.server_id)
        target = interface_name(req.server_id)
        vnc_password = secrets.token_urlsafe(12)

        created: list[str] = []
        try:
            disk = self.storage.create_volume(name, req.template, req.disk_gb)
            created.append("volume")

            seed = self.cloudinit.build(
                name=name,
                server_id=req.server_id,
                hostname=req.hostname,
                interfaces=req.interfaces,
                nameservers=req.nameservers,
                ssh_keys=req.ssh_keys,
                root_password=req.root_password,
                mac=mac,
            )
            created.append("seed")

            xml = build_domain_xml(
                name=name,
                uuid=vm_uuid,
                vcpu=req.vcpu,
                ram_mb=req.ram_mb,
                disk_path=str(disk),
                seed_path=str(seed),
                bridge=self.settings.bridge,
                mac=mac,
                interface_target=target,
                vnc_listen=self.settings.vnc_listen,
                vnc_password=vnc_password,
            )

            domain = self.conn.defineXML(xml)
            created.append("domain")

            self.network.configure(req.server_id, req.interfaces, firewall=[])
            created.append("network")

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
                dom.undefine()
            except libvirt.libvirtError:
                log.exception("Rollback: nie udało się usunąć domeny %s", name)
        if "volume" in created or "seed" in created:
            try:
                self.storage.delete_volume(name)
            except Exception:
                log.exception("Rollback: nie udało się usunąć dysku %s", name)

    def power(self, uuid: str, action: PowerAction) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        active = domain.isActive()

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
        server_id = server_id_from_name(name)
        was_running = bool(domain.isActive())

        if was_running:
            domain.destroy()

        interfaces = self._interfaces_from_domain(domain)
        disk_gb = self._disk_size_gb(name)

        self.storage.delete_volume(name)
        self.storage.create_volume(name, req.template, disk_gb)
        self.cloudinit.build(
            name=name,
            server_id=server_id,
            hostname=req.hostname or name,
            interfaces=interfaces,
            nameservers=["1.1.1.1", "9.9.9.9"],
            ssh_keys=req.ssh_keys,
            root_password=req.root_password,
            mac=mac_address(server_id),
        )

        domain.create()
        return {"uuid": uuid, "state": self._state(domain), "template": req.template}

    def resize(self, uuid: str, req: ResizeVmRequest) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        if domain.isActive():
            raise DriverError(
                "Zmiana pakietu wymaga zatrzymanej maszyny — zatrzymaj VPS i ponów operację."
            )

        name = domain.name()
        self.storage.resize_volume(name, req.disk_gb)

        try:
            domain.setMemoryFlags(req.ram_mb * 1024, libvirt.VIR_DOMAIN_AFFECT_CONFIG)
            domain.setMaxMemory(req.ram_mb * 1024)
            domain.setVcpusFlags(req.vcpu, libvirt.VIR_DOMAIN_AFFECT_CONFIG
                                 | libvirt.VIR_DOMAIN_VCPU_MAXIMUM)
            domain.setVcpusFlags(req.vcpu, libvirt.VIR_DOMAIN_AFFECT_CONFIG)
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się zmienić zasobów maszyny: {exc}") from exc

        return {"uuid": uuid, "vcpu": req.vcpu, "ram_mb": req.ram_mb, "disk_gb": req.disk_gb}

    def delete(self, uuid: str) -> dict[str, Any]:
        import libvirt

        domain = self._domain(uuid)
        name = domain.name()
        server_id = server_id_from_name(name)

        if domain.isActive():
            domain.destroy()

        try:
            domain.undefine()
        except libvirt.libvirtError as exc:
            raise DriverError(f"Nie udało się usunąć definicji domeny: {exc}") from exc

        self.network.teardown(server_id)
        self.storage.delete_volume(name)
        return {"uuid": uuid, "deleted": True}

    # --- snapshoty ----------------------------------------------------------

    def snapshot(self, uuid: str, name: str) -> dict[str, Any]:
        domain = self._domain(uuid)
        if domain.isActive():
            raise DriverError(
                "Snapshot spójny dyskowo wymaga zatrzymanej maszyny. Zatrzymaj VPS "
                "albo poczekaj na wsparcie snapshotów live (planowane z QEMU guest agent)."
            )
        path = self.storage.snapshot_volume(domain.name(), name)
        return {"uuid": uuid, "snapshot": name, "path": str(path)}

    def restore(self, uuid: str, name: str) -> dict[str, Any]:
        domain = self._domain(uuid)
        if domain.isActive():
            domain.destroy()
        self.storage.restore_volume(domain.name(), name)
        domain.create()
        return {"uuid": uuid, "snapshot": name, "state": self._state(domain)}

    # --- sieć ---------------------------------------------------------------

    def configure_network(self, uuid: str, req: NetworkConfigRequest) -> dict[str, Any]:
        domain = self._domain(uuid)
        server_id = server_id_from_name(domain.name())
        self.network.configure(server_id, req.interfaces, req.firewall)
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

    def health(self) -> HostHealth:
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
            libvirt_connected=connected,
            running_vms=running,
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
        import json as _json

        from .shell import run

        info = _json.loads(
            run(["qemu-img", "info", "--output=json", str(self.storage.volume_path(name))])
        )
        return int(info["virtual-size"]) // (1024**3)


class MockDriver(HypervisorDriver):
    """Stan trzymany w pliku JSON. Pozwala rozwijać panel bez hypervisora."""

    def __init__(self, settings: Settings):
        super().__init__(settings)
        self._state_file: Path = settings.state_db.parent / "mock-domains.json"

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

    def create_vm(self, req: CreateVmRequest) -> dict[str, Any]:
        name = domain_name(req.server_id)
        vm_uuid = str(uuidlib.uuid4())
        disk = self.storage.create_volume(name, req.template, req.disk_gb)
        self.cloudinit.build(
            name=name,
            server_id=req.server_id,
            hostname=req.hostname,
            interfaces=req.interfaces,
            nameservers=req.nameservers,
            ssh_keys=req.ssh_keys,
            root_password=req.root_password,
            mac=mac_address(req.server_id),
        )

        record = {
            "uuid": vm_uuid,
            "name": name,
            "server_id": req.server_id,
            "state": "running",
            "vcpu": req.vcpu,
            "ram_mb": req.ram_mb,
            "disk_gb": req.disk_gb,
            "template": req.template,
            "mac": mac_address(req.server_id),
            "interface": interface_name(req.server_id),
            "vnc_port": 5900 + (req.server_id % 100),
            "vnc_password": secrets.token_urlsafe(12),
            "disk_path": str(disk),
        }
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
        record["template"] = req.template
        record["state"] = "running"
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
        self._save(data)
        return {"uuid": uuid, **{k: record[k] for k in ("vcpu", "ram_mb", "disk_gb")}}

    def delete(self, uuid: str) -> dict[str, Any]:
        data = self._load()
        record = data.pop(uuid, None)
        if record is None:
            raise VmNotFound(uuid)
        self.storage.delete_volume(record["name"])
        self._save(data)
        return {"uuid": uuid, "deleted": True}

    def snapshot(self, uuid: str, name: str) -> dict[str, Any]:
        record = self._get(uuid)
        path = self.storage.snapshot_volume(record["name"], name)
        return {"uuid": uuid, "snapshot": name, "path": str(path)}

    def restore(self, uuid: str, name: str) -> dict[str, Any]:
        record = self._get(uuid)
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
        # Deterministyczne, prawdopodobne wartości — panel ma co rysować.
        seed = record["server_id"]
        return VmStats(
            uuid=uuid,
            state=record["state"],
            cpu_percent=round((seed * 7) % 60 + 5.0, 2),
            ram_used_mb=int(record["ram_mb"] * 0.42),
            ram_total_mb=record["ram_mb"],
            disk_read_bytes=seed * 1024 * 1024,
            disk_write_bytes=seed * 512 * 1024,
            net_rx_bytes=seed * 2048 * 1024,
            net_tx_bytes=seed * 1024 * 1024,
        )

    def health(self) -> HostHealth:
        return HostHealth(
            agent_version=AGENT_VERSION,
            driver="mock",
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
    return LibvirtDriver(settings)
