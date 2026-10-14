"""Sieci prywatne (izolowane) i dodatkowe karty sieciowe maszyn.

Sieć prywatna to osobny mostek `vhp<id>` bez karty fizycznej i bez adresu
węzła: maszyny klienta podpięte do tego samego mostka widzą się nawzajem w
warstwie 2, ale ruch nie wychodzi poza węzeł i nie dociera do samego hosta.
Każda sieć ma własny mostek, więc dwie sieci (także dwóch klientów) są od
siebie odcięte bez reguł zapory.

Karty dodatkowe mają stałe nazwy `vh<maszyna>n<slot>` (prefiks `vh` — strażnik
usług węzła obejmuje je tak samo jak kartę główną) i stały MAC, jeśli panel nie
poda własnego.
"""

from __future__ import annotations

import re
from pathlib import Path
from typing import Iterable
from xml.sax.saxutils import escape

from .config import Settings
from .shell import CommandError, run

BRIDGE_PREFIX = "vhp"
_TARGET = re.compile(r"^vh(\d+)n([1-7])$")


def bridge_name(network_id: int) -> str:
    return f"{BRIDGE_PREFIX}{network_id}"


def nic_target(server_id: int, slot: int) -> str:
    return f"vh{server_id}n{slot}"


def parse_target(name: str | None) -> tuple[int, int] | None:
    match = _TARGET.match(name or "")
    return (int(match.group(1)), int(match.group(2))) if match else None


def nic_mac(server_id: int, slot: int) -> str:
    """Jak MAC karty głównej (52:54:00:…), ale z numerem slotu w drugim bajcie po prefiksie."""
    return f"52:54:{slot:02x}:{(server_id >> 16) & 0xFF:02x}:{(server_id >> 8) & 0xFF:02x}:{server_id & 0xFF:02x}"


def interface_xml(server_id: int, nic) -> str:
    mac = nic.mac or nic_mac(server_id, nic.slot)
    return (
        "    <interface type='bridge'>\n"
        f"      <source bridge='{escape(bridge_name(nic.network_id))}'/>\n"
        f"      <mac address='{escape(mac)}'/>\n"
        f"      <target dev='{escape(nic_target(server_id, nic.slot))}'/>\n"
        "      <model type='virtio'/>\n"
        "    </interface>"
    )


def ensure_bridges(settings: Settings, nics: Iterable) -> None:
    """Mostki sieci prywatnych bez łącza w górę; bez adresu i bez przekazywania przez węzeł."""
    if settings.is_mock:
        return
    for network_id in sorted({n.network_id for n in nics}):
        bridge = bridge_name(network_id)
        if Path(f"/sys/class/net/{bridge}").exists():
            continue
        for argv in (
            ["ip", "link", "add", bridge, "type", "bridge", "stp_state", "0"],
            # Bez IPv6 na mostku: host nie dostaje adresu link-local w sieci klienta.
            ["sysctl", "-qw", f"net.ipv6.conf.{bridge}.disable_ipv6=1"],
            ["ip", "link", "set", bridge, "up"],
        ):
            try:
                run(argv)
            except CommandError as exc:
                if "File exists" not in exc.stderr:
                    raise


def prune_bridges(settings: Settings, sys_net: Path = Path("/sys/class/net")) -> list[str]:
    """Usuwa mostki sieci prywatnych, do których nie jest podpięta żadna karta."""
    if settings.is_mock or not sys_net.exists():
        return []
    removed = []
    for path in sorted(sys_net.glob(f"{BRIDGE_PREFIX}*")):
        if not re.fullmatch(rf"{BRIDGE_PREFIX}\d+", path.name) or not (path / "bridge").exists():
            continue
        if any((path / "brif").iterdir()):
            continue
        try:
            run(["ip", "link", "del", path.name])
            removed.append(path.name)
        except CommandError:
            pass
    return removed
