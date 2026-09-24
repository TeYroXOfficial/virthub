"""Kontrakt API agenta — modele wejścia i wyjścia.

Te modele są źródłem prawdy dla specyfikacji OpenAPI w /shared/api-contracts.
Zmiana pola tutaj = zmiana kontraktu z control plane.
"""

from __future__ import annotations

import ipaddress
from enum import Enum
from typing import Literal

from pydantic import BaseModel, Field, field_validator, model_validator


class PowerAction(str, Enum):
    START = "start"
    STOP = "stop"          # ACPI shutdown, łagodne
    REBOOT = "reboot"
    FORCE_OFF = "force-off"  # odcięcie zasilania


class JobStatus(str, Enum):
    QUEUED = "queued"
    RUNNING = "running"
    DONE = "done"
    FAILED = "failed"


class NatSpec(BaseModel):
    """Adres prywatny za NAT-em węzła.

    Maszyna stoi na osobnym mostku (bez karty fizycznej), węzeł jest jej bramą
    i wyprowadza ruch na świat swoim adresem. Z zewnątrz maszyna jest osiągalna
    wyłącznie przez przekierowane porty: pierwszy port bloku trafia na SSH
    (22), pozostałe przechodzą 1:1 — klient uruchamia usługi na tych numerach.
    """

    network: str = Field(description="Sieć prywatna za mostkiem NAT, np. 10.10.0.0/24")
    snat_address: str | None = Field(
        default=None,
        description="Publiczny adres wyjścia; brak = adres interfejsu wyjściowego węzła",
    )
    port_from: int | None = Field(default=None, ge=1, le=65535)
    port_to: int | None = Field(default=None, ge=1, le=65535)

    @field_validator("network")
    @classmethod
    def _valid_network(cls, value: str) -> str:
        # Trafia do skryptu nftables i do `ip addr` — tylko poprawna sieć.
        return str(ipaddress.ip_network(value, strict=False))

    @field_validator("snat_address")
    @classmethod
    def _valid_snat(cls, value: str | None) -> str | None:
        return None if value is None else str(ipaddress.ip_address(value))

    @model_validator(mode="after")
    def _valid_ports(self) -> "NatSpec":
        if (self.port_from is None) != (self.port_to is None):
            raise ValueError("port_from i port_to podaje się razem albo wcale")
        if self.port_from is not None and self.port_to < self.port_from:
            raise ValueError("port_to nie może być mniejszy niż port_from")
        return self

    @property
    def ip_network(self) -> ipaddress.IPv4Network | ipaddress.IPv6Network:
        return ipaddress.ip_network(self.network)


class NetworkInterfaceSpec(BaseModel):
    """Jeden adres przypisany do VPS-a — używany też do reguł anty-spoofingowych."""

    address: str = Field(description="Adres IP bez maski, np. 203.0.113.14")
    prefix: int = Field(ge=0, le=128, description="Długość prefiksu CIDR")
    gateway: str | None = None
    version: Literal[4, 6] = 4
    mode: Literal["bridged", "nat"] = Field(
        default="bridged",
        description="bridged — adres publiczny na mostku z kartą fizyczną; nat — adres prywatny za NAT-em węzła",
    )
    nat: NatSpec | None = None

    @field_validator("address")
    @classmethod
    def _valid_address(cls, value: str) -> str:
        # Adres trafia wprost do reguł nftables — nic poza poprawnym IP.
        return str(ipaddress.ip_address(value))

    @field_validator("gateway")
    @classmethod
    def _valid_gateway(cls, value: str | None) -> str | None:
        return None if not value else str(ipaddress.ip_address(value))

    @model_validator(mode="after")
    def _nat_requires_details(self) -> "NetworkInterfaceSpec":
        if self.mode == "nat":
            if self.nat is None or not self.gateway:
                raise ValueError("Adres NAT wymaga sekcji nat i bramy (adresu węzła na mostku NAT)")
            network = self.nat.ip_network
            for label, value in (("adres", self.address), ("brama", self.gateway)):
                if ipaddress.ip_address(value) not in network:
                    raise ValueError(f"{label} {value} leży poza siecią NAT {network}")
        return self


class FirewallRule(BaseModel):
    action: Literal["accept", "drop"] = "accept"
    direction: Literal["in", "out"] = "in"
    protocol: Literal["tcp", "udp", "icmp", "any"] = "tcp"
    port_from: int | None = Field(default=None, ge=1, le=65535)
    port_to: int | None = Field(default=None, ge=1, le=65535)
    source: str | None = Field(
        default=None,
        description="Adres drugiej strony (IP albo CIDR): źródło dla ruchu przychodzącego, "
                    "cel dla wychodzącego. Brak = dowolny.",
    )

    @field_validator("source")
    @classmethod
    def _valid_source(cls, value: str | None) -> str | None:
        # Trafia wprost do skryptu nftables — tylko poprawny adres lub sieć.
        if value is None or value == "":
            return None
        return str(ipaddress.ip_network(value.strip(), strict=False))

    @model_validator(mode="after")
    def _valid_ports(self) -> "FirewallRule":
        if self.port_to is not None and self.port_from is None:
            raise ValueError("port_to wymaga port_from")
        if self.port_from is not None and self.port_to is not None and self.port_to < self.port_from:
            raise ValueError("port_to nie może być mniejszy niż port_from")
        if self.port_from is not None and self.protocol not in ("tcp", "udp"):
            raise ValueError("Porty mają sens tylko dla TCP i UDP")
        return self


class FirewallPolicy(BaseModel):
    """Zapora maszyny jako całość — reguły plus to, co dzieje się z resztą ruchu."""

    enabled: bool = True
    inbound: Literal["accept", "drop"] = "accept"
    outbound: Literal["accept", "drop"] = "accept"


class CreateVmRequest(BaseModel):
    server_id: int = Field(description="Identyfikator VPS-a w control plane")
    hostname: str
    vcpu: int = Field(ge=1, le=128)
    ram_mb: int = Field(ge=256)
    disk_gb: int = Field(ge=1)
    template: str = Field(description="Nazwa pliku obrazu bazowego w katalogu szablonów")
    interfaces: list[NetworkInterfaceSpec] = Field(default_factory=list)
    ssh_keys: list[str] = Field(default_factory=list)
    root_password: str | None = Field(default=None, repr=False)
    nameservers: list[str] = Field(default_factory=lambda: ["1.1.1.1", "9.9.9.9"])


class RebuildVmRequest(BaseModel):
    template: str
    hostname: str | None = None
    ssh_keys: list[str] = Field(default_factory=list)
    root_password: str | None = Field(default=None, repr=False)
    # Adresacja dla cloud-init nowego systemu. Starszy panel jej nie wysyłał —
    # wtedy maszyna KVM stawała bez sieci; kontener bierze ją ze swojej konfiguracji.
    interfaces: list[NetworkInterfaceSpec] | None = None
    nameservers: list[str] | None = None


ISO_NAME = r"^[a-z0-9][a-z0-9._-]{0,80}\.iso$"


class IsoDownloadRequest(BaseModel):
    """Pobranie obrazu ISO do biblioteki węzła (katalog VH_ISO_DIR)."""

    name: str = Field(pattern=ISO_NAME, description="Nazwa pliku na węźle, np. debian-13-netinst.iso")
    url: str = Field(pattern=r"^https?://[^\s]{3,2000}$")
    sha256: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{64}$")


class IsoMountRequest(BaseModel):
    """Płyta w wirtualnym napędzie maszyny KVM i kolejność rozruchu."""

    iso: str | None = Field(default=None, pattern=ISO_NAME, description="Brak = wysuń płytę")
    boot: bool = Field(default=False, description="Uruchamiaj z płyty przed dyskiem")
    restart: bool = Field(default=False, description="Zastosuj od razu (wyłącz i włącz maszynę)")


class ResizeVmRequest(BaseModel):
    vcpu: int = Field(ge=1, le=128)
    ram_mb: int = Field(ge=256)
    disk_gb: int = Field(ge=1, description="Tylko powiększenie — qcow2 nie kurczy się bezpiecznie")


class PowerRequest(BaseModel):
    action: PowerAction


class NetworkConfigRequest(BaseModel):
    interfaces: list[NetworkInterfaceSpec]
    firewall: list[FirewallRule] = Field(default_factory=list)
    # Brak polityki (starszy panel) = dawne zachowanie: reguły bez domyślnej blokady.
    policy: FirewallPolicy | None = None


class SnapshotRequest(BaseModel):
    name: str = Field(pattern=r"^[a-zA-Z0-9_.-]{1,64}$")


class ImagePrefetchRequest(BaseModel):
    """Pobranie szablonu kontenera na węzeł, zanim ktoś go zamówi — pierwsze
    zamówienie nie czeka wtedy kilku minut na ściągnięcie obrazu."""

    alias: str = Field(
        pattern=r"^[a-z0-9][a-z0-9._-]*(/[a-z0-9._-]+){0,3}$",
        description="Alias obrazu na serwerze obrazów, np. debian/12/cloud",
    )


class JobAccepted(BaseModel):
    """Odpowiedź na każdą operację modyfikującą — agent pracuje asynchronicznie."""

    job_id: str
    status: JobStatus = JobStatus.QUEUED


class JobState(BaseModel):
    job_id: str
    action: str
    uuid: str | None = None
    server_id: int | None = None
    status: JobStatus
    result: dict | None = None
    error: str | None = None
    created_at: float
    finished_at: float | None = None


class VmStats(BaseModel):
    uuid: str
    state: str
    cpu_time_ns: int = 0
    cpu_percent: float = 0.0
    ram_used_mb: int = 0
    ram_total_mb: int = 0
    disk_read_bytes: int = 0
    disk_write_bytes: int = 0
    net_rx_bytes: int = 0
    net_tx_bytes: int = 0


class HostHealth(BaseModel):
    """Heartbeat — control plane używa tego do doboru node'a pod nowy VPS."""

    agent_version: str
    driver: str
    virtualization: str = "kvm"  # kvm | lxc | mock
    hostname: str
    libvirt_connected: bool
    cpu_cores_total: int
    cpu_load_1m: float
    ram_mb_total: int
    ram_mb_free: int
    disk_gb_total: int
    disk_gb_free: int
    running_vms: int
    build: str | None = Field(default=None, description="Commit kodu agenta (plik VERSION)")
    firewall_stateful: bool | None = Field(
        default=None, description="Czy zapora śledzi połączenia (moduł nf_conntrack_bridge)",
    )
    remote_update: bool = Field(default=False, description="Czy węzeł przyjmuje aktualizacje zlecane z panelu")
