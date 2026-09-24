"""Pełny cykl życia VPS-a przez API agenta (tryb mock)."""

from __future__ import annotations

import time

import pytest


def wait_for_job(client, job_id, timeout=15):
    """Kolejka jest asynchroniczna — czekamy na wynik, nie na odpowiedź HTTP."""
    deadline = time.time() + timeout
    while time.time() < deadline:
        state = client.get(f"/jobs/{job_id}").json()
        if state["status"] in {"done", "failed"}:
            return state
        time.sleep(0.05)
    pytest.fail(f"Zadanie {job_id} nie zakończyło się w {timeout}s")


def create_vm(client, template, server_id=1001, **overrides):
    payload = {
        "server_id": server_id,
        "hostname": f"vps{server_id}.example.com",
        "vcpu": 2,
        "ram_mb": 2048,
        "disk_gb": 20,
        "template": template,
        "interfaces": [
            {"address": "203.0.113.14", "prefix": 24, "gateway": "203.0.113.1", "version": 4}
        ],
        "ssh_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI test@virthub"],
        "root_password": "TajneHaslo123",
        **overrides,
    }
    response = client.post("/vm", json=payload)
    assert response.status_code == 202, response.text
    return wait_for_job(client, response.json()["job_id"])


def test_tworzenie_vps_konczy_sie_dzialajaca_maszyna(client, template):
    job = create_vm(client, template, server_id=1001)

    assert job["status"] == "done", job.get("error")
    result = job["result"]
    assert result["state"] == "running"
    assert result["name"] == "virthub-1001"
    assert result["mac"] == "52:54:00:00:03:e9"
    assert result["interface"] == "vh1001"
    assert result["vnc_port"]


def test_dysk_i_nosnik_cloud_init_powstaja_na_dysku(client, template, settings):
    create_vm(client, template, server_id=1002)

    assert (settings.image_dir / "virthub-1002.qcow2").exists()
    seed = settings.seed_dir / "virthub-1002-seed.iso"
    assert seed.exists()

    content = seed.read_text(encoding="utf-8")
    assert "vps1002.example.com" in content
    assert "ssh-ed25519" in content
    assert "203.0.113.14/24" in content


def test_operacje_zasilania_zmieniaja_stan(client, template):
    uuid = create_vm(client, template, server_id=1003)["result"]["uuid"]

    for action, expected in [("stop", "stopped"), ("start", "running"), ("reboot", "running")]:
        response = client.post(f"/vm/{uuid}/power", json={"action": action})
        assert response.status_code == 202
        job = wait_for_job(client, response.json()["job_id"])
        assert job["status"] == "done"
        assert job["result"]["state"] == expected


def test_zmiana_pakietu_wymaga_zatrzymanej_maszyny(client, template):
    uuid = create_vm(client, template, server_id=1004)["result"]["uuid"]

    response = client.post(f"/vm/{uuid}/resize", json={"vcpu": 4, "ram_mb": 4096, "disk_gb": 40})
    job = wait_for_job(client, response.json()["job_id"])
    assert job["status"] == "failed"
    assert "zatrzymanej maszyny" in job["error"]

    wait_for_job(client, client.post(f"/vm/{uuid}/power", json={"action": "stop"}).json()["job_id"])

    response = client.post(f"/vm/{uuid}/resize", json={"vcpu": 4, "ram_mb": 4096, "disk_gb": 40})
    job = wait_for_job(client, response.json()["job_id"])
    assert job["status"] == "done"
    assert job["result"]["vcpu"] == 4


def test_usuniecie_vps_sprzata_dysk(client, template, settings):
    uuid = create_vm(client, template, server_id=1005)["result"]["uuid"]
    disk = settings.image_dir / "virthub-1005.qcow2"
    assert disk.exists()

    response = client.delete(f"/vm/{uuid}")
    job = wait_for_job(client, response.json()["job_id"])

    assert job["status"] == "done"
    assert not disk.exists(), "Dysk usuniętego VPS-a nie może zostać na hoście"
    assert client.get(f"/vm/{uuid}/stats").status_code == 404


def test_statystyki_dzialajacej_maszyny(client, template):
    uuid = create_vm(client, template, server_id=1006)["result"]["uuid"]

    stats = client.get(f"/vm/{uuid}/stats").json()
    assert stats["state"] == "running"
    assert stats["ram_total_mb"] == 2048
    assert stats["ram_used_mb"] > 0


def test_snapshot_i_przywrocenie(client, template):
    uuid = create_vm(client, template, server_id=1007)["result"]["uuid"]

    job = wait_for_job(
        client, client.post(f"/vm/{uuid}/snapshot", json={"name": "przed-aktualizacja"}).json()["job_id"]
    )
    assert job["status"] == "done"

    job = wait_for_job(
        client,
        client.post(f"/vm/{uuid}/snapshot/restore", json={"name": "przed-aktualizacja"}).json()["job_id"],
    )
    assert job["status"] == "done"


def test_nieznana_maszyna_zwraca_404(client):
    assert client.get("/vm/00000000-0000-0000-0000-000000000000/stats").status_code == 404


def test_health_raportuje_zasoby_hosta(client):
    health = client.get("/health").json()

    assert health["driver"] == "mock"
    assert health["cpu_cores_total"] > 0
    assert health["ram_mb_total"] > 0
    assert health["disk_gb_total"] > 0


def test_walidacja_odrzuca_bezsensowne_parametry(client, template):
    response = client.post("/vm", json={
        "server_id": 1,
        "hostname": "test",
        "vcpu": 0,           # poniżej minimum
        "ram_mb": 64,        # poniżej minimum
        "disk_gb": 10,
        "template": template,
    })
    assert response.status_code == 422


def test_usuniecie_nieistniejacej_maszyny_konczy_sie_sukcesem(client):
    """Maszyny skasowanej ręcznie nie da się usunąć drugi raz — i nie trzeba."""
    job = client.delete("/vm/00000000-0000-4000-8000-000000000000")
    state = wait_for_job(client, job.json()["job_id"])
    assert state["status"] == "done"
    assert state["result"]["already_absent"] is True
