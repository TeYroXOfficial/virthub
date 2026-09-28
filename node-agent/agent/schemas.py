"""Kontrakt API agenta — modele wejścia i wyjścia.

Te modele są źródłem prawdy dla specyfikacji OpenAPI w /shared/api-contracts.
Zmiana pola tutaj = zmiana kontraktu z control plane.
"""

from __future__ import annotations

import ipaddress
from enum import Enum
from typing import Annotated, Literal

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


class PortForward(BaseModel):
    """Port zewnętrzny węzła → port usługi w maszynie (TCP i UDP)."""

    external: int = Field(ge=1, le=65535)
    internal: int = Field(ge=1, le=65535)


class NatSpec(BaseModel):
    """Adres prywatny za NAT-em węzła.

    Maszyna stoi na osobnym mostku (bez karty fizycznej), węzeł jest jej bramą
    i wyprowadza ruch na świat swoim adresem. Z zewnątrz maszyna jest osiągalna
    wyłącznie przez przekierowane porty z bloku `port_from`–`port_to`.
    Mapowanie portów ustala panel (`forwards`); starszy panel go nie wysyłał —
    wtedy pierwszy port bloku trafia na SSH (22), a pozostałe przechodzą 1:1.
    """

    network: str = Field(description="Sieć prywatna za mostkiem NAT, np. 10.10.0.0/24")
    snat_address: str | None = Field(
        default=None,
        description="Publiczny adres wyjścia; brak = adres interfejsu wyjściowego węzła",
    )
    port_from: int | None = Field(default=None, ge=1, le=65535)
    port_to: int | None = Field(default=None, ge=1, le=65535)
    forwards: list[PortForward] | None = Field(default=None, max_length=1000)

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
        if self.forwards:
            if self.port_from is None:
                raise ValueError("przekierowania wymagają bloku portów")
            externals = [f.external for f in self.forwards]
            if len(set(externals)) != len(externals):
                raise ValueError("port zewnętrzny może prowadzić tylko do jednej usługi")
            # Węzeł przekierowuje wyłącznie porty z bloku maszyny — inaczej
            # panel mógłby przejąć port innej maszyny albo samego węzła.
            if any(not self.port_from <= p <= self.port_to for p in externals):
                raise ValueError("port zewnętrzny spoza bloku maszyny")
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


# Druga linia obrony (panel sprawdza to samo): wartości trafiają do YAML
# cloud-init, więc znak nowej linii dopisałby do niego dowolne polecenia.
HOSTNAME = r"^([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$"
SSH_KEY = (
    r"^(ssh-(rsa|ed25519|dss)|ecdsa-sha2-nistp(256|384|521)|sk-(ssh-ed25519|ecdsa-sha2-nistp256)@openssh\.com)"
    r" [A-Za-z0-9+/]+={0,3}( [^\x00-\x1f\x7f]{1,200})?$"
)
SshKey = Annotated[str, Field(pattern=SSH_KEY, max_length=1000)]
Password = Annotated[str, Field(pattern=r"^[\x21-\x7e]{8,128}$")]


# Limit czasu procesora w procentach jednego rdzenia (jak `cpulimit` w
# Proxmoksie ×100): 150 = półtora rdzenia, niezależnie od liczby vCPU.
CpuLimit = Annotated[int, Field(ge=1, le=12800)]


class CreateVmRequest(BaseModel):
    server_id: int = Field(description="Identyfikator VPS-a w control plane")
    hostname: str = Field(pattern=HOSTNAME, max_length=253)
    vcpu: int = Field(ge=1, le=128)
    cpu_limit_percent: CpuLimit | None = None
    ram_mb: int = Field(ge=256)
    disk_gb: int = Field(ge=1)
    template: str = Field(description="Nazwa pliku obrazu bazowego w katalogu szablonów")
    interfaces: list[NetworkInterfaceSpec] = Field(default_factory=list)
    ssh_keys: list[SshKey] = Field(default_factory=list, max_length=10)
    root_password: Password | None = Field(default=None, repr=False)
    nameservers: list[str] = Field(default_factory=lambda: ["1.1.1.1", "9.9.9.9"])


class RebuildVmRequest(BaseModel):
    template: str
    hostname: str | None = Field(default=None, pattern=HOSTNAME, max_length=253)
    ssh_keys: list[SshKey] = Field(default_factory=list, max_length=10)
    root_password: Password | None = Field(default=None, repr=False)
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


class PasswordResetRequest(BaseModel):
    """Nowe hasło roota ustawiane w działającym systemie maszyny."""

    # Drukowalne ASCII bez spacji: hasło trafia do chpasswd jako „root:hasło",
    # więc znak nowej linii pozwoliłby dopisać zmianę hasła innego konta.
    password: str = Field(pattern=r"^[\x21-\x7e]{8,128}$")


class ResizeVmRequest(BaseModel):
    vcpu: int = Field(ge=1, le=128)
    ram_mb: int = Field(ge=256)
    disk_gb: int = Field(ge=1, description="Tylko powiększenie — qcow2 nie kurczy się bezpiecznie")
    # Pole pominięte (starszy panel) = limit bez zmian; null = bez limitu.
    cpu_limit_percent: CpuLimit | None = None


class CpuLimitRequest(BaseModel):
    cpu_limit_percent: CpuLimit | None = Field(description="Brak = bez limitu")


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
    # Etap pracy zgłaszany przez driver: prepare, download, stop, image,
    # network, boot, resources, disk. Postęp 0–100, jeśli driver go zna.
    stage: str | None = None
    progress: int | None = None
    detail: str | None = None


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


class GuestOs(BaseModel):
    """System zainstalowany w maszynie — odczytany z jej wnętrza."""

    id: str | None = None
    id_like: str | None = None
    name: str | None = None
    version: str | None = None
    pretty_name: str | None = None
    source: str = Field(description="guest-agent | os-release | template")


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
    cpu_model: str | None = Field(default=None, description="Model procesora hosta z /proc/cpuinfo")
    apps: dict | None = Field(default=None, description="Docker dla aplikacji: dostępność, wersja, liczba aplikacji")
    host_networks: list[dict] | None = Field(
        default=None,
        description="Sieci węzła [{interface, network}] — panel nie pozwoli na pulę NAT nachodzącą na nie",
    )
    public_ipv4: str | None = Field(
        default=None,
        description="Adres, z którego węzeł wychodzi w świat — pod nim klienci łączą się z portami NAT",
    )
    build: str | None = Field(default=None, description="Commit kodu agenta (plik VERSION)")
    firewall_stateful: bool | None = Field(
        default=None, description="Czy zapora śledzi połączenia (moduł nf_conntrack_bridge)",
    )
    security: dict | None = Field(default=None, description="Stan izolacji kontenerów (węzły LXC)")
    remote_update: bool = Field(default=False, description="Czy węzeł przyjmuje aktualizacje zlecane z panelu")


# --- aplikacje (serwery gier, boty) — odpowiednik Wings --------------------------

APP_UUID = r"^[a-f0-9][a-f0-9-]{7,63}$"
# Referencja obrazu Dockera: rejestr/ścieżka:tag albo @sha256:… — bez spacji i
# znaków powłoki, bo trafia też do komunikatów.
DOCKER_IMAGE = r"^[a-z0-9][A-Za-z0-9._/:@-]{0,254}$"
ENV_NAME = r"^[A-Za-z_][A-Za-z0-9_]{0,63}$"
# Ścieżka względem katalogu aplikacji, bez bajtów sterujących. Składowe „.."
# i dowiązania symboliczne odrzuca AppFiles przy przechodzeniu po katalogach.
APP_PATH = r"^[^\x00-\x1f]{0,1024}$"


class AppAllocation(BaseModel):
    """Port węzła przydzielony aplikacji (TCP i UDP, ten sam numer w kontenerze)."""

    port: int = Field(ge=1, le=65535)
    ip: str = "0.0.0.0"

    @field_validator("ip")
    @classmethod
    def _valid_ip(cls, value: str) -> str:
        return str(ipaddress.ip_address(value))


class AppConfigFile(BaseModel):
    """Plik konfiguracyjny, w którym agent ustawia wartości przed startem
    (np. server-port w server.properties) — jak `config.files` w eggach."""

    file: str = Field(pattern=APP_PATH, min_length=1)
    parser: Literal["properties", "file", "ini", "json", "yaml"] = "properties"
    replace: dict[str, str] = Field(default_factory=dict)


class AppInstall(BaseModel):
    """Skrypt instalacyjny eggu — uruchamiany raz, w osobnym kontenerze, jako root."""

    image: str = Field(pattern=DOCKER_IMAGE)
    entrypoint: str = Field(default="bash", pattern=r"^[a-z0-9/_.-]{1,64}$")
    script: str = Field(max_length=200_000)


class AppSpec(BaseModel):
    """Kompletny opis aplikacji — agent tworzy z niego kontener."""

    uuid: str = Field(pattern=APP_UUID)
    image: str = Field(pattern=DOCKER_IMAGE)
    startup: str = Field(max_length=10_000)
    stop: str = Field(default="^C", max_length=200)
    environment: dict[str, str] = Field(default_factory=dict)
    memory_mb: int = Field(ge=64, le=1_048_576)
    swap_mb: int = Field(default=0, ge=0, le=1_048_576)
    cpu_percent: int = Field(default=0, ge=0, le=12_800, description="0 = bez limitu")
    disk_mb: int = Field(default=0, ge=0, description="0 = bez limitu")
    pids_limit: int = Field(default=1024, ge=64, le=65_536)
    # Personel zwolnił aplikację z ochrony przed nadużyciami (fałszywy alarm).
    guard_exempt: bool = False
    # Reinstalacja: co usunąć przed skryptem instalacyjnym — ścieżki,
    # „@world” (świat z level-name w server.properties) albo „*” (wszystko).
    # Nie jest zapisywane w specyfikacji na węźle.
    reinstall_wipe: list[str] = Field(default_factory=list, max_length=50)
    allocations: list[AppAllocation] = Field(default_factory=list, max_length=100)
    config_files: list[AppConfigFile] = Field(default_factory=list, max_length=50)
    install: AppInstall | None = None

    @field_validator("environment")
    @classmethod
    def _valid_env(cls, value: dict[str, str]) -> dict[str, str]:
        import re

        for key, val in value.items():
            if not re.match(ENV_NAME, key):
                raise ValueError(f"Nieprawidłowa nazwa zmiennej: {key}")
            if len(val) > 10_000 or "\x00" in val:
                raise ValueError(f"Wartość zmiennej {key} jest za długa albo zawiera bajt zerowy")
        return value


class AppPowerRequest(BaseModel):
    action: Literal["start", "stop", "restart", "kill"]


class AppCommandRequest(BaseModel):
    command: str = Field(min_length=1, max_length=2000, pattern=r"^[^\x00\r\n]+$")


class AppLogsRequest(BaseModel):
    since: float | None = Field(default=None, description="Znacznik czasu ostatniej linii (unix)")
    tail: int = Field(default=200, ge=1, le=2000)


class AppPathRequest(BaseModel):
    path: str = Field(default="", pattern=APP_PATH)


class AppWriteRequest(BaseModel):
    path: str = Field(pattern=APP_PATH, min_length=1)
    content_base64: str = Field(max_length=70_000_000, description="Najwyżej ~50 MB po zdekodowaniu")


class AppDeleteRequest(BaseModel):
    paths: list[str] = Field(min_length=1, max_length=500)

    @field_validator("paths")
    @classmethod
    def _valid_paths(cls, value: list[str]) -> list[str]:
        import re

        for path in value:
            if not path.strip("/") or not re.match(APP_PATH, path):
                raise ValueError(f"Nieprawidłowa ścieżka: {path!r}")
        return value


class AppRenameRequest(BaseModel):
    source: str = Field(pattern=APP_PATH, min_length=1)
    target: str = Field(pattern=APP_PATH, min_length=1)
