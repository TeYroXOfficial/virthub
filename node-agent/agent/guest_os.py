"""System gościa i procesor hosta — do pokazania klientowi w panelu."""

from __future__ import annotations

import platform
import shlex
from pathlib import Path


def parse_os_release(text: str) -> dict[str, str | None]:
    """/etc/os-release (format KEY="wartość") → id, nazwa, wersja."""
    values: dict[str, str] = {}
    for line in text.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, raw = line.partition("=")
        try:
            parts = shlex.split(raw)
        except ValueError:
            parts = [raw.strip("\"'")]
        values[key.strip()] = parts[0] if parts else ""

    return {
        "id": (values.get("ID") or "").lower() or None,
        "id_like": (values.get("ID_LIKE") or "").lower() or None,
        "name": values.get("NAME") or None,
        "version": values.get("VERSION_ID") or None,
        "pretty_name": values.get("PRETTY_NAME") or values.get("NAME") or None,
    }


def from_guest_agent(info: dict) -> dict[str, str | None]:
    """Wynik virDomainGetGuestInfo(OS) z qemu-guest-agent (Linux i Windows)."""
    return {
        "id": (info.get("os.id") or "").lower() or None,
        "id_like": None,
        "name": info.get("os.name") or None,
        "version": info.get("os.version-id") or info.get("os.version") or None,
        "pretty_name": info.get("os.pretty-name") or info.get("os.name") or None,
    }


def outbound_ipv4(probe: str = "1.1.1.1") -> str | None:
    """Adres IPv4, którym węzeł wychodzi w świat (trasa domyślna).

    `connect` na gnieździe UDP tylko wybiera trasę i adres źródłowy — nie
    wysyła żadnego pakietu. Pod tym adresem klienci łączą się z portami
    maszyn za NAT-em, gdy pula nie ma własnego adresu publicznego.
    """
    import socket

    try:
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as sock:
            sock.connect((probe, 53))
            return sock.getsockname()[0]
    except OSError:
        return None


def cpu_model(cpuinfo: Path = Path("/proc/cpuinfo")) -> str | None:
    """Nazwa procesora hosta, np. „AMD EPYC 7763 64-Core Processor"."""
    try:
        for line in cpuinfo.read_text(encoding="utf-8", errors="replace").splitlines():
            key, _, value = line.partition(":")
            if key.strip().lower() in {"model name", "cpu model", "hardware"} and value.strip():
                return " ".join(value.split())
    except OSError:
        pass
    return platform.processor() or None
