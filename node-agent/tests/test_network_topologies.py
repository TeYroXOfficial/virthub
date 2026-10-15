"""Sieci: VXLAN między węzłami, zapora na kartach prywatnych, Open vSwitch i macvtap."""

from __future__ import annotations

import dataclasses
import xml.etree.ElementTree as ET

from agent import ovs, private_net
from agent.cloudinit import render_network_config
from agent.domain_xml import primary_interface_xml
from agent.network import NetworkManager
from agent.schemas import FirewallRule, NetworkInterfaceSpec, PrivateNicSpec, VxlanSpec

PUBLIC = [NetworkInterfaceSpec(address="203.0.113.60", prefix=24, gateway="203.0.113.1")]


def test_vxlan_polecenia_i_fdb():
    spec = VxlanSpec(vni=10007, local="10.10.0.1", peers=["10.10.0.2", "10.10.0.3"])
    commands = private_net.vxlan_commands(7, spec)
    assert commands[0] == ["ip", "link", "add", "vxp7", "type", "vxlan", "id", "10007", "dstport", "4789", "local", "10.10.0.1", "nolearning"]
    assert ["ip", "link", "set", "vxp7", "master", "vhp7"] in commands

    ran = []

    def runner(argv):
        ran.append(argv)
        if argv[:3] == ["bridge", "fdb", "show"]:
            return "00:00:00:00:00:00 dst 10.10.0.2 self permanent\n00:00:00:00:00:00 dst 10.10.0.9 self permanent\n"
        return ""

    private_net.sync_fdb(7, ["10.10.0.2", "10.10.0.3"], runner=runner)
    assert ["bridge", "fdb", "append", "00:00:00:00:00:00", "dev", "vxp7", "dst", "10.10.0.3"] in ran
    assert ["bridge", "fdb", "del", "00:00:00:00:00:00", "dev", "vxp7", "dst", "10.10.0.9"] in ran, "Węzeł bez członków sieci znika z tunelu"


def test_vxlan_mtu_w_karcie_i_cloud_init():
    nic = PrivateNicSpec(slot=1, network_id=7, address="10.20.0.2", vxlan={"vni": 10007, "local": "10.10.0.1"})
    assert "<mtu size='1450'/>" in private_net.interface_xml(5, nic)
    assert "    mtu: 1450" in render_network_config(PUBLIC, ["1.1.1.1"], "52:54:00:00:00:05", [nic], 5)


def test_konfiguracja_vxlan_zapamietana_na_restart(settings, monkeypatch, tmp_path):
    monkeypatch.setattr(type(settings), "is_mock", property(lambda self: False))
    ran = []
    monkeypatch.setattr(private_net, "run", lambda argv: ran.append(argv) or "")
    monkeypatch.setattr(private_net.Path, "exists", lambda self: False)
    nic = PrivateNicSpec(slot=1, network_id=88, vxlan={"vni": 10088, "local": "10.10.0.1", "peers": ["10.10.0.2"]})
    private_net.ensure_bridges(settings, [nic])
    assert any(a[:4] == ["ip", "link", "add", "vxp88"] for a in ran)

    ran.clear()
    stub = type("Nic", (), {"network_id": 88})()  # odtworzenie po restarcie: tylko numer sieci z XML domeny
    private_net.ensure_bridges(settings, [stub])
    assert any(a[:4] == ["ip", "link", "add", "vxp88"] for a in ran), "Tunel wraca z zapisanej konfiguracji"


def test_zapora_na_karcie_prywatnej(settings):
    net = NetworkManager(settings)
    nic = PrivateNicSpec(slot=2, network_id=3, address="10.30.0.5", prefix=24)
    script = net.render_machine(9, [NetworkInterfaceSpec(address=nic.address, prefix=nic.prefix)],
                                [FirewallRule(interface="n2", action="drop", protocol="tcp", port_from=3306)],
                                iface="vh9n2", chain="vm_9_n2")
    assert 'iifname "vh9n2" ip saddr != { 10.30.0.5 } drop' in script
    assert 'oifname "vh9n2" meta l4proto tcp tcp dport 3306 drop' in script
    assert '{ "vh9n2" : jump vm_9_n2 }' in script


def test_reguly_dzielone_wedlug_karty(settings, monkeypatch):
    net = NetworkManager(dataclasses.replace(settings))
    monkeypatch.setattr(type(net.settings), "is_mock", property(lambda self: False))
    applied = []
    monkeypatch.setattr(net, "_apply", lambda script: applied.append(script))
    monkeypatch.setattr(net, "ensure_base_table", lambda: None)
    monkeypatch.setattr(net, "stateful", lambda: True)
    monkeypatch.setattr(net.nat, "configure", lambda *a: None)
    monkeypatch.setattr(net, "_sync_routes", lambda *a: None)
    rules = [FirewallRule(port_from=22), FirewallRule(interface="n1", port_from=5432)]
    net.configure(11, PUBLIC, rules, private_nics=[PrivateNicSpec(slot=1, network_id=4, address="10.40.0.2")])
    primary = next(s for s in applied if "add chain bridge virthub vm_11\n" in s or "vm_11\n" in s.split("add rule")[0])
    assert "dport 22" in primary and "dport 5432" not in primary
    private = next(s for s in applied if "vm_11_n1" in s and "add rule" in s)
    assert "dport 5432" in private and "dport 22 " not in private
    assert any('delete element' in s and '"vh11n2"' in s for s in applied), "Łańcuchy nieistniejących kart są sprzątane"


def test_ovs_przeplywy_antyspoofingowe():
    flows = ovs.flows(12, 7, "52:54:00:00:00:0c", ["203.0.113.12"], ["2001:db8::12"])
    assert any("in_port=7,dl_src=52:54:00:00:00:0c,arp,arp_spa=203.0.113.12,actions=NORMAL" in f for f in flows)
    assert any("ip,nw_src=203.0.113.12,actions=NORMAL" in f for f in flows)
    assert any("ipv6,ipv6_src=2001:db8::12,actions=NORMAL" in f for f in flows)
    assert flows[-1].endswith("priority=100,in_port=7,actions=drop")
    assert all(f.startswith(f"cookie={ovs.cookie(12)}") for f in flows)

    ran = []

    def runner(argv, input=None):
        ran.append((argv, input))
        return "7\n" if argv[:2] == ["ovs-vsctl", "get"] else ""

    ovs.apply("br0", 12, "vh12", "52:54:00:00:00:0c", ["203.0.113.12"], [], runner=runner)
    assert ran[1][0] == ["ovs-ofctl", "del-flows", "br0", f"cookie={ovs.cookie(12)}/-1"], "Stare przepływy zdejmowane przed nowymi"
    assert ran[2][0] == ["ovs-ofctl", "add-flows", "br0", "-"] and "arp_spa=203.0.113.12" in ran[2][1]


def test_xml_karty_dla_trybow():
    ovs_xml = ET.fromstring(primary_interface_xml("ovs", "br0", "52:54:00:00:00:01", "vh1", vlan=120))
    assert ovs_xml.find("virtualport").get("type") == "openvswitch" and ovs_xml.find("vlan/tag").get("id") == "120"
    direct = ET.fromstring(primary_interface_xml("direct", "br0", "52:54:00:00:00:01", "vh1", direct_dev="eno1"))
    assert direct.get("type") == "direct" and direct.find("source").attrib == {"dev": "eno1", "mode": "bridge"}
    plain = ET.fromstring(primary_interface_xml("bridge", "br0", "52:54:00:00:00:01", "vh1"))
    assert plain.find("virtualport") is None and plain.find("target").get("dev") == "vh1"


def test_ovs_vlan_bez_osobnego_mostka(settings):
    net = NetworkManager(dataclasses.replace(settings, network_backend="ovs"))
    vlan = [NetworkInterfaceSpec(address="203.0.113.5", prefix=24, vlan=120)]
    assert net.bridge_for(vlan) == settings.bridge
    assert net.backend_for(vlan) == "ovs"
    assert net.backend_for([NetworkInterfaceSpec(address="10.0.0.5", prefix=32, mode="routed", gateway="169.254.0.1")]) == "bridge"
