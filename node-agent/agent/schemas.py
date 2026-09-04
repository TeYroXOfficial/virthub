"""Kontrakt API agenta — modele wejścia i wyjścia.

Te modele są źródłem prawdy dla specyfikacji OpenAPI w /shared/api-contracts.
Zmiana pola tutaj = zmiana kontraktu z control plane.
"""

from __future__ import annotations

from enum import Enum
from typing import Literal

from pydantic import BaseModel, Field


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


class NetworkInterfaceSpec(BaseModel):
    """Jeden adres przypisany do VPS-a — używany też do reguł anty-spoofingowych."""

    address: str = Field(description="Adres IP bez maski, np. 203.0.113.14")
    prefix: int = Field(ge=0, le=128, description="Długość prefiksu CIDR")
    gateway: str | None = None
    version: Literal[4, 6] = 4


class FirewallRule(BaseModel):
    action: Literal["accept", "drop"] = "accept"
    direction: Literal["in", "out"] = "in"
    protocol: Literal["tcp", "udp", "icmp", "any"] = "tcp"
    port_from: int | None = Field(default=None, ge=1, le=65535)
    port_to: int | None = Field(default=None, ge=1, le=65535)
    source: str | None = Field(default=None, description="CIDR źródła, domyślnie dowolny")


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


class ResizeVmRequest(BaseModel):
    vcpu: int = Field(ge=1, le=128)
    ram_mb: int = Field(ge=256)
    disk_gb: int = Field(ge=1, description="Tylko powiększenie — qcow2 nie kurczy się bezpiecznie")


class PowerRequest(BaseModel):
    action: PowerAction


class NetworkConfigRequest(BaseModel):
    interfaces: list[NetworkInterfaceSpec]
    firewall: list[FirewallRule] = Field(default_factory=list)


class SnapshotRequest(BaseModel):
    name: str = Field(pattern=r"^[a-zA-Z0-9_.-]{1,64}$")


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
    hostname: str
    libvirt_connected: bool
    cpu_cores_total: int
    cpu_load_1m: float
    ram_mb_total: int
    ram_mb_free: int
    disk_gb_total: int
    disk_gb_free: int
    running_vms: int
