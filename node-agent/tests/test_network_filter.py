"""Profile filtrowania sieci (administrator): anty-spoofing, RA, DHCP, własne reguły."""

from __future__ import annotations

import json
import shutil
import subprocess

import pytest

from agent.network import NetworkManager
from agent.schemas import FirewallPolicy, FirewallRule, NetworkConfigRequest, NetworkFilter, NetworkInterfaceSpec

IFACES = [NetworkInterfaceSpec(address="203.0.113.14", prefix=24, gateway="203.0.113.1")]


def render(settings, net_filter=None, policy=None):
    return NetworkManager(settings).render_machine(7, IFACES, [FirewallRule(protocol="tcp", port_from=22)], policy, stateful=True, net_filter=net_filter)


def test_domyslny_profil_jak_dotad(settings):
    assert render(settings) == render(settings, NetworkFilter())
    script = render(settings)
    assert "ip saddr != { 203.0.113.14 } drop" in script
    assert "nd-router-advert, nd-redirect } drop" not in script


def test_bez_anty_spoofingu(settings):
    script = render(settings, NetworkFilter(anti_spoofing=False))
    assert "saddr" not in script
    assert "ether type" not in script
    # Zapora klienta zostaje.
    assert "tcp dport 22 accept" in script


def test_blokada_ra_i_dhcp_przed_zapora_klienta(settings):
    script = render(settings, NetworkFilter(block_router_advertisements=True, block_dhcp_server=True),
                    FirewallPolicy(inbound="drop", outbound="drop"))
    ra = script.index('iifname "vh7" icmpv6 type { nd-router-advert, nd-redirect } drop')
    dhcp = script.index('iifname "vh7" udp sport 67 drop')
    assert 'iifname "vh7" udp sport 547 drop' in script
    # Przed zaporą klienta, która przepuszcza ND (w tym RA) przy domyślnej blokadzie.
    assert ra < script.index("nd-neighbor-solicit")
    assert dhcp < script.index("tcp dport 22 accept")


def test_reguly_administratora_przed_regulami_klienta(settings):
    script = render(settings, NetworkFilter(rules=[FirewallRule(protocol="tcp", port_from=25, direction="out", action="drop")]))
    assert script.index("tcp dport 25 drop") < script.index("tcp dport 22 accept")


def test_profil_przetrwa_restart(settings):
    manager = NetworkManager(settings)
    manager.configure(7, IFACES, [], None, NetworkFilter(block_dhcp_server=True))
    state = json.loads((settings.network_state_dir / "7.json").read_text())
    assert state["filter"]["block_dhcp_server"] is True


def test_starszy_panel_bez_profilu():
    assert NetworkConfigRequest(interfaces=[]).filter is None


def _nft_available() -> bool:
    if not shutil.which("nft") or not shutil.which("unshare"):
        return False
    return subprocess.run(["unshare", "-n", "nft", "list", "ruleset"], capture_output=True).returncode == 0


@pytest.mark.skipif(not _nft_available(), reason="wymaga nft i unshare (root)")
def test_skladnia_profilu_w_prawdziwym_nft(settings):
    manager = NetworkManager(settings)
    script = manager.render_base() + render(settings, NetworkFilter(
        anti_spoofing=False, block_router_advertisements=True, block_dhcp_server=True,
        rules=[FirewallRule(protocol="any", source="10.0.0.0/8", direction="out", action="drop")]))
    proc = subprocess.run(["unshare", "-n", "nft", "-c", "-f", "-"], input=script, text=True, capture_output=True)
    assert proc.returncode == 0, proc.stderr
