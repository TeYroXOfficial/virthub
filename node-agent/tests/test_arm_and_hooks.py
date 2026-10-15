"""Węzły ARM (aarch64) i skrypty administratora (hook DR)."""

from __future__ import annotations

import xml.etree.ElementTree as ET

from agent import driver as driver_mod
from agent.domain_xml import build_domain_xml, with_hardware
from agent.hooks import run_hook
from agent.schemas import HardwareSpec
from test_provisioning import wait_for_job


def _xml(**extra):
    return build_domain_xml(name="virthub-5", uuid="u", vcpu=2, ram_mb=1024, disk_path="/d/virthub-5.qcow2", seed_path="/s.iso",
                            bridge="vhbr0", mac="52:54:00:00:00:05", interface_target="vh5", vnc_listen="127.0.0.1", vnc_password="p", **extra)


def test_domena_arm():
    root = ET.fromstring(_xml(arch="aarch64", windows=True))
    t = root.find("./os/type")
    assert (t.get("arch"), t.get("machine")) == ("aarch64", "virt")
    assert root.find("os").get("firmware") == "efi"
    assert root.find("./features/gic").get("version") == "3"
    assert root.find("./features/apic") is None and root.find("./features/hyperv") is None
    assert root.find("cpu").findall("feature") == [] and root.find("./clock/timer") is None
    assert root.find("./devices/emulator").text == "/usr/bin/qemu-system-aarch64"
    assert root.find("./devices/serial/target").get("type") == "system-serial"
    assert root.find("./devices/disk[@device='cdrom']/target").get("bus") == "scsi"
    assert root.find("./devices/controller[@type='scsi']").get("model") == "virtio-scsi"
    assert {i.get("type") for i in root.findall("./devices/input")} == {"tablet", "keyboard"}

    # Sprzęt na ARM: zawsze UEFI, procesor hosta, bez vmx/svm i Hyper-V, vTPM jako tpm-tis-device.
    hw = ET.fromstring(with_hardware(_xml(arch="aarch64"), HardwareSpec(firmware="bios", cpu_model="EPYC-Milan", hyperv="full", tpm=False)))
    assert hw.find("os").get("firmware") == "efi"
    assert hw.find("cpu").get("mode") == "host-passthrough" and hw.find("cpu").findall("feature") == []
    assert hw.find("./features/hyperv") is None
    tpm = ET.fromstring(with_hardware(_xml(arch="aarch64"), HardwareSpec(firmware="uefi", tpm=True)))
    assert tpm.find("./devices/tpm").get("model") == "tpm-tis-device"

    # x86 bez zmian.
    assert _xml() == _xml(arch="x86_64")


def test_architektura_w_raporcie(client, monkeypatch):
    monkeypatch.setattr(driver_mod.platform, "machine", lambda: "arm64")
    assert driver_mod.host_arch() == "aarch64"
    assert client.get("/health").json()["arch"] == "aarch64"


def test_skrypt_hook(client, tmp_path):
    ok = run_hook("dr-pre", "echo \"$VH_PHASE na $VH_REPLACEMENT_NODE\"", {"VH_PHASE": "pre", "VH_REPLACEMENT_NODE": "kvm-2", "PATH_INJECT": "x"})
    assert ok["ok"] is True and ok["output"].strip() == "pre na kvm-2"
    bad = run_hook("dr-post", "echo przed; exit 3")
    assert bad["ok"] is False and bad["exit_code"] == 3

    job = wait_for_job(client, client.post("/system/hook", json={"name": "dr-post", "script": "echo gotowe", "env": {"VH_PHASE": "post"}}).json()["job_id"])
    assert job["status"] == "done" and job["result"]["output"].strip() == "gotowe"
    failed = wait_for_job(client, client.post("/system/hook", json={"name": "dr-post", "script": "exit 2"}).json()["job_id"])
    assert failed["status"] == "failed" and "kodem 2" in failed["error"]
    assert client.post("/system/hook", json={"name": "zła nazwa", "script": "x"}).status_code == 422
