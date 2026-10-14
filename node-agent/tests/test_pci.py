"""Przekazywanie urządzeń PCI: lista z sysfs, XML hostdev, API."""

from __future__ import annotations

from xml.etree import ElementTree as ET

import pytest

from agent import pci
from agent.domain_xml import build_domain_xml
from test_provisioning import create_vm, wait_for_job


def base_xml():
    return build_domain_xml(
        name="virthub-5", uuid="6f1d2a4e-9c3b-4b8e-8f2a-1d2c3b4a5e6f", vcpu=1, ram_mb=512, disk_path="/d.qcow2",
        seed_path="/seed.iso", bridge="br0", mac="52:54:00:00:00:05", interface_target="vh5", vnc_listen="127.0.0.1", vnc_password="x",
    )


def test_lista_z_sysfs(tmp_path):
    dev = tmp_path / "devices" / "0000:41:00.0"
    dev.mkdir(parents=True)
    (dev / "class").write_text("0x030000\n")
    (dev / "vendor").write_text("0x10de\n")
    (dev / "device").write_text("0x2204\n")
    group = tmp_path / "groups" / "31"
    group.mkdir(parents=True)
    (dev / "iommu_group").symlink_to(group)
    (tmp_path / "devices" / "nie-adres").mkdir()

    [device] = pci.list_devices(tmp_path / "devices")
    assert device["address"] == "0000:41:00.0"
    assert device["type"] == "vga"
    assert device["vendor_id"] == "10de"
    assert device["iommu_group"] == 31


def test_hostdev_w_domenie_i_podmiana():
    xml = pci.with_hostdevs(base_xml(), [("0000:41:00.0", None), ("0000:41:00.1", None)])
    assert pci.hostdev_addresses(xml) == ["0000:41:00.0", "0000:41:00.1"]
    hd = ET.fromstring(xml).find("devices/hostdev")
    assert hd.get("managed") == "yes"
    assert hd.find("source/address").get("bus") == "0x41"

    # Ponowne ustawienie zastępuje komplet.
    xml = pci.with_hostdevs(xml, [("0000:81:00.0", None)])
    assert pci.hostdev_addresses(xml) == ["0000:81:00.0"]
    assert pci.hostdev_addresses(pci.with_hostdevs(xml, [])) == []


def test_wlasny_xml_i_walidacja():
    custom = "<hostdev mode='subsystem' type='pci' managed='no'><source><address domain='0x0000' bus='0x05' slot='0x00' function='0x0'/></source><rom bar='off'/></hostdev>"
    xml = pci.with_hostdevs(base_xml(), [("0000:05:00.0", custom)])
    assert ET.fromstring(xml).find("devices/hostdev/rom").get("bar") == "off"
    with pytest.raises(pci.PciError):
        pci.hostdev_element("0000:05:00.0", "<disk/>")
    with pytest.raises(pci.PciError):
        pci.hostdev_element("0000:05:00.0", "<hostdev")
    with pytest.raises(pci.PciError):
        pci.hostdev_element("05:00.0")


def test_api(client, template):
    assert client.get("/system/pci").json()["devices"][0]["address"] == "0000:41:00.0"
    vm = create_vm(client, template, server_id=1401)["result"]
    response = client.put(f"/vm/{vm['uuid']}/hostdevs", json={"devices": [{"address": "0000:41:00.0"}]})
    assert response.status_code == 202
    job = wait_for_job(client, response.json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert job["result"]["devices"] == ["0000:41:00.0"]
    assert client.put(f"/vm/{vm['uuid']}/hostdevs", json={"devices": [{"address": "zly"}]}).status_code == 422
