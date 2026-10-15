"""Sprzęt maszyny (magistrala, I/O, UEFI, vTPM, CPU, cpuset) i dodatkowe dyski."""

from __future__ import annotations

import sys
import types
import xml.etree.ElementTree as ET

import pytest
from pydantic import ValidationError

from agent.domain_xml import build_domain_xml, disk_dev, with_hardware
from agent.schemas import DisksRequest, HardwareRequest, HardwareSpec
from test_provisioning import create_vm, wait_for_job


def _xml(**extra):
    return build_domain_xml(name="virthub-5", uuid="u", vcpu=2, ram_mb=1024, disk_path="/d/virthub-5.qcow2", seed_path="/s.iso",
                            bridge="vhbr0", mac="52:54:00:00:00:05", interface_target="vh5", vnc_listen="127.0.0.1", vnc_password="p", **extra)


def test_domyslny_sprzet_bez_zmian():
    assert _xml() == _xml(hardware=None)
    root = ET.fromstring(_xml(hardware=HardwareSpec()))
    assert root.find("cpu").get("mode") == "host-passthrough"
    assert root.find("os").get("firmware") is None
    assert root.find("./devices/tpm") is None
    assert root.find("./devices/disk/target").get("bus") == "virtio"


def test_uefi_tpm_scsi_io_uring_model_cpu_i_cpuset():
    hw = HardwareSpec(disk_bus="scsi", io_mode="io_uring", firmware="uefi", tpm=True, cpu_model="EPYC-Milan", cpuset="0-7,16-23")
    root = ET.fromstring(_xml(hardware=hw, extra_disks=["    <disk type='file' device='disk'><driver name='qemu' type='qcow2' io='native'/>"
                                                         "<source file='/d/x'/><target dev='vdb' bus='virtio'/></disk>"]))
    assert root.find("vcpu").get("cpuset") == "0-7,16-23"
    assert root.find("os").get("firmware") == "efi"
    assert {f.get("name"): f.get("enabled") for f in root.findall("./os/firmware/feature")} == {"enrolled-keys": "no", "secure-boot": "no"}
    assert root.find("./devices/tpm/backend").get("version") == "2.0"
    cpu = root.find("cpu")
    assert cpu.get("mode") == "custom" and cpu.find("model").text == "EPYC-Milan"
    assert {f.get("name") for f in cpu.findall("feature")} == {"vmx", "svm"}
    disks = [d for d in root.findall("./devices/disk") if d.get("device") == "disk"]
    assert [d.find("target").get("dev") for d in disks] == ["vda", "vdb"]
    assert all(d.find("target").get("bus") == "scsi" and d.find("driver").get("io") == "io_uring" for d in disks)
    controller = root.find("./devices/controller[@type='scsi']")
    assert controller.get("model") == "virtio-scsi" and controller.find("driver").get("iothread") == "1"
    assert root.find("iothreads").text == "1"
    # Płyty CD zostają na SATA.
    assert root.find("./devices/disk[@device='cdrom']/target").get("bus") == "sata"


def test_powrot_do_bios_i_virtio_sprzata():
    uefi = _xml(hardware=HardwareSpec(disk_bus="scsi", firmware="uefi", tpm=True, cpu_model="host-model"))
    back = ET.fromstring(with_hardware(uefi, HardwareSpec(), allow_nested=True))
    assert back.find("os").get("firmware") is None and back.find("./os/firmware") is None
    assert back.find("./devices/tpm") is None and back.find("./devices/controller[@type='scsi']") is None
    assert back.find("iothreads") is None
    assert back.find("cpu").get("mode") == "host-passthrough" and back.find("cpu").findall("feature") == []
    assert back.find("./devices/disk/target").get("bus") == "virtio"


def test_walidacja():
    with pytest.raises(ValidationError):
        HardwareSpec(tpm=True)
    with pytest.raises(ValidationError):
        HardwareSpec(cpuset="0-7; rm -rf")
    with pytest.raises(ValidationError):
        HardwareSpec(cpu_model="<script>")
    with pytest.raises(ValidationError):
        DisksRequest(disks=[{"slot": 1, "size_gb": 5}, {"slot": 1, "size_gb": 6}])
    assert [disk_dev(i) for i in (0, 1, 7)] == ["vda", "vdb", "vdh"]


def test_dyski_w_trybie_mock(client, template, settings):
    vm = create_vm(client, template, server_id=2001, extra_disks=[{"slot": 1, "size_gb": 10}], hardware={"firmware": "uefi", "tpm": True})["result"]
    assert (settings.image_dir / "virthub-2001-d1.qcow2").exists()

    def put(body):
        return wait_for_job(client, client.put(f"/vm/{vm['uuid']}/disks", json=body).json()["job_id"])

    job = put({"disks": [{"slot": 1, "size_gb": 20}, {"slot": 2, "size_gb": 5}], "primary_gb": 30})
    assert job["status"] == "done", job.get("error")
    assert job["result"]["changed"] == ["vda", "vdb", "vdc"]
    assert job["result"]["disks"] == [{"slot": 0, "dev": "vda", "size_gb": 30}, {"slot": 1, "dev": "vdb", "size_gb": 20}, {"slot": 2, "dev": "vdc", "size_gb": 5}]

    shrink = put({"disks": [{"slot": 1, "size_gb": 10}, {"slot": 2, "size_gb": 5}]})
    assert shrink["status"] == "failed" and "zmniejszanie" in shrink["error"]
    unconfirmed = put({"disks": [{"slot": 1, "size_gb": 20}]})
    assert unconfirmed["status"] == "failed" and "vdc" in unconfirmed["error"]
    removed = put({"disks": [{"slot": 1, "size_gb": 20}], "delete_removed": True})
    assert removed["status"] == "done" and removed["result"]["changed"] == ["vdc"]
    assert not (settings.image_dir / "virthub-2001-d2.qcow2").exists()

    hw = wait_for_job(client, client.put(f"/vm/{vm['uuid']}/hardware", json={"hardware": {"disk_bus": "scsi"}}).json()["job_id"])
    assert hw["status"] == "done" and hw["result"]["restart_required"] is True

    assert wait_for_job(client, client.delete(f"/vm/{vm['uuid']}").json()["job_id"])["status"] == "done"
    assert not (settings.image_dir / "virthub-2001-d1.qcow2").exists()


class _Domain:
    def __init__(self, xml, active=True):
        self.xml, self.active, self.calls = xml, active, []

    def name(self):
        return "virthub-5"

    def isActive(self):
        return self.active

    def XMLDesc(self, flags):
        return self.xml

    def attachDeviceFlags(self, xml, flags):
        self.calls.append(("attach", ET.fromstring(xml).find("target").attrib, flags))

    def detachDeviceFlags(self, xml, flags):
        self.calls.append(("detach", ET.fromstring(xml).find("target").get("dev"), flags))

    def blockResize(self, dev, size, flags):
        self.calls.append(("resize", dev, size))


@pytest.fixture()
def fake_libvirt(monkeypatch):
    fake = types.SimpleNamespace(VIR_DOMAIN_XML_INACTIVE=2, VIR_DOMAIN_AFFECT_CONFIG=2, VIR_DOMAIN_AFFECT_LIVE=1, VIR_DOMAIN_BLOCK_RESIZE_BYTES=1,
                                 VIR_DOMAIN_UNDEFINE_NVRAM=4, VIR_DOMAIN_UNDEFINE_TPM=64, libvirtError=type("libvirtError", (Exception,), {}))
    monkeypatch.setitem(sys.modules, "libvirt", fake)
    return fake


def test_libvirt_dyski_na_zywo(settings, monkeypatch, fake_libvirt):
    from agent.driver import LibvirtDriver

    xml = _xml(hardware=HardwareSpec(disk_bus="scsi"),
               extra_disks=["<disk type='file' device='disk'><driver name='qemu' type='qcow2'/><source file='/d/b'/><target dev='vdb' bus='scsi'/></disk>",
                            "<disk type='file' device='disk'><driver name='qemu' type='qcow2'/><source file='/d/c'/><target dev='vdc' bus='scsi'/></disk>"])
    domain = _Domain(xml)
    drv = LibvirtDriver(settings)
    monkeypatch.setattr(drv, "_domain", lambda uuid: domain)
    monkeypatch.setattr(drv, "_state", lambda d: "running")
    sizes = {"virthub-5": 20, "virthub-5-d1": 10, "virthub-5-d2": 5, "virthub-5-d4": 8}
    created, deleted = [], []
    monkeypatch.setattr(drv.storage, "size_gb", lambda n: sizes[n])
    monkeypatch.setattr(drv.storage, "create_blank", lambda n, gb: created.append((n, gb)))
    monkeypatch.setattr(drv.storage, "delete_volume", lambda n: deleted.append(n))
    monkeypatch.setattr(drv.storage, "has_overlay", lambda n: False)

    result = drv.set_disks("u", DisksRequest(disks=[{"slot": 1, "size_gb": 15}, {"slot": 4, "size_gb": 8}], primary_gb=40, delete_removed=True))
    assert ("resize", "vda", 40 * 1024**3) in domain.calls and ("resize", "vdb", 15 * 1024**3) in domain.calls
    assert ("detach", "vdc", 3) in domain.calls
    attach = next(c for c in domain.calls if c[0] == "attach")
    assert attach[1] == {"dev": "vde", "bus": "scsi"} and attach[2] == 3, "Nowy dysk na tej samej magistrali co główny"
    assert created == [("virthub-5-d4", 8)] and deleted == ["virthub-5-d2"]
    assert result["changed"] == ["vda", "vdc", "vdb", "vde"]


def test_libvirt_sprzet_i_usuwanie_z_nvram(settings, monkeypatch, fake_libvirt):
    from agent.driver import LibvirtDriver

    domain = _Domain(_xml())
    defined = []
    drv = LibvirtDriver(settings)
    drv._conn = types.SimpleNamespace(defineXML=lambda x: defined.append(x), isAlive=lambda: True)
    monkeypatch.setattr(type(drv), "conn", property(lambda self: self._conn))
    monkeypatch.setattr(drv, "_domain", lambda uuid: domain)
    monkeypatch.setattr(drv, "_state", lambda d: "running")
    monkeypatch.setattr(drv, "nested_enabled", lambda: False)
    result = drv.set_hardware("u", HardwareRequest(hardware={"firmware": "uefi", "tpm": True}))
    assert result["restart_required"] is True
    assert ET.fromstring(defined[0]).find("os").get("firmware") == "efi"

    flags = []
    LibvirtDriver._undefine(types.SimpleNamespace(undefineFlags=lambda f: flags.append(f)))
    assert flags == [4 | 64]


def test_profile_hyperv():
    # Brak ustawienia: Windows ma zestaw standardowy, Linux bez oświeceń.
    win = ET.fromstring(_xml(windows=True, hardware=HardwareSpec()))
    assert win.find("./features/hyperv").get("mode") == "custom"
    assert ET.fromstring(_xml(hardware=HardwareSpec())).find("./features/hyperv") is None

    off = ET.fromstring(_xml(windows=True, hardware=HardwareSpec(hyperv="off")))
    assert off.find("./features/hyperv") is None and off.find("./clock/timer[@name='hypervclock']") is None

    full = ET.fromstring(_xml(hardware=HardwareSpec(hyperv="full")))
    names = {el.tag for el in full.find("./features/hyperv")}
    assert {"relaxed", "vapic", "spinlocks", "frequencies", "tlbflush", "ipi", "vendor_id", "reset"} <= names
    assert full.find("./features/hyperv/vendor_id").get("value") == "VirtHubKVM"
    assert full.find("./clock/timer[@name='tsc']").get("mode") == "native"
    assert len(full.findall("./clock/timer[@name='hypervclock']")) == 1

    passthrough = ET.fromstring(with_hardware(ET.tostring(full, encoding="unicode"), HardwareSpec(hyperv="passthrough")))
    assert len(passthrough.findall("./features/hyperv")) == 1
    assert passthrough.find("./features/hyperv").get("mode") == "passthrough" and list(passthrough.find("./features/hyperv")) == []
    assert passthrough.find("./clock/timer[@name='tsc']") is None

    with pytest.raises(ValidationError):
        HardwareSpec(hyperv="turbo")
