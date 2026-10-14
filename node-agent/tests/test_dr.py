"""Disaster recovery: odtworzenie maszyny z kopii na innym węźle i zrzut konfiguracji węzła."""

from __future__ import annotations

import hashlib
import json

from test_provisioning import create_vm, wait_for_job


def _payload(template, server_id, **extra):
    return {
        "server_id": server_id,
        "hostname": f"vps{server_id}.example.com",
        "vcpu": 2,
        "ram_mb": 2048,
        "disk_gb": 20,
        "template": template,
        "interfaces": [{"address": "203.0.113.30", "prefix": 24, "gateway": "203.0.113.1", "version": 4}],
        **extra,
    }


def test_odtworzenie_maszyny_z_kopii(client, template, tmp_path, settings):
    target = {"type": "local", "config": {"path": str(tmp_path / "dr")}}
    vm = create_vm(client, template, server_id=1801)["result"]
    backup = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups", json={"key": "vm1801/latest.qcow2", "target": target}).json()["job_id"])
    assert backup["status"] == "done", backup.get("error")
    original = (tmp_path / "dr" / "vm1801" / "latest.qcow2").read_bytes()

    # Węzeł „padł”: maszyny nie ma ani w hypervisorze, ani na dysku.
    assert wait_for_job(client, client.delete(f"/vm/{vm['uuid']}").json()["job_id"])["status"] == "done"
    disk = settings.image_dir / "virthub-1801.qcow2"
    assert not disk.exists()

    # Zła suma kontrolna: nic nie powstaje.
    bad = client.post("/vm/recover", json=_payload(template, 1801, backup={"key": "vm1801/latest.qcow2", "target": target, "sha256": "0" * 64}))
    failed = wait_for_job(client, bad.json()["job_id"])
    assert failed["status"] == "failed" and "Suma kontrolna" in failed["error"]
    assert not disk.exists()

    response = client.post("/vm/recover", json=_payload(template, 1801, backup={"key": "vm1801/latest.qcow2", "target": target, "sha256": backup["result"]["sha256"]}))
    assert response.status_code == 202, response.text
    job = wait_for_job(client, response.json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert job["result"]["uuid"] != vm["uuid"]
    assert disk.read_bytes() == original
    assert client.get(f"/vm/{job['result']['uuid']}/stats").status_code == 200

    # Drugie odtworzenie tej samej maszyny nie nadpisuje istniejącego dysku.
    again = wait_for_job(client, client.post("/vm/recover", json=_payload(template, 1801, backup={"key": "vm1801/latest.qcow2", "target": target})).json()["job_id"])
    assert again["status"] == "failed" and "już istnieje" in again["error"]


def test_zrzut_konfiguracji_wezla(client, template, tmp_path):
    target = {"type": "local", "config": {"path": str(tmp_path / "dr")}}
    create_vm(client, template, server_id=1802, storage={"id": 5, "type": "directory", "path": "/srv/ds5"})
    response = client.post("/system/dr-snapshot", json={"key": "node-1/config.json", "target": target})
    assert response.status_code == 202, response.text
    job = wait_for_job(client, response.json()["job_id"])
    assert job["status"] == "done", job.get("error")
    stored = tmp_path / "dr" / "node-1" / "config.json"
    data = json.loads(stored.read_text())
    assert data["format"] == "virthub-dr/1"
    assert any(d["name"] == "virthub-1802" for d in data["domains"])
    assert data["datastores"]["virthub-1802"]["path"] == "/srv/ds5"
    assert job["result"]["sha256"] == hashlib.sha256(stored.read_bytes()).hexdigest()
    assert job["result"]["domains"] == len(data["domains"])


def test_zrzut_bez_kluczy_cepha(client, tmp_path, settings):
    from agent.datastores import StorageRouter
    from agent.schemas import DatastoreSpec

    key = "AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ=="
    StorageRouter(settings).bind("virthub-1803", DatastoreSpec(id=9, type="ceph", pool="vms", monitors=["10.0.0.1"], key=key))
    target = {"type": "local", "config": {"path": str(tmp_path / "dr")}}
    job = wait_for_job(client, client.post("/system/dr-snapshot", json={"key": "n/c.json", "target": target}).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    text = (tmp_path / "dr" / "n" / "c.json").read_text()
    assert key not in text and '"pool": "vms"' in text
    StorageRouter(settings).unbind("virthub-1803")
