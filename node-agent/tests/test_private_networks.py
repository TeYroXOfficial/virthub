"""Sieci prywatne i dodatkowe karty maszyn."""

from __future__ import annotations

import sys
import types
import xml.etree.ElementTree as ET

import pytest
from pydantic import ValidationError

from agent import private_net
from agent.cloudinit import render_network_config, render_network_data
from agent.domain_xml import build_domain_xml
from agent.schemas import CreateVmRequest, NetworkInterfaceSpec, PrivateNicSpec, PrivateNicsRequest
from test_provisioning import create_vm, wait_for_job

PUBLIC = [NetworkInterfaceSpec(address="203.0.113.50", prefix=24, gateway="203.0.113.1")]


def test_nazwy_mac_i_walidacja():
    assert private_net.bridge_name(42) == "vhp42"
    assert private_net.nic_target(1234567, 7) == "vh1234567n7" and len("vh1234567n7") <= 15
    assert private_net.parse_target("vh15n2") == (15, 2)
    assert private_net.parse_target("vh15") is None
    assert private_net.nic_mac(0x010203, 1) == "52:54:01:01:02:03"
    with pytest.raises(ValidationError):
        PrivateNicsRequest(nics=[{"slot": 1, "network_id": 1}, {"slot": 1, "network_id": 2}])
    with pytest.raises(ValidationError):
        PrivateNicSpec(slot=8, network_id=1)
    with pytest.raises(ValidationError):
        PrivateNicSpec(slot=1, network_id=1, address="fd00::1")


def test_cloud_init_i_xml_domeny():
    nics = [PrivateNicSpec(slot=2, network_id=9, address="10.20.0.5", prefix=24), PrivateNicSpec(slot=1, network_id=7, mac="02:00:00:00:00:aa")]
    config = render_network_config(PUBLIC, ["1.1.1.1"], "52:54:00:00:00:0f", nics, 15)
    assert "  private1:" in config and 'macaddress: "02:00:00:00:00:aa"' in config
    assert 'macaddress: "52:54:02:00:00:0f"' in config and "10.20.0.5/24" in config
    # Trasa domyślna tylko na karcie głównej.
    assert config.count("routes:") == 1

    data = render_network_data(PUBLIC, ["1.1.1.1"], "52:54:00:00:00:0f", nics, 15)
    assert '"interface2"' in data and '"10.20.0.5"' in data

    xml = build_domain_xml(name="virthub-15", uuid="u", vcpu=1, ram_mb=512, disk_path="/d.qcow2", seed_path=None, bridge="vhbr0",
                           mac="52:54:00:00:00:0f", interface_target="vh15", vnc_listen="127.0.0.1", vnc_password="p",
                           extra_interfaces=[private_net.interface_xml(15, n) for n in nics])
    root = ET.fromstring(xml)
    ifaces = root.findall("./devices/interface")
    assert [i.find("target").get("dev") for i in ifaces] == ["vh15", "vh15n2", "vh15n1"]
    assert ifaces[1].find("source").get("bridge") == "vhp9"


def test_karty_w_trybie_mock(client, template):
    vm = create_vm(client, template, server_id=1951, private_nics=[{"slot": 1, "network_id": 3, "address": "10.0.3.2"}])["result"]
    assert vm["private_nics"][0]["interface"] == "vh1951n1"

    job = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/nics", json={"nics": [
        {"slot": 1, "network_id": 3}, {"slot": 2, "network_id": 4},
    ]}).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert [n["interface"] for n in job["result"]["nics"]] == ["vh1951n1", "vh1951n2"]

    bad = client.put(f"/vm/{vm['uuid']}/nics", json={"nics": [{"slot": 1, "network_id": 3}, {"slot": 1, "network_id": 4}]})
    assert bad.status_code == 422


def test_libvirt_dolacza_i_odlacza_na_zywo(settings, monkeypatch):
    calls: list[tuple[str, str, int]] = []
    fake = types.SimpleNamespace(VIR_DOMAIN_XML_INACTIVE=2, VIR_DOMAIN_AFFECT_CONFIG=2, VIR_DOMAIN_AFFECT_LIVE=1,
                                 libvirtError=type("libvirtError", (Exception,), {}))
    monkeypatch.setitem(sys.modules, "libvirt", fake)
    xml = """<domain><name>virthub-20</name><devices>
      <interface type='bridge'><source bridge='vhbr0'/><mac address='52:54:00:00:00:14'/><target dev='vh20'/></interface>
      <interface type='bridge'><source bridge='vhp1'/><mac address='52:54:01:00:00:14'/><target dev='vh20n1'/></interface>
      <interface type='bridge'><source bridge='vhp5'/><mac address='52:54:02:00:00:14'/><target dev='vh20n2'/></interface>
    </devices></domain>"""

    class Domain:
        def name(self):
            return "virthub-20"

        def isActive(self):
            return True

        def XMLDesc(self, flags):
            return xml

        def detachDeviceFlags(self, device, flags):
            calls.append(("detach", ET.fromstring(device).find("target").get("dev"), flags))

        def attachDeviceFlags(self, device, flags):
            calls.append(("attach", ET.fromstring(device).find("source").get("bridge"), flags))

        def info(self):
            return [1]

    from agent.driver import LibvirtDriver

    drv = LibvirtDriver(settings)
    monkeypatch.setattr(drv, "_domain", lambda uuid: Domain())
    monkeypatch.setattr(drv, "_state", lambda d: "running")
    result = drv.set_private_nics("u", PrivateNicsRequest(nics=[{"slot": 1, "network_id": 1}, {"slot": 2, "network_id": 6}, {"slot": 3, "network_id": 7}]))
    # slot 1 bez zmian, slot 2 przepięty z vhp5 na vhp6, slot 3 nowy; karta główna nietknięta.
    assert calls == [("detach", "vh20n2", 3), ("attach", "vhp6", 3), ("attach", "vhp7", 3)]
    assert [n["interface"] for n in result["nics"]] == ["vh20n1", "vh20n2", "vh20n3"]

    calls.clear()
    drv.set_private_nics("u", PrivateNicsRequest(nics=[]))
    assert sorted(c[1] for c in calls) == ["vh20n1", "vh20n2"]


def test_mostki_bez_kart_sa_usuwane(settings, tmp_path, monkeypatch):
    for name, members in (("vhp1", []), ("vhp2", ["vh5n1"]), ("vhbr0", [])):
        (tmp_path / name / "bridge").mkdir(parents=True)
        (tmp_path / name / "brif").mkdir()
        for m in members:
            (tmp_path / name / "brif" / m).touch()
    ran = []
    monkeypatch.setattr(private_net, "run", lambda argv: ran.append(argv))
    monkeypatch.setattr(type(settings), "is_mock", property(lambda self: False))
    assert private_net.prune_bridges(settings, tmp_path) == ["vhp1"]
    assert ran == [["ip", "link", "del", "vhp1"]]


def test_tworzenie_mostka(settings, monkeypatch):
    ran = []
    monkeypatch.setattr(private_net, "run", lambda argv: ran.append(argv))
    monkeypatch.setattr(type(settings), "is_mock", property(lambda self: False))
    monkeypatch.setattr(private_net.Path, "exists", lambda self: False)
    private_net.ensure_bridges(settings, [PrivateNicSpec(slot=1, network_id=8), PrivateNicSpec(slot=2, network_id=8)])
    assert ran[0] == ["ip", "link", "add", "vhp8", "type", "bridge", "stp_state", "0"]
    assert len(ran) == 3, "Jeden mostek na sieć, bez łącza w górę"
    assert not any("master" in argv for argv in ran)


def test_request_bez_kart_zgodny_wstecz(template):
    req = CreateVmRequest(server_id=1, hostname="a.example.com", vcpu=1, ram_mb=512, disk_gb=10, template=template)
    assert req.private_nics == []


def test_mostki_odtwarzane_po_restarcie(settings, monkeypatch):
    fake = types.SimpleNamespace(VIR_DOMAIN_XML_INACTIVE=2, libvirtError=Exception)
    monkeypatch.setitem(sys.modules, "libvirt", fake)
    ensured = []
    monkeypatch.setattr(private_net, "ensure_bridges", lambda s, nics: ensured.extend(n.network_id for n in nics))

    class Domain:
        def __init__(self, xml):
            self.xml = xml

        def name(self):
            return "d"

        def XMLDesc(self, flags):
            return self.xml

    class Conn:
        def listAllDomains(self):
            return [Domain("<domain><devices><interface><source bridge='vhp4'/></interface><interface><source bridge=\"vhp9\"/></interface></devices></domain>"),
                    Domain("<domain><devices><interface><source bridge='vhbr0'/></interface></devices></domain>")]

    from agent.driver import LibvirtDriver

    drv = LibvirtDriver(settings)
    drv._conn = Conn()
    monkeypatch.setattr(type(drv), "conn", property(lambda self: self._conn))
    assert drv.restore_private_bridges() == 2
    assert sorted(ensured) == [4, 9]
