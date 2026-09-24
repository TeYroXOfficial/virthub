"""NAT węzła: kontrakt adresu prywatnego, reguły nftables i stan przekierowań."""

from __future__ import annotations

import dataclasses

import pytest
from pydantic import ValidationError

from agent.nat import NatManager, port_forwards
from agent.network import NetworkManager
from agent.schemas import NetworkInterfaceSpec


def nat_iface(address="10.10.0.5", port_from=10100, port_to=10104, **nat) -> NetworkInterfaceSpec:
    return NetworkInterfaceSpec(
        address=address, prefix=24, gateway="10.10.0.1", mode="nat",
        nat={"network": "10.10.0.0/24", "port_from": port_from, "port_to": port_to, **nat},
    )


def nat6_iface(address="fd00:10::5") -> NetworkInterfaceSpec:
    return NetworkInterfaceSpec(
        address=address, prefix=64, gateway="fd00:10::1", version=6, mode="nat",
        nat={"network": "fd00:10::/64"},
    )


@pytest.fixture()
def live(settings, tmp_path, monkeypatch):
    """NatManager w trybie „prawdziwym", z przechwyconymi wywołaniami nft."""
    manager = NatManager(dataclasses.replace(settings, driver="libvirt", state_db=tmp_path / "s.db"))
    manager.applied = []
    manager.existing = set()
    monkeypatch.setattr(manager, "_apply", manager.applied.append)
    monkeypatch.setattr(manager, "_existing_ports", lambda: set(manager.existing))
    monkeypatch.setattr(manager, "prepare", lambda interfaces: None)
    monkeypatch.setattr(manager, "ensure_base_table", lambda: None)
    return manager


# --- kontrakt -----------------------------------------------------------------

def test_pierwszy_port_prowadzi_na_ssh_reszta_jeden_do_jednego():
    assert port_forwards(nat_iface(port_from=10100, port_to=10103)) == {
        10100: 22, 10101: 10101, 10102: 10102, 10103: 10103,
    }


def test_ipv6_za_nat_nie_dostaje_przekierowan():
    assert port_forwards(nat6_iface()) == {}


def test_adres_nat_wymaga_sieci_i_bramy():
    with pytest.raises(ValidationError):
        NetworkInterfaceSpec(address="10.10.0.5", prefix=24, gateway="10.10.0.1", mode="nat")


def test_adres_spoza_sieci_nat_jest_odrzucany():
    with pytest.raises(ValidationError):
        nat_iface(address="10.20.0.5")


@pytest.mark.parametrize("bad", ["10.10.0.5; flush ruleset", "10.10.0.5 }", "not-an-ip"])
def test_adres_nie_wstrzyknie_polecenia_do_nftables(bad):
    with pytest.raises(ValidationError):
        NetworkInterfaceSpec(address=bad, prefix=24)


def test_zakres_portow_musi_byc_kompletny():
    with pytest.raises(ValidationError):
        nat_iface(port_from=10100, port_to=None)


# --- reguły -------------------------------------------------------------------

def test_maszyna_za_nat_stoi_na_mostku_nat(settings):
    network = NetworkManager(settings)
    assert network.bridge_for([nat_iface()]) == settings.nat_bridge
    assert network.bridge_for([NetworkInterfaceSpec(address="203.0.113.5", prefix=24)]) == settings.bridge


def test_anty_spoofing_obejmuje_ruch_do_wezla(settings):
    # Maszyna za NAT-em rozmawia ze światem przez węzeł — jej ruch trafia do
    # hooka input mostka, a nie forward.
    base = NetworkManager(settings).render_base()
    assert "hook input" in base
    assert "hook output" in base
    assert "hook forward" in base


def test_tabela_nat_ma_maskarade_i_przekierowania(settings):
    manager = NatManager(settings)
    base = manager.render_base()
    rules = manager.render_base_rules()

    assert f"add table inet {manager.table}" in base
    assert "hook prerouting priority dstnat" in base
    assert "hook postrouting priority srcnat" in base
    assert "dnat ip to tcp dport map @fwd4" in rules
    assert "dnat ip to udp dport map @fwd4" in rules
    assert "ip saddr @nets4 ip daddr != @nets4 masquerade" in rules
    assert "ip6 saddr @nets6 ip6 daddr != @nets6 masquerade" in rules
    # Adres wyjścia z mapy musi wygrać z maskaradą, więc stoi przed nią.
    assert rules.index("snat ip to ip saddr map @snat4") < rules.index("ip daddr != @nets4 masquerade")


def test_brama_puli_trafia_na_mostek_z_maska_sieci(settings):
    addresses = NatManager(settings).render_bridge_addresses(
        [nat_iface(), nat_iface(address="10.10.0.6"), nat6_iface()]
    )
    assert addresses == ["10.10.0.1/24", "fd00:10::1/64"]


def test_elementy_maszyny(settings):
    manager = NatManager(settings)
    iface = nat_iface(snat_address="198.51.100.7", port_from=10100, port_to=10101)
    script = manager.render_machine(
        [iface], {10100: ("10.10.0.5", 22), 10101: ("10.10.0.5", 10101)}, delete_ports=[],
    )

    assert "add element inet virthub_nat nets4 { 10.10.0.0/24 }" in script
    assert "add element inet virthub_nat snat4 { 10.10.0.0/24 : 198.51.100.7 }" in script
    assert "10100 : 10.10.0.5 . 22, 10101 : 10.10.0.5 . 10101" in script
    assert "delete" not in script


def test_adres_wyjscia_innej_rodziny_jest_pomijany(settings):
    # Publiczny IPv4 nie może trafić do mapy adresów IPv6 — nft odrzuciłby
    # całą transakcję, a maszyna zostałaby bez sieci.
    script = NatManager(settings).render_machine(
        [NetworkInterfaceSpec(
            address="fd00:10::5", prefix=64, gateway="fd00:10::1", version=6, mode="nat",
            nat={"network": "fd00:10::/64", "snat_address": "198.51.100.7"},
        )], {}, [],
    )
    assert "snat6" not in script
    assert "nets6 { fd00:10::/64 }" in script


# --- stan i cykl życia ----------------------------------------------------------

def test_konfiguracja_zapisuje_stan_i_przekierowania(live):
    live.configure(5, [nat_iface(port_from=10100, port_to=10101)])

    assert live._state_file(5).exists()
    assert "10100 : 10.10.0.5 . 22" in live.applied[-1]


def test_zmiana_adresu_usuwa_stare_porty_w_tej_samej_transakcji(live):
    live.configure(5, [nat_iface(port_from=10100, port_to=10101)])
    live.existing = {10100, 10101}

    live.configure(5, [nat_iface(address="10.10.0.9", port_from=10180, port_to=10181)])

    script = live.applied[-1]
    assert script.startswith("delete element inet virthub_nat fwd4 { 10100, 10101 }")
    assert "10180 : 10.10.0.9 . 22" in script


def test_port_po_poprzednim_wlascicielu_jest_nadpisywany(live):
    # Adres wrócił do puli, ale wpis poprzedniej maszyny został (np. padł
    # agent w trakcie usuwania). Nowy właściciel musi go przejąć.
    live.existing = {10100}
    live.configure(8, [nat_iface(port_from=10100, port_to=10100)])

    assert live.applied[-1].startswith("delete element inet virthub_nat fwd4 { 10100 }")


def test_usuniecie_maszyny_zdejmuje_przekierowania(live):
    live.configure(5, [nat_iface(port_from=10100, port_to=10101)])
    live.existing = {10100, 10101, 20000}

    live.teardown(5)

    assert live.applied[-1] == "delete element inet virthub_nat fwd4 { 10100, 10101 }\n"
    assert not live._state_file(5).exists()


def test_przejscie_na_adres_publiczny_zdejmuje_nat(live):
    live.configure(5, [nat_iface(port_from=10100, port_to=10100)])
    live.existing = {10100}

    live.configure(5, [NetworkInterfaceSpec(address="203.0.113.5", prefix=24)])

    assert not live._state_file(5).exists()
    assert "delete element" in live.applied[-1]


def test_restart_hosta_odtwarza_nat_ze_stanu(live):
    live.configure(5, [nat_iface(port_from=10100, port_to=10100)])
    live.applied.clear()

    assert live.restore() == 1
    assert "10100 : 10.10.0.5 . 22" in live.applied[-1]
    assert live._state_file(5).exists()


def test_tryb_mock_zapisuje_stan_bez_nft(settings, tmp_path):
    manager = NatManager(dataclasses.replace(settings, state_db=tmp_path / "s.db"))
    manager.configure(3, [nat_iface()])
    assert manager._state_file(3).exists()
    manager.teardown(3)
    assert not manager._state_file(3).exists()


def test_wlaczone_przekazywanie_nie_wymaga_zapisu(tmp_path):
    # Usługa ma /proc/sys tylko do odczytu (ProtectKernelTunables). Jeśli
    # przekazywanie już jest włączone (ExecStartPre), agent nic nie zapisuje.
    flag = tmp_path / "ip_forward"
    flag.write_text("1\n")
    flag.chmod(0o444)
    NatManager._require_sysctl(str(flag), "net.ipv4.ip_forward")


def test_wylaczone_przekazywanie_bez_uprawnien_mowi_co_zrobic(tmp_path):
    from agent.shell import CommandError

    missing_dir = tmp_path / "brak" / "ip_forward"
    with pytest.raises(CommandError) as exc:
        NatManager._require_sysctl(str(missing_dir), "net.ipv4.ip_forward")
    assert "sysctl" in str(exc.value)
    assert "90-virthub.conf" in str(exc.value)
