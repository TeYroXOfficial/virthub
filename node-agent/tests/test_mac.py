"""Własny MAC karty maszyny (wirtualny MAC adresu IP u dostawcy)."""

from __future__ import annotations

import xml.etree.ElementTree as ET

import pytest
from pydantic import ValidationError

from agent.schemas import MacRequest, RebuildVmRequest

from test_lxc import driver, incus, request  # noqa: F401 — fikstury LXC
from test_provisioning import create_vm, wait_for_job


def test_walidacja_mac():
    assert MacRequest(mac="02-AB-CD-00-11-22").mac == "02:ab:cd:00:11:22"
    assert MacRequest(mac=None).mac is None
    for bad in ["01:00:5e:00:00:01", "02:ab:cd:00:11", "zz:zz:zz:zz:zz:zz", "00:00:00:00:00:00"]:
        with pytest.raises(ValidationError):
            MacRequest(mac=bad)


# --- API (sterownik testowy) ---------------------------------------------------

def test_mac_przy_tworzeniu_i_zmiana_przez_api(client, template):
    vm = create_vm(client, template, server_id=1101, mac="02:00:00:aa:bb:cc")["result"]
    assert vm["mac"] == "02:00:00:aa:bb:cc"

    job = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/mac", json={"mac": "02:00:00:11:22:33"}).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert job["result"] == {"uuid": vm["uuid"], "mac": "02:00:00:11:22:33", "changed": True, "restart_required": True}

    # Brak MAC = powrót do domyślnego z puli 52:54:00.
    job = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/mac", json={"mac": None}).json()["job_id"])
    assert job["result"]["mac"].startswith("52:54:00:")

    assert client.put(f"/vm/{vm['uuid']}/mac", json={"mac": "ff:ff:ff:ff:ff:ff"}).status_code == 422


def test_bez_mac_zostaje_domyslny(client, template):
    vm = create_vm(client, template, server_id=1102)["result"]
    assert vm["mac"] == "52:54:00:00:04:4e"


# --- LXC ---------------------------------------------------------------------------

def test_lxc_mac_przy_tworzeniu_zmianie_i_reinstalacji(driver, incus):  # noqa: F811
    uuid = driver.create_vm(request(mac="02:00:00:aa:bb:cc"))["uuid"]
    ct = incus.instances["virthub-42"]
    assert ct["devices"]["eth0"]["hwaddr"] == "02:00:00:aa:bb:cc"
    assert "02:00:00:aa:bb:cc" in ct["config"]["cloud-init.network-config"]

    result = driver.set_mac(uuid, "02:00:00:11:22:33")
    assert result == {"uuid": uuid, "mac": "02:00:00:11:22:33", "changed": True, "restart_required": True}
    assert ct["devices"]["eth0"]["hwaddr"] == "02:00:00:11:22:33"
    netcfg = ct["config"]["cloud-init.network-config"]
    assert "02:00:00:11:22:33" in netcfg and "02:00:00:aa:bb:cc" not in netcfg, "cloud-init dopasowuje kartę po MAC"

    assert driver.set_mac(uuid, "02:00:00:11:22:33")["changed"] is False

    incus.images.add("debian/12/cloud")
    driver.rebuild(uuid, RebuildVmRequest(template="debian/12/cloud", root_password="NoweHaslo456", mac="02:00:00:44:55:66"))
    assert ct["devices"]["eth0"]["hwaddr"] == "02:00:00:44:55:66"
    assert "02:00:00:44:55:66" in ct["config"]["cloud-init.network-config"]


# --- KVM (libvirt podstawiony) -----------------------------------------------------

class FakeDomain:
    def __init__(self, xml, active=True):
        self.xml, self.active = xml, active

    def XMLDesc(self, flags=0):
        return self.xml

    def name(self):
        return "virthub-7"

    def isActive(self):
        return self.active


class FakeConn:
    def __init__(self, domain):
        self.domain = domain
        self.defined: list[str] = []

    def isAlive(self):
        return True

    def defineXML(self, xml):
        self.defined.append(xml)
        self.domain.xml = xml
        return self.domain


def test_kvm_zmiana_mac_w_definicji_domeny(settings, monkeypatch):
    import sys
    import types

    fake_libvirt = types.SimpleNamespace(VIR_DOMAIN_XML_INACTIVE=2, libvirtError=Exception)
    monkeypatch.setitem(sys.modules, "libvirt", fake_libvirt)

    from agent.driver import LibvirtDriver

    drv = LibvirtDriver.__new__(LibvirtDriver)
    domain = FakeDomain("<domain><name>virthub-7</name><devices><interface type='bridge'>"
                        "<mac address='52:54:00:00:00:07'/></interface></devices></domain>")
    drv._conn = FakeConn(domain)
    monkeypatch.setattr(drv, "_domain", lambda uuid: domain)

    result = drv.set_mac("u-1", "02:00:00:aa:bb:cc")
    assert result == {"uuid": "u-1", "mac": "02:00:00:aa:bb:cc", "changed": True, "restart_required": True}
    assert ET.fromstring(drv._conn.defined[-1]).find("./devices/interface/mac").get("address") == "02:00:00:aa:bb:cc"

    assert drv.set_mac("u-1", None)["mac"] == "52:54:00:00:00:07"
    assert drv.set_mac("u-1", None)["changed"] is False
