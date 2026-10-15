"""Kontrakt API agenta — modele wejścia i wyjścia.

Te modele są źródłem prawdy dla specyfikacji OpenAPI w /shared/api-contracts.
Zmiana pola tutaj = zmiana kontraktu z control plane.
"""

from __future__ import annotations

import ipaddress
import re
from enum import Enum
from typing import Annotated, Any, Literal

from pydantic import AfterValidator, BaseModel, Field, field_validator, model_validator


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
    mode: Literal["bridged", "nat", "routed"] = Field(
        default="bridged",
        description="bridged — adres publiczny na mostku z kartą fizyczną; nat — adres prywatny za NAT-em węzła; "
                    "routed — adres z podsieci routowanej do węzła (/32, brama on-link)",
    )
    nat: NatSpec | None = None
    # Tag 802.1Q: maszyna na mostku vhv<tag> nad <łącze>.<tag> (tylko bridged).
    vlan: int | None = Field(default=None, ge=1, le=4094)

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


class HardwareSpec(BaseModel):
    """Sprzęt wirtualny maszyny KVM. Brak pola = wartość domyślna (jak w starszych wersjach)."""

    disk_bus: Literal["virtio", "scsi"] = Field(default="virtio", description="virtio-blk albo VirtIO SCSI (wielowątkowy kontroler)")
    io_mode: Literal["native", "threads", "io_uring"] = "native"
    firmware: Literal["bios", "uefi"] = "bios"
    tpm: bool = Field(default=False, description="vTPM 2.0 (swtpm) — wymaga UEFI")
    # None = host-passthrough (pełny procesor hosta); host-model albo nazwa modelu QEMU (np. EPYC-Milan).
    cpu_model: str | None = Field(default=None, pattern=r"^[A-Za-z0-9][A-Za-z0-9_.-]{0,40}$")
    cpuset: str | None = Field(default=None, pattern=r"^[0-9]+(-[0-9]+)?(,\^?[0-9]+(-[0-9]+)?){0,99}$",
                               description="Rdzenie hosta dla vCPU, np. 0-15,32-47")

    @model_validator(mode="after")
    def _tpm_needs_uefi(self) -> "HardwareSpec":
        if self.tpm and self.firmware != "uefi":
            raise ValueError("vTPM wymaga rozruchu UEFI")
        return self


class ExtraDiskSpec(BaseModel):
    """Dodatkowy dysk maszyny: slot 1–7 → vdb–vdh."""

    slot: int = Field(ge=1, le=7)
    size_gb: int = Field(ge=1, le=65536)


def _unique_disk_slots(disks: list[ExtraDiskSpec]) -> list[ExtraDiskSpec]:
    if len({d.slot for d in disks}) != len(disks):
        raise ValueError("Numery dysków muszą być unikalne")
    return disks


class DisksRequest(BaseModel):
    """Stan docelowy dysków: brakujące są tworzone, mniejsze powiększane, zbędne usuwane."""

    disks: Annotated[list[ExtraDiskSpec], AfterValidator(_unique_disk_slots)] = Field(default_factory=list, max_length=7)
    primary_gb: int | None = Field(default=None, ge=1, le=65536, description="Nowy rozmiar dysku głównego (tylko powiększenie)")
    # Usunięcie dysku kasuje dane — panel musi to potwierdzić wprost.
    delete_removed: bool = False


class HardwareRequest(BaseModel):
    hardware: HardwareSpec


class VxlanSpec(BaseModel):
    """Sieć prywatna rozpięta na kilka węzłów: tunel VXLAN (unicast, bez multicastu)
    między adresami węzłów w sieci wewnętrznej (interconnect)."""

    vni: int = Field(ge=1, le=16_777_215)
    local: str
    peers: list[str] = Field(default_factory=list, max_length=200)
    mtu: int = Field(default=1450, ge=1280, le=9000)

    @field_validator("local")
    @classmethod
    def _local_ip(cls, value: str) -> str:
        return str(ipaddress.ip_address(value))

    @field_validator("peers")
    @classmethod
    def _peer_ips(cls, value: list[str]) -> list[str]:
        return sorted({str(ipaddress.ip_address(v)) for v in value})


class PrivateNicSpec(BaseModel):
    """Dodatkowa karta maszyny w sieci prywatnej (izolowany mostek węzła)."""

    slot: int = Field(ge=1, le=7, description="Numer karty (karta główna = 0)")
    network_id: int = Field(ge=1, le=999_999_999)
    mac: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5}$")
    address: str | None = Field(default=None, description="Adres IPv4 w sieci prywatnej (cloud-init); brak = bez adresacji")
    prefix: int = Field(default=24, ge=8, le=30)
    vxlan: VxlanSpec | None = None

    @field_validator("address")
    @classmethod
    def _valid_address(cls, value: str | None) -> str | None:
        return None if not value else str(ipaddress.IPv4Address(value))

    @field_validator("mac")
    @classmethod
    def _unicast(cls, value: str | None) -> str | None:
        return None if value is None else _unicast_mac(value)


def _unique_slots(nics: list[PrivateNicSpec] | None) -> list[PrivateNicSpec] | None:
    if nics is not None and len({n.slot for n in nics}) != len(nics):
        raise ValueError("Numery kart sieciowych muszą być unikalne")
    return nics


class PrivateNicsRequest(BaseModel):
    """Pełna lista kart dodatkowych maszyny — agent dołącza brakujące i odłącza zbędne."""

    nics: Annotated[list[PrivateNicSpec], AfterValidator(_unique_slots)] = Field(default_factory=list, max_length=7)


class FirewallRule(BaseModel):
    # Karta, której dotyczy reguła: główna albo karta w sieci prywatnej (n1–n7).
    interface: str = Field(default="primary", pattern=r"^(primary|n[1-7])$")
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


class NetworkFilter(BaseModel):
    """Profil filtrowania sieci maszyny (ustawia administrator, nie klient).

    Domyślne wartości = zachowanie sprzed profili: anty-spoofing włączony,
    bez blokad RA/DHCP i bez dodatkowych reguł."""

    # Ruch tylko z adresów maszyny, tylko IPv4/IPv6/ARP (bez VLAN i innych L2).
    anti_spoofing: bool = True
    # Maszyna nie ogłasza się jako router IPv6 (RA, przekierowania ICMPv6).
    block_router_advertisements: bool = False
    # Maszyna nie odpowiada jako serwer DHCP (v4) ani DHCPv6.
    block_dhcp_server: bool = False
    # Reguły administratora — przed zaporą klienta, klient ich nie zmieni.
    rules: list[FirewallRule] = Field(default_factory=list, max_length=100)


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


def _unicast_mac(value: str) -> str:
    """MAC karty maszyny: sześć bajtów szesnastkowo, adres unicast (nie grupowy).

    Część dostawców (np. adresy failover) przyjmuje ruch adresu IP tylko z
    przypisanego do niego wirtualnego MAC — admin wpisuje go w panelu."""
    mac = value.strip().lower().replace("-", ":")
    if not re.fullmatch(r"[0-9a-f]{2}(:[0-9a-f]{2}){5}", mac):
        raise ValueError("MAC musi mieć postać aa:bb:cc:dd:ee:ff")
    if int(mac[:2], 16) & 1:
        raise ValueError("MAC grupowy (multicast) nie może być adresem karty")
    if mac == "00:00:00:00:00:00":
        raise ValueError("Zerowy MAC nie może być adresem karty")
    return mac


MacAddress = Annotated[str, AfterValidator(_unicast_mac)]


class ProvisionScript(BaseModel):
    """Skrypt pierwszego uruchomienia (provisioner z panelu), wykonywany przez cloud-init.

    Plik ląduje w `path`, a jego wynik w /var/log/virthub-provision/<nr>-<name>.log."""

    name: str = Field(pattern=r"^[a-z0-9][a-z0-9_-]{0,40}$")
    content: str = Field(min_length=3, max_length=65536)
    path: str = Field(default="/var/lib/virthub/provision", pattern=r"^/[A-Za-z0-9._/-]{1,200}$")

    @field_validator("content")
    @classmethod
    def _shebang(cls, value: str) -> str:
        if not value.startswith("#!"):
            raise ValueError("Skrypt musi zaczynać się od linii #! (np. #!/bin/bash)")
        return value

    @field_validator("path")
    @classmethod
    def _no_parent(cls, value: str) -> str:
        if ".." in value.split("/"):
            raise ValueError("Ścieżka nie może zawierać ..")
        return value.rstrip("/") or "/"


CEPH_NAME = r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$"
CEPH_MON = r"^\[?[A-Za-z0-9.:-]{1,253}\]?(:\d{1,5})?$"


class DatastoreSpec(BaseModel):
    """Magazyn dysków maszyny: katalog z plikami qcow2 albo pula Ceph RBD."""

    id: int = Field(ge=1)
    type: Literal["directory", "ceph"]
    # Cienkie przydzielanie: dysk zajmuje tyle, ile zapisano (qcow2 z backing
    # file, klon RBD). Grube: pełna kopia z rezerwacją miejsca (tylko katalog).
    thin: bool = True
    path: str | None = Field(default=None, pattern=r"^/[A-Za-z0-9._/-]{1,250}$")
    pool: str | None = Field(default=None, pattern=CEPH_NAME)
    monitors: list[Annotated[str, Field(pattern=CEPH_MON)]] = Field(default_factory=list, max_length=9)
    user: str = Field(default="admin", pattern=CEPH_NAME)
    key: str | None = Field(default=None, pattern=r"^[A-Za-z0-9+/=]{16,128}$", repr=False)

    @model_validator(mode="after")
    def _complete(self) -> "DatastoreSpec":
        if self.type == "directory":
            if not self.path or ".." in self.path.split("/"):
                raise ValueError("Magazyn katalogowy wymaga ścieżki bezwzględnej.")
        elif not (self.pool and self.monitors and self.key):
            raise ValueError("Magazyn Ceph wymaga puli, monitorów i klucza.")
        return self


class DatastoreStatusRequest(BaseModel):
    datastores: list[DatastoreSpec] = Field(max_length=50)


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
    # Windows: nośnik config-2 dla cloudbase-init zamiast NoCloud i ustawienia domeny pod Windows.
    os_type: Literal["linux", "windows"] = "linux"
    # MAC karty wymagany przez dostawcę dla adresu IP; brak = stały MAC z puli 52:54:00.
    mac: MacAddress | None = None
    # Provisionery szablonu (tylko Linux — cloudbase-init ma własny mechanizm).
    scripts: list[ProvisionScript] = Field(default_factory=list, max_length=20)
    # Profil filtrowania od pierwszego startu (potem aktualizuje go /network).
    filter: NetworkFilter | None = None
    # Magazyn dysku; brak = katalog domyślny węzła (VH_IMAGE_DIR).
    storage: DatastoreSpec | None = None
    # Karty w sieciach prywatnych (sloty 1–7).
    private_nics: Annotated[list[PrivateNicSpec], AfterValidator(_unique_slots)] = Field(default_factory=list, max_length=7)
    hardware: HardwareSpec | None = None
    extra_disks: Annotated[list[ExtraDiskSpec], AfterValidator(_unique_disk_slots)] = Field(default_factory=list, max_length=7)
    # Plik wymiany zakładany przez cloud-init (tylko Linux); brak/0 = bez swapu.
    swap_mb: int | None = Field(default=None, ge=0, le=65536)
    bandwidth_mbps: int | None = Field(default=None, ge=1, le=100_000)


class RebuildVmRequest(BaseModel):
    template: str
    hostname: str | None = Field(default=None, pattern=HOSTNAME, max_length=253)
    ssh_keys: list[SshKey] = Field(default_factory=list, max_length=10)
    root_password: Password | None = Field(default=None, repr=False)
    # Adresacja dla cloud-init nowego systemu. Starszy panel jej nie wysyłał —
    # wtedy maszyna KVM stawała bez sieci; kontener bierze ją ze swojej konfiguracji.
    interfaces: list[NetworkInterfaceSpec] | None = None
    nameservers: list[str] | None = None
    os_type: Literal["linux", "windows"] = "linux"
    # Brak = MAC zostaje taki, jaki karta ma teraz.
    mac: MacAddress | None = None
    scripts: list[ProvisionScript] = Field(default_factory=list, max_length=20)
    swap_mb: int | None = Field(default=None, ge=0, le=65536)
    # Brak = karty dodatkowe bez zmian (starszy panel); lista = stan docelowy.
    private_nics: Annotated[list[PrivateNicSpec], AfterValidator(_unique_slots)] | None = Field(default=None, max_length=7)


class RescueRequest(BaseModel):
    """Tryb ratunkowy: start z czystego obrazu (nakładka na szablon) z dyskiem maszyny podłączonym jako vdi."""

    action: Literal["start", "stop"]
    template: str | None = Field(default=None, pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$", description="Szablon systemu ratunkowego (start)")
    root_password: Password | None = Field(default=None, repr=False)
    ssh_keys: list[SshKey] = Field(default_factory=list, max_length=10)
    interfaces: list[NetworkInterfaceSpec] = Field(default_factory=list)
    nameservers: list[str] = Field(default_factory=lambda: ["1.1.1.1", "9.9.9.9"])
    mac: MacAddress | None = None

    @model_validator(mode="after")
    def _start_needs_template(self) -> "RescueRequest":
        if self.action == "start" and not self.template:
            raise ValueError("Start trybu ratunkowego wymaga szablonu")
        return self


class SeedRequest(BaseModel):
    """Dysk konfiguracji (cloud-init / config-2) podłączony albo odłączony — np. na czas instalacji z ISO."""

    enabled: bool


class HostnameRequest(BaseModel):
    hostname: str = Field(pattern=HOSTNAME, max_length=253)
    os_type: Literal["linux", "windows"] = "linux"


ISO_NAME = r"^[a-z0-9][a-z0-9._-]{0,80}\.iso$"


class IsoDownloadRequest(BaseModel):
    """Pobranie obrazu ISO do biblioteki węzła (katalog VH_ISO_DIR)."""

    name: str = Field(pattern=ISO_NAME, description="Nazwa pliku na węźle, np. debian-13-netinst.iso")
    url: str = Field(pattern=r"^https?://[^\s]{3,2000}$")
    sha256: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{64}$")
    # Adres podany przez klienta: tylko hosty publiczne (bez sieci węzła), mniejszy limit.
    untrusted: bool = False
    max_gb: int | None = Field(default=None, ge=1, le=100)


class TemplateDownloadRequest(BaseModel):
    """Szablon maszyny KVM (qcow2 z cloud-init) z katalogu panelu."""

    name: str = Field(pattern=r"^[a-z0-9][a-z0-9._-]{0,80}\.qcow2$", description="Nazwa pliku w VH_TEMPLATE_DIR")
    url: str = Field(pattern=r"^https://[^\s]{3,2000}$")
    sha256: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{64}$")
    sha512: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{128}$")


class TemplateBuildRequest(BaseModel):
    """Budowa szablonu Windows Server Packerem (pliki instalacji VirtFusion)."""

    name: str = Field(pattern=r"^[a-z0-9][a-z0-9._-]{0,80}\.qcow2$")
    edition: Literal["2019", "2022", "2025"]
    evaluation: bool = False
    iso_url: str = Field(pattern=r"^https?://[^\s]{3,2000}$", description="ISO Windows: retail/SPLA albo ewaluacyjne")
    iso_sha256: str | None = Field(default=None, pattern=r"^[0-9a-fA-F]{64}$")
    virtio_url: str = Field(
        default="https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/archive-virtio/virtio-win-0.1.262-2/virtio-win-0.1.262.iso",
        pattern=r"^https://[^\s]{3,2000}$",
    )
    files_url: str = Field(
        default="https://bitbucket.org/virtfusion-public/packer/get/master.zip",
        pattern=r"^https://[^\s]{3,2000}$",
    )
    disk_gb: int = Field(default=20, ge=14, le=200)
    cpus: int = Field(default=4, ge=1, le=32)
    memory_mb: int = Field(default=4096, ge=2048, le=65536)
    kms_key: str | None = Field(default=None, pattern=r"^[A-Z0-9]{5}(-[A-Z0-9]{5}){4}$")


class HostdevSpec(BaseModel):
    address: str = Field(pattern=r"^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}\.[0-7]$")
    # Własny XML libvirt (administrator) — zamiast domyślnego <hostdev managed='yes'>.
    xml: str | None = Field(default=None, max_length=4000)


class HostdevRequest(BaseModel):
    """Komplet urządzeń PCI maszyny (zastępuje poprzednie). Działa od następnego uruchomienia."""

    devices: list[HostdevSpec] = Field(default_factory=list, max_length=16)
    restart: bool = False


class BackupTargetSpec(BaseModel):
    type: Literal["local", "s3", "sftp", "ftp", "rclone", "pbs"]
    config: dict[str, Any] = Field(default_factory=dict, repr=False)
    # Klucz AES-256 (base64) — kopia jest szyfrowana przed wysyłką i odszyfrowywana po pobraniu.
    encryption_key: str | None = Field(default=None, repr=False, pattern=r"^[A-Za-z0-9+/]{43}=$")


class BackupRequest(BaseModel):
    """Kopia dysku maszyny na zewnętrzny cel (klucz nadaje panel)."""

    key: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,500}$")
    target: BackupTargetSpec
    compress: bool = True
    # Kopie przyrostowe (libvirt checkpoints / dirty bitmaps QEMU): nazwa nowego
    # checkpointu i checkpoint poprzedniej kopii. Brak poprzedniego (albo maszyna
    # zatrzymana) = kopia pełna; agent zwraca kind w wyniku.
    checkpoint: str | None = Field(default=None, pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
    incremental_from: str | None = Field(default=None, pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
    # Różnicowa: bloki zmienione od kopii pełnej (incremental_from = jej checkpoint), bez nowego checkpointu.
    differential: bool = False


class BackupLink(BaseModel):
    key: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,500}$")
    sha256: str | None = Field(default=None, pattern=r"^[0-9a-f]{64}$")


class BackupRestoreRequest(BaseModel):
    key: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,500}$")
    target: BackupTargetSpec
    sha256: str | None = Field(default=None, pattern=r"^[0-9a-f]{64}$")
    # Kopia przyrostowa: wcześniejsze ogniwa łańcucha, od pełnej do poprzedniej.
    chain: list[BackupLink] = Field(default_factory=list, max_length=400)


class BackupDeleteRequest(BaseModel):
    keys: list[str] = Field(max_length=500)
    target: BackupTargetSpec


class ElevateRequest(BaseModel):
    """Elevate: start = nakładka na dysk, commit = zapis zmian, discard = powrót do stanu sprzed startu."""

    action: Literal["start", "commit", "discard"]


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
    username: Literal["root", "Administrator"] = "root"


class ResizeVmRequest(BaseModel):
    vcpu: int = Field(ge=1, le=128)
    ram_mb: int = Field(ge=256)
    disk_gb: int = Field(ge=1, description="Tylko powiększenie — qcow2 nie kurczy się bezpiecznie")
    # Pole pominięte (starszy panel) = limit bez zmian; null = bez limitu.
    cpu_limit_percent: CpuLimit | None = None


class CpuLimitRequest(BaseModel):
    cpu_limit_percent: CpuLimit | None = Field(description="Brak = bez limitu")


class NestedPolicyRequest(BaseModel):
    """auto — zagnieżdżanie tylko na jądrze z poprawkami; allow — także bez nich."""

    policy: Literal["auto", "allow"]


class MacRequest(BaseModel):
    """Zmiana MAC karty istniejącej maszyny. Brak = powrót do domyślnego MAC."""

    mac: MacAddress | None = None


class PowerRequest(BaseModel):
    action: PowerAction


class NetworkConfigRequest(BaseModel):
    interfaces: list[NetworkInterfaceSpec]
    firewall: list[FirewallRule] = Field(default_factory=list)
    # Brak polityki (starszy panel) = dawne zachowanie: reguły bez domyślnej blokady.
    policy: FirewallPolicy | None = None
    # Brak profilu (starszy panel) = domyślny NetworkFilter.
    filter: NetworkFilter | None = None
    # Limit prędkości karty głównej w obie strony (Mbit/s). Pole pominięte = bez zmian,
    # null = bez limitu (dławienie po przekroczeniu transferu albo prędkość portu z pakietu).
    bandwidth_mbps: int | None = Field(default=None, ge=1, le=100_000)
    # Karty w sieciach prywatnych — dla reguł zapory przypisanych do nich (interface = nX).
    private_nics: list[PrivateNicSpec] | None = None


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
    network_backend: str | None = Field(default=None, description="Topologia sieci maszyn: bridge, ovs albo direct")
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
    host_security: dict | None = Field(default=None, description="Audyt ochrony hosta: znane ucieczki z maszyn, jądro, podatności CPU")
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
    # Duże pliki z panelu idą kawałkami: pierwszy tworzy plik, kolejne dopisują.
    append: bool = False


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


class RecoverVmRequest(CreateVmRequest):
    """Odtworzenie maszyny na zastępczym hoście z kopii na celu zewnętrznym (DR)."""

    backup: BackupRestoreRequest


class DrSnapshotRequest(BaseModel):
    """Zrzut konfiguracji węzła (domeny, sieć, magazyny) na cel kopii."""

    key: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._/-]{0,500}$")
    target: BackupTargetSpec


class TransferSource(BaseModel):
    """Dysk wystawiony przez węzeł źródłowy (migracja na zimno)."""

    url: str = Field(pattern=r"^https?://[^\s]{3,2000}$")
    sha256: str = Field(pattern=r"^[0-9a-f]{64}$")
    size_bytes: int | None = Field(default=None, ge=0)
    # Czy uruchomić maszynę po imporcie (była włączona przed migracją).
    start: bool = True


class ImportVmRequest(CreateVmRequest):
    """Migracja na zimno: maszyna z dysku pobranego z węzła źródłowego."""

    source: TransferSource


class LiveMigrationRequest(BaseModel):
    """Migracja na żywo do węzła o podanym adresie (libvirt z kopiowaniem dysku)."""

    host: str = Field(pattern=r"^[A-Za-z0-9.:\[\]-]{1,253}$")
    bandwidth_mbps: int | None = Field(default=None, ge=1, le=100000)

