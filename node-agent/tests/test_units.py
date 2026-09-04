"""Testy jednostkowe czystej logiki: XML domeny, cloud-init, reguły nftables."""

from __future__ import annotations

from xml.etree import ElementTree

import pytest

from agent.cloudinit import render_network_config, render_user_data
from agent.domain_xml import build_domain_xml, domain_name
from agent.network import NetworkManager, interface_name, mac_address
from agent.schemas import FirewallRule, NetworkInterfaceSpec


# --- definicja domeny -------------------------------------------------------

def _domain(**overrides):
    params = dict(
        name="virthub-7",
        uuid="4e6b4c8a-0000-4000-8000-000000000007",
        vcpu=4,
        ram_mb=4096,
        disk_path="/var/lib/virthub/images/virthub-7.qcow2",
        seed_path="/var/lib/virthub/seeds/virthub-7-seed.iso",
        bridge="br0",
        mac="52:54:00:00:00:07",
        interface_target="vh7",
        vnc_listen="127.0.0.1",
        vnc_password="tajne",
    )
    params.update(overrides)
    return ElementTree.fromstring(build_domain_xml(**params))


def test_xml_domeny_jest_poprawnym_dokumentem():
    root = _domain()
    assert root.tag == "domain"
    assert root.get("type") == "kvm"
    assert root.findtext("name") == "virthub-7"
    assert root.findtext("vcpu") == "4"
    assert root.findtext("memory") == "4096"


def test_dysk_uzywa_virtio_i_zwalnia_miejsce_po_trim():
    root = _domain()
    disk = root.find("./devices/disk[@device='disk']")
    assert disk.find("target").get("bus") == "virtio"
    assert disk.find("driver").get("discard") == "unmap"


def test_interfejs_ma_staly_mac_i_nazwe():
    root = _domain()
    iface = root.find("./devices/interface")
    assert iface.find("source").get("bridge") == "br0"
    assert iface.find("mac").get("address") == "52:54:00:00:00:07"
    assert iface.find("target").get("dev") == "vh7"


def test_vnc_nasluchuje_tylko_lokalnie():
    root = _domain()
    graphics = root.find("./devices/graphics")
    assert graphics.get("listen") == "127.0.0.1"
    assert graphics.get("passwd") == "tajne"


def test_konsola_szeregowa_jest_zawsze_dostepna():
    root = _domain()
    assert root.find("./devices/console") is not None, (
        "Bez konsoli szeregowej klient z zepsutą siecią traci dostęp do maszyny"
    )


def test_maszyna_bez_nosnika_cloud_init_nie_ma_cdromu():
    root = _domain(seed_path=None)
    assert root.find("./devices/disk[@device='cdrom']") is None


def test_nazwa_domeny_nie_zalezy_od_hostname_klienta():
    assert domain_name(42) == "virthub-42"


# --- cloud-init -------------------------------------------------------------

def test_brak_hasla_wylacza_logowanie_haslem():
    user_data = render_user_data("vps.example.com", ["ssh-ed25519 AAA"], None)
    assert "ssh_pwauth: false" in user_data
    assert "chpasswd" not in user_data


def test_haslo_root_trafia_do_chpasswd():
    user_data = render_user_data("vps.example.com", [], "Tajne123")
    assert "root:Tajne123" in user_data
    assert "ssh_pwauth: true" in user_data


def test_siec_dopasowuje_sie_po_mac_nie_po_nazwie_interfejsu():
    config = render_network_config(
        [NetworkInterfaceSpec(address="203.0.113.14", prefix=24, gateway="203.0.113.1")],
        ["1.1.1.1"],
        "52:54:00:00:00:07",
    )
    assert 'macaddress: "52:54:00:00:00:07"' in config
    assert "203.0.113.14/24" in config
    assert "on-link: true" in config, "Brama spoza podsieci wymaga trasy on-link"


def test_konfiguracja_obsluguje_ipv4_i_ipv6_naraz():
    config = render_network_config(
        [
            NetworkInterfaceSpec(address="203.0.113.14", prefix=24, gateway="203.0.113.1"),
            NetworkInterfaceSpec(address="2001:db8::14", prefix=64,
                                 gateway="2001:db8::1", version=6),
        ],
        ["1.1.1.1", "2606:4700:4700::1111"],
        "52:54:00:00:00:07",
    )
    assert "203.0.113.14/24" in config
    assert "2001:db8::14/64" in config
    assert "to: 0.0.0.0/0" in config
    assert "to: ::/0" in config


# --- sieć hosta -------------------------------------------------------------

def test_nazwa_interfejsu_miesci_sie_w_limicie_jadra():
    assert len(interface_name(999999999)) <= 15


def test_mac_jest_deterministyczny_i_z_puli_qemu():
    assert mac_address(1001) == mac_address(1001)
    assert mac_address(1001).startswith("52:54:00:")
    assert mac_address(1001) != mac_address(1002)


@pytest.mark.parametrize(
    "rule, expected_fragment",
    [
        (FirewallRule(protocol="tcp", port_from=22), "tcp dport 22 accept"),
        (FirewallRule(protocol="tcp", port_from=8000, port_to=8100), "tcp dport 8000-8100"),
        (FirewallRule(protocol="icmp", action="drop"), "icmp drop"),
        (FirewallRule(protocol="tcp", port_from=3306, source="10.0.0.0/8"),
         "ip saddr 10.0.0.0/8"),
    ],
)
def test_reguly_firewalla_tlumacza_sie_na_sklade_nftables(settings, rule, expected_fragment):
    manager = NetworkManager(settings)
    rendered = manager._render_rule(rule, "vh7")
    assert expected_fragment in rendered
    assert 'oifname "vh7"' in rendered
