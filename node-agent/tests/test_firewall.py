"""Zapora maszyny: reguły, polityka domyślna i poprawność składni nftables."""

from __future__ import annotations

import shutil
import subprocess

import pytest
from pydantic import ValidationError

from agent.network import NetworkManager
from agent.schemas import FirewallPolicy, FirewallRule, NetworkInterfaceSpec

IFACES = [
    NetworkInterfaceSpec(address="203.0.113.14", prefix=24, gateway="203.0.113.1"),
    NetworkInterfaceSpec(address="2001:db8::14", prefix=64, gateway="2001:db8::1", version=6),
]

RULES = [
    FirewallRule(protocol="tcp", port_from=22),
    FirewallRule(protocol="tcp"),
    FirewallRule(protocol="udp", port_from=1000, port_to=2000, source="198.51.100.0/24", action="drop"),
    FirewallRule(protocol="tcp", port_from=443, direction="out", source="2001:db8:ff::/48"),
    FirewallRule(protocol="icmp"),
    FirewallRule(protocol="any", source="192.0.2.7", action="drop"),
]


def render(settings, policy=None, rules=RULES, stateful=True):
    return NetworkManager(settings).render_machine(7, IFACES, list(rules), policy, stateful=stateful)


# --- kontrakt -----------------------------------------------------------------

@pytest.mark.parametrize("bad", ["10.0.0.0/8; flush ruleset", "10.0.0.0/8 }", "nie-adres", "10.0.0.300"])
def test_adres_nie_wstrzyknie_polecenia(bad):
    with pytest.raises(ValidationError):
        FirewallRule(protocol="tcp", port_from=22, source=bad)


def test_porty_tylko_dla_tcp_i_udp():
    with pytest.raises(ValidationError):
        FirewallRule(protocol="icmp", port_from=22)


def test_zakres_portow_nie_moze_byc_odwrocony():
    with pytest.raises(ValidationError):
        FirewallRule(protocol="tcp", port_from=2000, port_to=1000)


# --- generowanie --------------------------------------------------------------

def test_tcp_bez_portu_ma_poprawna_skladnie(settings):
    # Dawniej „tcp accept" — błąd składni, który wywracał całą konfigurację sieci.
    rule = NetworkManager(settings)._render_rule(FirewallRule(protocol="tcp"), "vh7")
    assert rule == 'oifname "vh7" meta l4proto tcp accept'


def test_regula_wychodzaca_dotyczy_adresu_docelowego(settings):
    rule = NetworkManager(settings)._render_rule(RULES[3], "vh7")
    assert rule.startswith('iifname "vh7" ip6 daddr 2001:db8:ff::/48')


def test_bez_polityki_dawne_zachowanie(settings):
    rules = render(settings, policy=None)
    assert "tcp dport 22 accept" in rules
    assert "meta protocol { ip, ip6 } drop" not in rules


def test_wylaczona_zapora_zostawia_tylko_anty_spoofing(settings):
    rules = render(settings, FirewallPolicy(enabled=False, inbound="drop"))
    assert "dport 22" not in rules
    assert "meta protocol { ip, ip6 } drop" not in rules
    assert 'iifname "vh7" ip saddr != { 203.0.113.14 } drop' in rules


def test_domyslna_blokada_przychodzacych(settings):
    rules = render(settings, FirewallPolicy(inbound="drop"))
    lines = rules.splitlines()

    block = next(i for i, l in enumerate(lines) if 'oifname "vh7" meta protocol { ip, ip6 } drop' in l)
    allow_ssh = next(i for i, l in enumerate(lines) if "tcp dport 22 accept" in l)
    established = next(i for i, l in enumerate(lines) if 'oifname "vh7" ct state established,related accept' in l)

    assert established < allow_ssh < block, "odpowiedzi i reguły muszą być przed domyślną blokadą"
    assert "nd-neighbor-solicit" in rules, "bez Neighbor Discovery maszyna traci IPv6"
    assert 'iifname "vh7" meta protocol { ip, ip6 } drop' not in rules, "wychodzący zostaje otwarty"


def test_tryb_bezstanowy_bez_conntrack(settings):
    rules = render(settings, FirewallPolicy(inbound="drop"), stateful=False)
    assert "ct state" not in rules
    assert "tcp flags & (syn | ack) != syn accept" in rules


# --- prawdziwe nftables -----------------------------------------------------------

def _nft_available() -> bool:
    if not shutil.which("nft") or not shutil.which("unshare"):
        return False
    probe = subprocess.run(["unshare", "-n", "nft", "list", "ruleset"], capture_output=True)
    return probe.returncode == 0


@pytest.mark.skipif(not _nft_available(), reason="wymaga nft i unshare (root)")
@pytest.mark.parametrize("policy", [None, FirewallPolicy(inbound="drop", outbound="drop"), FirewallPolicy(enabled=False)])
def test_skladnia_przechodzi_w_prawdziwym_nft(settings, policy):
    manager = NetworkManager(settings)
    script = manager.render_base() + render(settings, policy, stateful=False)
    proc = subprocess.run(["unshare", "-n", "nft", "-c", "-f", "-"], input=script, text=True, capture_output=True)
    assert proc.returncode == 0, proc.stderr
