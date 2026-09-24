"""Sterownik kontenerów LXC przez Incus.

Dla węzłów bez sprzętowego wsparcia wirtualizacji (VT-x/AMD-V) — typowo VPS-ów
bez zagnieżdżonej wirtualizacji. Kontener nie potrzebuje KVM: dzieli jądro z
hostem, a izolację zapewniają przestrzenie nazw i cgroups.

Dlaczego Incus, a nie gołe LXC:

* ma wbudowany serwer obrazów (images.linuxcontainers.org) — szablon pobiera
  się sam przy pierwszym użyciu, bez ręcznego ściągania archiwów,
* egzekwuje limity CPU, pamięci i dysku (quota na puli btrfs),
* rozumie cloud-init, więc hasło, klucze SSH i adresacja kontenera powstają
  z tych samych funkcji co dla maszyn KVM.

Kontener dostaje interfejs `vh{id}` w mostku i MAC z tej samej puli co maszyny
KVM, więc reguły anty-spoofingu i firewalla (network.py) działają bez zmian.
"""

from __future__ import annotations

import json
import logging
import re
import uuid as uuidlib
from typing import Any

from .cloudinit import render_network_config, render_user_data
from .config import Settings
from .domain_xml import domain_name
from .driver import DriverError, HypervisorDriver, VmNotFound, server_id_from_name, AGENT_VERSION
from .network import interface_name, mac_address
from .schemas import (
    CreateVmRequest,
    HostHealth,
    NetworkConfigRequest,
    PowerAction,
    RebuildVmRequest,
    ResizeVmRequest,
    VmStats,
)
from .shell import CommandError, run

log = logging.getLogger("virthub.lxc")

ALIAS_RE = re.compile(r"^[a-z0-9][a-z0-9._-]*(/[a-z0-9._-]+){0,3}$")

# Klucz w konfiguracji kontenera, pod którym trzymamy identyfikator nadany
# przez panel. Kontener nie ma UUID-u w sensie libvirt, a API agenta operuje
# na UUID-ach — ten klucz je spina.
UUID_KEY = "user.virthub.uuid"

# Obrazy kontenerów nie zawierają serwera SSH (w odróżnieniu od obrazów cloud
# dla maszyn wirtualnych). Bez niego klient nie miałby jak się zalogować.
CONTAINER_PACKAGES = ["openssh-server"]


class IncusDriver(HypervisorDriver):
    """Kontenery LXC zarządzane przez Incus."""

    # Stany Incusa → słownik rozumiany przez panel (ten sam co dla libvirt).
    _STATES = {
        "running": "running",
        "stopped": "stopped",
        "frozen": "paused",
        "error": "crashed",
    }

    def __init__(self, settings: Settings):
        super().__init__(settings)
        self.pool = settings.incus_storage_pool
        self.remote = settings.incus_image_remote

    # --- dostęp do Incusa ---------------------------------------------------

    def _incus(self, *args: str, timeout: int = 300) -> str:
        try:
            return run(["incus", *args], timeout=timeout)
        except CommandError as exc:
            raise DriverError(f"Incus odrzucił operację: {exc.stderr or exc}") from exc

    def _query(self, path: str) -> Any:
        return json.loads(self._incus("query", path, timeout=30) or "null")

    def _instances(self) -> list[dict]:
        raw = self._incus("list", "--format", "json", timeout=60)
        return json.loads(raw or "[]")

    def _name_for(self, uuid: str) -> str:
        for instance in self._instances():
            if (instance.get("config") or {}).get(UUID_KEY) == uuid:
                return instance["name"]
        raise VmNotFound(uuid)

    def _state(self, name: str) -> str:
        state = self._query(f"/1.0/instances/{name}/state") or {}
        return self._STATES.get(str(state.get("status", "")).lower(), "unknown")

    # --- obrazy -------------------------------------------------------------

    @staticmethod
    def _validate_alias(alias: str) -> str:
        # Alias idzie jako argument polecenia. Lista argumentów chroni przed
        # wstrzyknięciem powłoki, ale nie przed zdalnym aliasem w rodzaju
        # „innyserwer:obraz" — dlatego dopuszczamy wyłącznie ścieżkę aliasu.
        if not ALIAS_RE.match(alias):
            raise DriverError(
                f"Nieprawidłowy alias szablonu: {alias!r}. Oczekiwany format "
                f"np. debian/12/cloud."
            )
        return alias

    def _has_local_image(self, alias: str) -> bool:
        try:
            run(["incus", "image", "info", f"local:{alias}"], timeout=30)
            return True
        except CommandError:
            return False

    def ensure_image(self, alias: str) -> bool:
        """Sprowadza obraz na węzeł, jeśli jeszcze go nie ma. Zwraca True, gdy
        faktycznie pobierał — przydaje się w logach, bo pierwsze pobranie
        trwa minuty, a kolejne użycia są natychmiastowe."""
        alias = self._validate_alias(alias)
        if self._has_local_image(alias):
            return False

        log.info("Pobieram obraz %s:%s", self.remote, alias)
        # --auto-update: Incus sam odświeża obraz, gdy dystrybucja wyda
        # poprawki. Nowe kontenery nie startują od miesięcy starych pakietów.
        self._incus(
            "image", "copy", f"{self.remote}:{alias}", "local:",
            "--alias", alias, "--auto-update",
            timeout=1800,
        )
        return True

    def prefetch_image(self, alias: str) -> dict[str, Any]:
        downloaded = self.ensure_image(alias)
        return {"alias": alias, "downloaded": downloaded, "cached": not downloaded}

    # --- cykl życia ---------------------------------------------------------

    def _cloud_init_args(
        self,
        hostname: str,
        interfaces,
        nameservers: list[str],
        ssh_keys: list[str],
        root_password: str | None,
        mac: str,
    ) -> list[str]:
        user_data = render_user_data(
            hostname, ssh_keys, root_password, packages=CONTAINER_PACKAGES
        )
        network_config = render_network_config(interfaces, nameservers, mac)
        return [
            "-c", f"cloud-init.user-data={user_data}",
            "-c", f"cloud-init.network-config={network_config}",
        ]

    def create_vm(self, req: CreateVmRequest) -> dict[str, Any]:
        name = domain_name(req.server_id)
        vm_uuid = str(uuidlib.uuid4())
        mac = mac_address(req.server_id)
        target = interface_name(req.server_id)
        alias = self._validate_alias(req.template)

        self.ensure_image(alias)

        created: list[str] = []
        try:
            self._incus(
                "init", f"local:{alias}", name,
                "--storage", self.pool,
                "-c", f"limits.cpu={req.vcpu}",
                "-c", f"limits.memory={req.ram_mb}MiB",
                "-c", f"{UUID_KEY}={vm_uuid}",
                "-c", f"user.virthub.server-id={req.server_id}",
                # Nieuprzywilejowany kontener: root w środku nie jest rootem
                # hosta. To domyślne w Incusie — ustawiamy jawnie, żeby zmiana
                # domyślnego profilu przez kogoś nie otworzyła hosta klientom.
                "-c", "security.privileged=false",
                *self._cloud_init_args(
                    req.hostname, req.interfaces, req.nameservers,
                    req.ssh_keys, req.root_password, mac,
                ),
                "-d", f"root,size={req.disk_gb}GiB",
                timeout=600,
            )
            created.append("instance")

            # Interfejs z tą samą nazwą i MAC-iem co maszyny KVM — reguły
            # firewalla i anty-spoofingu działają przez to bez zmian.
            self._incus(
                "config", "device", "add", name, "eth0", "nic",
                "nictype=bridged",
                f"parent={self.settings.bridge}",
                f"host_name={target}",
                f"hwaddr={mac}",
            )

            self.network.configure(req.server_id, req.interfaces, firewall=[])
            created.append("network")

            self._incus("start", name, timeout=120)
        except Exception as exc:
            log.error("Tworzenie kontenera %s nieudane (%s) — sprzątam", req.server_id, exc)
            self._rollback(req.server_id, name, created)
            raise

        return {
            "uuid": vm_uuid,
            "name": name,
            "mac": mac,
            "interface": target,
            # Kontener nie ma wirtualnej karty graficznej — konsola jest
            # tekstowa (incus console), bez VNC.
            "vnc_port": None,
            "vnc_password": None,
            "state": self._state(name),
            "virtualization": "lxc",
        }

    def _rollback(self, server_id: int, name: str, created: list[str]) -> None:
        if "network" in created:
            try:
                self.network.teardown(server_id)
            except Exception:
                log.exception("Rollback: nie udało się usunąć reguł sieciowych")
        if "instance" in created:
            try:
                run(["incus", "delete", name, "--force"], timeout=120)
            except CommandError:
                log.exception("Rollback: nie udało się usunąć kontenera %s", name)

    def power(self, uuid: str, action: PowerAction) -> dict[str, Any]:
        name = self._name_for(uuid)
        running = self._state(name) == "running"

        if action is PowerAction.START and not running:
            self._incus("start", name, timeout=120)
        elif action is PowerAction.STOP and running:
            # Łagodne zamknięcie: system w kontenerze dostaje sygnał i ma
            # minutę na zapisanie danych.
            self._incus("stop", name, "--timeout", "60", timeout=90)
        elif action is PowerAction.REBOOT:
            if running:
                self._incus("restart", name, "--timeout", "60", timeout=90)
            else:
                self._incus("start", name, timeout=120)
        elif action is PowerAction.FORCE_OFF and running:
            self._incus("stop", name, "--force", timeout=60)

        return {"uuid": uuid, "state": self._state(name)}

    def rebuild(self, uuid: str, req: RebuildVmRequest) -> dict[str, Any]:
        name = self._name_for(uuid)
        server_id = server_id_from_name(name)
        alias = self._validate_alias(req.template)

        self.ensure_image(alias)

        if self._state(name) == "running":
            self._incus("stop", name, "--force", timeout=60)

        # rebuild podmienia system plików na świeży z obrazu, zostawiając
        # konfigurację kontenera: limity, interfejs, MAC i nasz UUID.
        self._incus("rebuild", f"local:{alias}", name, timeout=900)

        # Nowa konfiguracja cloud-init zmienia identyfikator instancji, więc
        # cloud-init w świeżym systemie wykona się od nowa — z nowym hasłem.
        interfaces = self._current_interfaces(name)
        user_data = render_user_data(
            req.hostname or name, req.ssh_keys, req.root_password,
            packages=CONTAINER_PACKAGES,
        )
        self._incus("config", "set", name, f"cloud-init.user-data={user_data}")
        if interfaces is not None:
            self._incus("config", "set", name, f"cloud-init.network-config={interfaces}")

        self._incus("start", name, timeout=120)
        return {"uuid": uuid, "state": self._state(name), "template": alias}

    def _current_interfaces(self, name: str) -> str | None:
        """Adresacja zostaje ta sama co przed przebudową — bierzemy ją z
        aktualnej konfiguracji kontenera."""
        instance = self._query(f"/1.0/instances/{name}") or {}
        return (instance.get("config") or {}).get("cloud-init.network-config")

    def resize(self, uuid: str, req: ResizeVmRequest) -> dict[str, Any]:
        name = self._name_for(uuid)
        instance = self._query(f"/1.0/instances/{name}") or {}
        current = ((instance.get("devices") or {}).get("root") or {}).get("size", "")
        current_gb = _parse_gib(current)

        if current_gb is not None and req.disk_gb < current_gb:
            raise DriverError(
                f"Zmniejszenie dysku z {current_gb} GB do {req.disk_gb} GB nie jest "
                f"wspierane — dane klienta mogłyby się nie zmieścić."
            )

        self._incus(
            "config", "set", name,
            f"limits.cpu={req.vcpu}",
            f"limits.memory={req.ram_mb}MiB",
        )
        self._incus("config", "device", "set", name, "root", f"size={req.disk_gb}GiB")
        return {"uuid": uuid, "vcpu": req.vcpu, "ram_mb": req.ram_mb, "disk_gb": req.disk_gb}

    def delete(self, uuid: str) -> dict[str, Any]:
        name = self._name_for(uuid)
        server_id = server_id_from_name(name)
        self._incus("delete", name, "--force", timeout=120)
        self.network.teardown(server_id)
        return {"uuid": uuid, "deleted": True}

    # --- snapshoty ----------------------------------------------------------

    def snapshot(self, uuid: str, name: str) -> dict[str, Any]:
        instance = self._name_for(uuid)
        # Kontener nie trzeba zatrzymywać: snapshot systemu plików na btrfs
        # jest natychmiastowy i spójny na poziomie bloku.
        self._incus("snapshot", "create", instance, name, timeout=600)
        return {"uuid": uuid, "snapshot": name, "path": f"{instance}/{name}"}

    def restore(self, uuid: str, name: str) -> dict[str, Any]:
        instance = self._name_for(uuid)
        self._incus("snapshot", "restore", instance, name, timeout=600)
        if self._state(instance) != "running":
            self._incus("start", instance, timeout=120)
        return {"uuid": uuid, "snapshot": name, "state": self._state(instance)}

    # --- sieć ---------------------------------------------------------------

    def configure_network(self, uuid: str, req: NetworkConfigRequest) -> dict[str, Any]:
        name = self._name_for(uuid)
        self.network.configure(server_id_from_name(name), req.interfaces, req.firewall)
        return {
            "uuid": uuid,
            "interfaces": len(req.interfaces),
            "firewall_rules": len(req.firewall),
        }

    # --- telemetria ---------------------------------------------------------

    def stats(self, uuid: str) -> VmStats:
        name = self._name_for(uuid)
        state = self._query(f"/1.0/instances/{name}/state") or {}
        instance = self._query(f"/1.0/instances/{name}") or {}

        status = self._STATES.get(str(state.get("status", "")).lower(), "unknown")
        if status != "running":
            return VmStats(uuid=uuid, state=status)

        memory = state.get("memory") or {}
        network = (state.get("network") or {}).get("eth0") or {}
        counters = network.get("counters") or {}
        limit_mb = _parse_mib((instance.get("config") or {}).get("limits.memory", ""))

        return VmStats(
            uuid=uuid,
            state=status,
            # Incus podaje czas procesora narastająco w nanosekundach — tak
            # samo jak libvirt, więc panel liczy procent tą samą metodą.
            cpu_time_ns=int((state.get("cpu") or {}).get("usage", 0)),
            ram_used_mb=int(memory.get("usage", 0)) // (1024**2),
            ram_total_mb=limit_mb or int(memory.get("total", 0)) // (1024**2),
            net_rx_bytes=int(counters.get("bytes_received", 0)),
            net_tx_bytes=int(counters.get("bytes_sent", 0)),
        )

    def health(self) -> HostHealth:
        metrics = self._host_metrics()
        connected = True
        running = 0

        try:
            running = sum(
                1 for i in self._instances()
                if str(i.get("status", "")).lower() == "running"
            )
            # Pojemność dysku to pula Incusa, a nie katalog agenta —
            # kontenery lądują w puli.
            resources = self._query(f"/1.0/storage-pools/{self.pool}/resources") or {}
            space = resources.get("space") or {}
            if space.get("total"):
                metrics["disk_gb_total"] = int(space["total"]) // (1024**3)
                metrics["disk_gb_free"] = (
                    int(space["total"]) - int(space.get("used", 0))
                ) // (1024**3)
        except (DriverError, ValueError):
            connected = False

        return HostHealth(
            agent_version=AGENT_VERSION,
            driver="lxc",
            virtualization="lxc",
            libvirt_connected=connected,
            running_vms=running,
            **metrics,
        )


def _parse_gib(value: str) -> int | None:
    """'20GiB' → 20, '20GB' → 20. Brak albo inny format → None."""
    match = re.match(r"^\s*(\d+)\s*(GiB|GB)\s*$", value or "")
    return int(match.group(1)) if match else None


def _parse_mib(value: str) -> int | None:
    """'2048MiB' → 2048, '2GiB' → 2048."""
    match = re.match(r"^\s*(\d+)\s*(MiB|MB|GiB|GB)\s*$", value or "")
    if not match:
        return None
    amount, unit = int(match.group(1)), match.group(2)
    return amount * 1024 if unit in {"GiB", "GB"} else amount
