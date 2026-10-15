"""Tryb ratunkowy, dysk konfiguracji, nazwa hosta i swap."""

from __future__ import annotations

import sys
import types
import xml.etree.ElementTree as ET

import pytest
from pydantic import ValidationError

from agent.cloudinit import render_user_data
from agent.domain_xml import build_domain_xml, rescue_xml, with_iso, with_seed
from agent.schemas import RescueRequest
from test_provisioning import create_vm, wait_for_job


def _xml():
    return build_domain_xml(name="virthub-7", uuid="u", vcpu=1, ram_mb=1024, disk_path="/d/virthub-7.qcow2", seed_path="/s/virthub-7-seed.iso",
                            bridge="vhbr0", mac="52:54:00:00:00:07", interface_target="vh7", vnc_listen="127.0.0.1", vnc_password="p")


def _disks(xml):
    return {d.find("target").get("dev"): d for d in ET.fromstring(xml).findall("./devices/disk")}


def test_definicja_ratunkowa():
    mounted = with_iso(_xml(), "/isos/debian.iso", boot_from_iso=True)
    out = rescue_xml(mounted, "/d/virthub-7.rescue.qcow2", "/s/virthub-7-rescue-seed.iso")
    disks = _disks(out)
    assert disks["vda"].find("source").get("file") == "/d/virthub-7.rescue.qcow2"
    assert disks["vdi"].find("source").get("file") == "/d/virthub-7.qcow2", "Dysk klienta podłączony obok"
    assert disks["sda"].find("source").get("file") == "/s/virthub-7-rescue-seed.iso"
    assert "sdb" not in disks, "Bez ISO — start z systemu ratunkowego"
    root = ET.fromstring(out)
    assert root.find("./os/boot").get("dev") == "hd"
    assert not root.findall("./devices/disk/boot")


def test_dysk_konfiguracji_odlaczany_i_przywracany():
    off = with_seed(_xml(), None)
    assert "sda" not in _disks(off)
    on = with_seed(off, "/s/virthub-7-seed.iso")
    assert _disks(on)["sda"].find("source").get("file") == "/s/virthub-7-seed.iso"


def test_swap_w_cloud_init():
    assert "swap:" not in render_user_data("a", [], None)
    data = render_user_data("a", [], None, swap_mb=1024)
    assert "  filename: /swapfile" in data and "  size: 1073741824" in data


def test_walidacja_ratunkowego():
    with pytest.raises(ValidationError):
        RescueRequest(action="start")
    with pytest.raises(ValidationError):
        RescueRequest(action="start", template="../etc/passwd")
    RescueRequest(action="stop")


def test_tryb_ratunkowy_mock(client, template):
    vm = create_vm(client, template, server_id=2101, swap_mb=512)["result"]

    def rescue(body):
        return wait_for_job(client, client.post(f"/vm/{vm['uuid']}/rescue", json=body).json()["job_id"])

    assert rescue({"action": "stop"})["status"] == "failed"
    started = rescue({"action": "start", "template": template, "root_password": "Ratunek123!"})
    assert started["status"] == "done", started.get("error")
    assert started["result"]["rescue"] is True
    # Kopia w trybie ratunkowym jest blokowana.
    backup = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups", json={"key": "k.qcow2", "target": {"type": "local", "config": {"path": "/tmp"}}}).json()["job_id"])
    assert backup["status"] == "failed" and "ratunkow" in backup["error"]
    assert rescue({"action": "stop"})["result"]["rescue"] is False

    seed = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/seed", json={"enabled": False}).json()["job_id"])
    assert seed["status"] == "done" and seed["result"]["enabled"] is False
    host = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/hostname", json={"hostname": "nowa.example.com"}).json()["job_id"])
    assert host["status"] == "done" and host["result"]["applied"] is True
    assert client.put(f"/vm/{vm['uuid']}/hostname", json={"hostname": "zła nazwa"}).status_code == 422


def test_libvirt_start_i_koniec_trybu(settings, monkeypatch, tmp_path):
    fake = types.SimpleNamespace(VIR_DOMAIN_XML_INACTIVE=2, libvirtError=type("libvirtError", (Exception,), {}))
    monkeypatch.setitem(sys.modules, "libvirt", fake)
    from agent import driver as drv_mod
    from agent.driver import LibvirtDriver

    template = settings.template_dir / "debian-13.qcow2"
    template.parent.mkdir(parents=True, exist_ok=True)
    template.write_bytes(b"x")
    original = _xml()
    defined, ran = [], []

    class Domain:
        active = True

        def name(self):
            return "virthub-7"

        def isActive(self):
            return self.active

        def XMLDesc(self, flags):
            return defined[-1] if defined else original

        def destroy(self):
            self.active = False

        def create(self):
            self.active = True

    domain = Domain()
    drv = LibvirtDriver(settings)
    drv._conn = types.SimpleNamespace(defineXML=lambda x: defined.append(x))
    monkeypatch.setattr(type(drv), "conn", property(lambda self: self._conn))
    monkeypatch.setattr(drv, "_domain", lambda uuid: domain)
    monkeypatch.setattr(drv, "_state", lambda d: "running")
    monkeypatch.setattr(drv, "_domain_mac", lambda d: "52:54:00:00:00:07")
    monkeypatch.setattr(drv_mod, "run", lambda argv, **k: ran.append(argv))
    monkeypatch.setattr(drv.cloudinit, "build", lambda **k: tmp_path / f"{k['name']}-seed.iso")

    drv.rescue("u", RescueRequest(action="start", template="debian-13.qcow2", root_password="Ratunek123!"))
    assert ran[0][:4] == ["qemu-img", "create", "-f", "qcow2"] and ran[0][-2] == str(template)
    assert _disks(defined[-1])["vdi"].find("source").get("file") == "/d/virthub-7.qcow2"
    assert drv._rescue_file("virthub-7").read_text() == original
    with pytest.raises(drv_mod.DriverError):
        drv._assert_not_elevated("virthub-7")

    drv.rescue("u", RescueRequest(action="stop"))
    assert defined[-1] == original, "Powrót do dokładnie tej samej definicji"
    assert not drv._rescue_file("virthub-7").exists() and domain.active
