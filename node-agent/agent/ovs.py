"""Open vSwitch: port maszyny na mostku OVS i anty-spoofing regułami OpenFlow.

Ruch portu OVS nie przechodzi przez netfilter rodziny bridge, więc reguły
nftables go nie widzą. Zamiast nich każda maszyna dostaje zestaw przepływów
z ciasteczkiem równym jej numerowi: z portu maszyny przechodzi tylko ARP
i IP z jej adresów i jej MAC-a (plus Neighbor Discovery IPv6), reszta jest
odrzucana. Zapora klienta (reguły portów) w tym trybie nie działa — panel to
pokazuje.
"""

from __future__ import annotations

from .shell import CommandError, run

COOKIE_BASE = 0x5648_0000_0000_0000  # „VH” w starszych bajtach — nie koliduje z przepływami administratora


def cookie(server_id: int) -> str:
    return f"{COOKIE_BASE | server_id:#x}"


def ofport(iface: str, runner=run) -> int:
    value = runner(["ovs-vsctl", "get", "Interface", iface, "ofport"]).strip()
    try:
        port = int(value)
    except ValueError as exc:
        raise CommandError(["ovs-vsctl"], 1, f"Port {iface} nie jest podpięty do OVS ({value})") from exc
    if port <= 0:
        raise CommandError(["ovs-vsctl"], 1, f"Port {iface} nie ma numeru OpenFlow ({port}) — maszyna nie działa?")
    return port


def flows(server_id: int, port: int, mac: str, v4: list[str], v6: list[str]) -> list[str]:
    """Przepływy anty-spoofingowe dla portu maszyny (czysta funkcja, testowalna bez OVS)."""
    c = cookie(server_id)
    base = f"cookie={c},priority={{p}},in_port={port},dl_src={mac}"
    out = [base.format(p=300) + f",arp,arp_spa={ip},actions=NORMAL" for ip in v4]
    out += [base.format(p=300) + f",ip,nw_src={ip},actions=NORMAL" for ip in v4]
    # IPv6: Neighbor Discovery z adresu link-local/nieokreślonego, potem tylko adresy maszyny.
    out += [base.format(p=300) + ",icmp6,ipv6_src=fe80::/10,actions=NORMAL", base.format(p=300) + ",icmp6,ipv6_src=::,actions=NORMAL"]
    out += [base.format(p=300) + f",ipv6,ipv6_src={ip},actions=NORMAL" for ip in v6]
    # Rozgłaszanie routerów i DHCP z maszyny — nigdy.
    out.insert(0, f"cookie={c},priority=400,in_port={port},icmp6,icmp_type=134,actions=drop")
    out.insert(0, f"cookie={c},priority=400,in_port={port},udp,tp_src=67,actions=drop")
    out.append(f"cookie={c},priority=100,in_port={port},actions=drop")
    return out


def apply(bridge: str, server_id: int, iface: str, mac: str, v4: list[str], v6: list[str], runner=run) -> None:
    port = ofport(iface, runner)
    runner(["ovs-ofctl", "del-flows", bridge, f"cookie={cookie(server_id)}/-1"])
    runner(["ovs-ofctl", "add-flows", bridge, "-"], input="\n".join(flows(server_id, port, mac, v4, v6)) + "\n")


def remove(bridge: str, server_id: int, runner=run) -> None:
    try:
        runner(["ovs-ofctl", "del-flows", bridge, f"cookie={cookie(server_id)}/-1"])
    except CommandError:
        pass
