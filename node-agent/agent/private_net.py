"""Sieci prywatne (izolowane) i dodatkowe karty sieciowe maszyn.

Sieć prywatna to osobny mostek `vhp<id>` bez karty fizycznej i bez adresu
węzła: maszyny klienta podpięte do tego samego mostka widzą się nawzajem w
warstwie 2, ale ruch nie wychodzi poza węzeł i nie dociera do samego hosta.
Każda sieć ma własny mostek, więc dwie sieci (także dwóch klientów) są od
siebie odcięte bez reguł zapory.

Karty dodatkowe mają stałe nazwy `vh<maszyna>n<slot>` (prefiks `vh` — strażnik
usług węzła obejmuje je tak samo jak kartę główną) i stały MAC, jeśli panel nie
poda własnego.

Sieć rozpięta na kilka węzłów grupy dostaje tunel VXLAN `vxp<id>` w mostku
(unicast do adresów pozostałych węzłów w sieci wewnętrznej, bez multicastu i
bez uczenia się adresów z ruchu). Konfiguracja tunelu jest zapamiętywana na
węźle, żeby po restarcie hosta mostek wrócił razem z nim.
"""

from __future__ import annotations

import json
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


def vxlan_name(network_id: int) -> str:
    return f"vxp{network_id}"


def interface_xml(server_id: int, nic) -> str:
    mac = nic.mac or nic_mac(server_id, nic.slot)
    vxlan = getattr(nic, "vxlan", None)
    return (
        "    <interface type='bridge'>\n"
        f"      <source bridge='{escape(bridge_name(nic.network_id))}'/>\n"
        f"      <mac address='{escape(mac)}'/>\n"
        f"      <target dev='{escape(nic_target(server_id, nic.slot))}'/>\n"
        "      <model type='virtio'/>\n"
        + (f"      <mtu size='{int(vxlan.mtu)}'/>\n" if vxlan else "")
        + "    </interface>"
    )


def _vxlan_store(settings: Settings) -> Path:
    return settings.state_db.parent / "private-vxlan.json"


def _load_vxlan(settings: Settings) -> dict[str, dict]:
    try:
        return json.loads(_vxlan_store(settings).read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def _save_vxlan(settings: Settings, network_id: int, spec) -> None:
    data = _load_vxlan(settings)
    current = spec.model_dump() if spec is not None else None
    if data.get(str(network_id)) == current:
        return
    if current is None:
        data.pop(str(network_id), None)
    else:
        data[str(network_id)] = current
    path = _vxlan_store(settings)
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data), encoding="utf-8")


def vxlan_commands(network_id: int, spec) -> list[list[str]]:
    dev, bridge = vxlan_name(network_id), bridge_name(network_id)
    return [
        ["ip", "link", "add", dev, "type", "vxlan", "id", str(spec.vni), "dstport", "4789", "local", spec.local, "nolearning"],
        ["ip", "link", "set", dev, "mtu", str(spec.mtu)],
        ["ip", "link", "set", bridge, "mtu", str(spec.mtu)],
        ["ip", "link", "set", dev, "master", bridge],
        ["ip", "link", "set", dev, "up"],
    ]


def fdb_peers(output: str) -> set[str]:
    """Adresy węzłów z wpisów `bridge fdb show dev vxpN` dla adresu 00:…:00 (BUM)."""
    peers = set()
    for line in output.splitlines():
        parts = line.split()
        if parts[:1] == ["00:00:00:00:00:00"] and "dst" in parts:
            peers.add(parts[parts.index("dst") + 1])
    return peers


def sync_fdb(network_id: int, peers: list[str], runner=None) -> None:
    """Ruch rozgłoszeniowy (ARP) do każdego węzła sieci — wpisy dopisane i usunięte do stanu z panelu."""
    runner = runner or run
    dev = vxlan_name(network_id)
    current = fdb_peers(runner(["bridge", "fdb", "show", "dev", dev]) or "")
    for peer in sorted(set(peers) - current):
        runner(["bridge", "fdb", "append", "00:00:00:00:00:00", "dev", dev, "dst", peer])
    for peer in sorted(current - set(peers)):
        runner(["bridge", "fdb", "del", "00:00:00:00:00:00", "dev", dev, "dst", peer])


def ensure_bridges(settings: Settings, nics: Iterable) -> None:
    """Mostki sieci prywatnych bez łącza w górę; bez adresu i bez przekazywania przez węzeł."""
    nics = list(nics)
    stored = _load_vxlan(settings)
    specs: dict[int, object] = {}
    for nic in nics:
        spec = getattr(nic, "vxlan", None)
        if spec is not None:
            specs[nic.network_id] = spec
            _save_vxlan(settings, nic.network_id, spec)
    if settings.is_mock:
        return
    from .schemas import VxlanSpec

    for network_id in sorted({n.network_id for n in nics}):
        bridge = bridge_name(network_id)
        spec = specs.get(network_id) or (VxlanSpec(**stored[str(network_id)]) if str(network_id) in stored else None)
        commands: list[list[str]] = []
        if not Path(f"/sys/class/net/{bridge}").exists():
            commands += [
                ["ip", "link", "add", bridge, "type", "bridge", "stp_state", "0"],
                # Bez IPv6 na mostku: host nie dostaje adresu link-local w sieci klienta.
                ["sysctl", "-qw", f"net.ipv6.conf.{bridge}.disable_ipv6=1"],
                ["ip", "link", "set", bridge, "up"],
            ]
        if spec is not None and not Path(f"/sys/class/net/{vxlan_name(network_id)}").exists():
            commands += vxlan_commands(network_id, spec)
        for argv in commands:
            try:
                run(argv)
            except CommandError as exc:
                if "File exists" not in exc.stderr:
                    raise
        if spec is not None:
            sync_fdb(network_id, [p for p in spec.peers if p != spec.local])


def prune_bridges(settings: Settings, sys_net: Path = Path("/sys/class/net")) -> list[str]:
    """Usuwa mostki sieci prywatnych, do których nie jest podpięta żadna karta."""
    if settings.is_mock or not sys_net.exists():
        return []
    removed = []
    for path in sorted(sys_net.glob(f"{BRIDGE_PREFIX}*")):
        if not re.fullmatch(rf"{BRIDGE_PREFIX}\d+", path.name) or not (path / "bridge").exists():
            continue
        # Tunel VXLAN jest portem mostka, ale nie maszyną — nie trzyma sieci przy życiu.
        if any(not port.name.startswith("vxp") for port in (path / "brif").iterdir()):
            continue
        network_id = int(path.name[len(BRIDGE_PREFIX):])
        try:
            if (sys_net / vxlan_name(network_id)).exists():
                run(["ip", "link", "del", vxlan_name(network_id)])
            run(["ip", "link", "del", path.name])
            removed.append(path.name)
            _save_vxlan(settings, network_id, None)
        except CommandError:
            pass
    return removed
