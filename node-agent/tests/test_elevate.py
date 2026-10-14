"""Elevate: nakładka na dysk — start, zatwierdzenie, odrzucenie."""

from __future__ import annotations

import shutil
import subprocess
from xml.etree import ElementTree as ET

import pytest

from agent.domain_xml import build_domain_xml, with_disk_source
from agent.storage import Qcow2LocalDriver
from test_provisioning import create_vm, wait_for_job


def elevate(client, uuid, action):
    response = client.post(f"/vm/{uuid}/elevate", json={"action": action})
    assert response.status_code == 202, response.text
    return wait_for_job(client, response.json()["job_id"])


def test_cykl_przez_api(client, template, settings):
    vm = create_vm(client, template, server_id=1301)["result"]
    overlay = settings.image_dir / "virthub-1301.elevate.qcow2"

    job = elevate(client, vm["uuid"], "start")
    assert job["status"] == "done", job.get("error")
    assert job["result"]["elevated"] is True
    assert overlay.exists()

    # Drugi start i migawka w trakcie są odrzucane.
    assert elevate(client, vm["uuid"], "start")["status"] == "failed"
    snap = client.post(f"/vm/{vm['uuid']}/snapshot", json={"name": "s1"})
    if snap.status_code == 202:
        assert wait_for_job(client, snap.json()["job_id"])["status"] == "failed"

    job = elevate(client, vm["uuid"], "commit")
    assert job["status"] == "done", job.get("error")
    assert job["result"]["elevated"] is False
    assert not overlay.exists()

    elevate(client, vm["uuid"], "start")
    job = elevate(client, vm["uuid"], "discard")
    assert job["result"]["elevated"] is False
    assert elevate(client, vm["uuid"], "discard")["status"] == "failed"


def test_nieznana_akcja(client, template):
    vm = create_vm(client, template, server_id=1302)["result"]
    assert client.post(f"/vm/{vm['uuid']}/elevate", json={"action": "boom"}).status_code == 422


def test_podmiana_zrodla_dysku():
    xml = build_domain_xml(
        name="virthub-5", uuid="6f1d2a4e-9c3b-4b8e-8f2a-1d2c3b4a5e6f", vcpu=1, ram_mb=512, disk_path="/d/virthub-5.qcow2",
        seed_path="/seed.iso", bridge="br0", mac="52:54:00:00:00:05", interface_target="vh5", vnc_listen="127.0.0.1", vnc_password="x",
    )
    out = ET.fromstring(with_disk_source(xml, "/d/virthub-5.elevate.qcow2"))
    sources = {d.find("target").get("dev"): d.find("source").get("file") for d in out.find("devices").findall("disk")}
    assert sources["vda"] == "/d/virthub-5.elevate.qcow2"
    assert sources["sda"] == "/seed.iso"


@pytest.mark.skipif(not shutil.which("qemu-img"), reason="wymaga qemu-img")
def test_qcow2_nakladka_offline_zatwierdzenie(settings, tmp_path):
    settings.image_dir = tmp_path
    storage = Qcow2LocalDriver(settings)
    base = storage.volume_path("virthub-9")
    subprocess.run(["qemu-img", "create", "-f", "qcow2", str(base), "64M"], check=True, capture_output=True)

    overlay = storage.create_overlay("virthub-9")
    info = subprocess.run(["qemu-img", "info", str(overlay)], check=True, capture_output=True, text=True).stdout
    assert str(base) in info
    storage.commit_overlay("virthub-9")
    assert not overlay.exists()
