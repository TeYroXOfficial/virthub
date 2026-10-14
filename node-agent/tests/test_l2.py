"""Sieci zaawansowane: VLAN, podsieci routowane, spójność adresów maszyny."""

from __future__ import annotations

import dataclasses

import pytest
import yaml

from agent import l2
from agent.cloudinit import render_network_config
from agent.network import NetworkManager
from agent.schemas import NetworkInterfaceSpec


def spec(address, mode="bridged", vlan=None, version=4, prefix=24, gateway="203.0.113.1"):
    return NetworkInterfaceSpec(address=address, prefix=prefix, gateway=gateway, version=version, mode=mode, vlan=vlan)


def test_mostek_dla_rodzaju_sieci(settings):
    net = NetworkManager(settings)
    assert net.bridge_for([spec("203.0.113.5")]) == settings.bridge
    assert net.bridge_for([spec("203.0.113.5", vlan=120), spec("2001:db8::5", vlan=120, version=6, prefix=64, gateway="2001:db8::1")]) == "vhv120"
    assert net.bridge_for([spec("198.51.100.9", mode="routed", prefix=32, gateway="169.254.0.1")]) == settings.routed_bridge


def test_rozne_vlany_albo_rodzaje_odrzucone():
    with pytest.raises(l2.NetworkLayoutError):
        l2.layout([spec("203.0.113.5", vlan=10), spec("203.0.113.6", vlan=20)])
    with pytest.raises(l2.NetworkLayoutError):
        l2.layout([spec("203.0.113.5"), spec("198.51.100.9", mode="routed")])


def test_polecenia_vlan_i_wykrycie_lacza(settings, tmp_path):
    brif = tmp_path / settings.bridge / "brif"
    brif.mkdir(parents=True)
    for port in ("vnet3", "vh12", "eno1"):
        (brif / port).mkdir()
    assert l2.vlan_parent(settings, tmp_path) == "eno1"
    assert l2.vlan_parent(dataclasses.replace(settings, vlan_parent="bond0"), tmp_path) == "bond0"

    commands = l2.vlan_commands("eno1", 120)
    assert ["ip", "link", "add", "link", "eno1", "name", "eno1.120", "type", "vlan", "id", "120"] in commands
    assert ["ip", "link", "set", "dev", "eno1.120", "master", "vhv120"] in commands
    with pytest.raises(l2.NetworkLayoutError):
        l2.vlan_commands("enp129s0f1np1", 4094)


def test_trasy_dla_adresow_routowanych():
    routed = [spec("198.51.100.9", mode="routed", prefix=32, gateway="169.254.0.1"),
              spec("2001:db8:5::9", mode="routed", version=6, prefix=128, gateway="fe80::1")]
    assert l2.route_commands("vhroute0", routed) == [
        ["ip", "route", "replace", "198.51.100.9/32", "dev", "vhroute0"],
        ["ip", "-6", "route", "replace", "2001:db8:5::9/128", "dev", "vhroute0"],
    ]
    assert l2.route_commands("vhroute0", [spec("203.0.113.5")]) == []


def test_konfiguracja_goscia_routowanego_ma_brame_on_link():
    data = yaml.safe_load(render_network_config(
        [spec("198.51.100.9", mode="routed", prefix=32, gateway="169.254.0.1")], ["1.1.1.1"], "52:54:00:00:00:09"))
    eth = data["ethernets"]["primary"]
    assert eth["addresses"] == ["198.51.100.9/32"]
    assert eth["routes"][0] == {"to": "0.0.0.0/0", "via": "169.254.0.1", "on-link": True}
