"""Sieci zaawansowane na węźle: mostki VLAN (802.1Q) i mostek podsieci routowanych.

Agent ma CAP_NET_ADMIN, więc zakłada interfejsy sam, przy pierwszej maszynie,
która ich potrzebuje — tak jak mostek NAT. Wszystko jest idempotentne.
"""

from __future__ import annotations

import logging
from pathlib import Path

from .config import Settings
from .schemas import NetworkInterfaceSpec
from .shell import CommandError, run

log = logging.getLogger("virthub.l2")

ROUTED_GATEWAY_V4 = "169.254.0.1"
ROUTED_GATEWAY_V6 = "fe80::1"
VIRTUAL_PREFIXES = ("vh", "vnet", "tap", "veth", "virbr", "docker", "br-")


class NetworkLayoutError(ValueError):
    pass


def vlan_bridge(vlan: int) -> str:
    return f"vhv{vlan}"


def layout(interfaces: list[NetworkInterfaceSpec]) -> tuple[str, int | None]:
    """Rodzaj podłączenia maszyny (jedna karta = jeden rodzaj): bridged/nat/routed i VLAN."""
    modes = {i.mode for i in interfaces}
    vlans = {i.vlan for i in interfaces if i.mode == "bridged"}
    if len(modes) > 1:
        raise NetworkLayoutError(f"Adresy maszyny mają różne rodzaje sieci ({', '.join(sorted(modes))}) — maszyna ma jedną kartę.")
    if len(vlans) > 1:
        raise NetworkLayoutError("Adresy maszyny są w różnych VLAN-ach — maszyna ma jedną kartę.")
    mode = next(iter(modes), "bridged")
    return mode, next(iter(vlans), None) if mode == "bridged" else None


def vlan_parent(settings: Settings, sys_net: Path = Path("/sys/class/net")) -> str:
    """Łącze dla VLAN-ów: VH_VLAN_PARENT albo port fizyczny głównego mostka."""
    if settings.vlan_parent:
        return settings.vlan_parent
    ports = sorted(p.name for p in (sys_net / settings.bridge / "brif").glob("*")) if (sys_net / settings.bridge / "brif").is_dir() else []
    physical = [p for p in ports if not p.startswith(VIRTUAL_PREFIXES)]
    if not physical:
        raise NetworkLayoutError(f"Nie znaleziono portu fizycznego mostka {settings.bridge} — ustaw VH_VLAN_PARENT w agent.env.")
    return physical[0]


def vlan_commands(parent: str, vlan: int) -> list[list[str]]:
    sub = f"{parent}.{vlan}"
    if len(sub) > 15:
        raise NetworkLayoutError(f"Nazwa interfejsu {sub} jest za długa (max 15 znaków) — ustaw krótsze VH_VLAN_PARENT.")
    bridge = vlan_bridge(vlan)
    return [
        ["ip", "link", "add", "link", parent, "name", sub, "type", "vlan", "id", str(vlan)],
        ["ip", "link", "add", "name", bridge, "type", "bridge"],
        ["ip", "link", "set", "dev", sub, "master", bridge],
        ["ip", "link", "set", "dev", sub, "up"],
        ["ip", "link", "set", "dev", bridge, "up"],
    ]


def routed_bridge_commands(bridge: str) -> list[list[str]]:
    return [
        ["ip", "link", "add", "name", bridge, "type", "bridge"],
        ["ip", "link", "set", "dev", bridge, "up"],
        ["ip", "addr", "replace", f"{ROUTED_GATEWAY_V4}/32", "dev", bridge],
        ["ip", "-6", "addr", "replace", f"{ROUTED_GATEWAY_V6}/64", "dev", bridge],
    ]


def route_commands(bridge: str, interfaces: list[NetworkInterfaceSpec], action: str = "replace") -> list[list[str]]:
    out = []
    for spec in interfaces:
        if spec.mode != "routed":
            continue
        prefix = 128 if spec.version == 6 else 32
        out.append(["ip", *(["-6"] if spec.version == 6 else []), "route", action, f"{spec.address}/{prefix}", "dev", bridge])
    return out


def run_idempotent(commands: list[list[str]]) -> None:
    for argv in commands:
        try:
            run(argv)
        except CommandError as exc:
            # „File exists” przy dodawaniu i „No such process” przy usuwaniu to stan docelowy.
            if "File exists" in exc.stderr or "No such process" in exc.stderr or "Cannot find device" in exc.stderr and argv[2] == "del":
                continue
            raise
