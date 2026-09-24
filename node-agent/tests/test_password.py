"""Reset hasła roota w działającej maszynie."""

from __future__ import annotations

from agent.cloudinit import VM_PACKAGES, render_user_data
from test_provisioning import create_vm, wait_for_job


def test_reset_hasla_dzialajacej_maszyny(client, template):
    uuid = create_vm(client, template, server_id=3201)["result"]["uuid"]

    job = client.post(f"/vm/{uuid}/password", json={"password": "Nowe-Haslo-123"})
    assert job.status_code == 202
    assert wait_for_job(client, job.json()["job_id"])["status"] == "done"


def test_reset_hasla_wymaga_dzialajacej_maszyny(client, template):
    uuid = create_vm(client, template, server_id=3202)["result"]["uuid"]
    stop = client.post(f"/vm/{uuid}/power", json={"action": "force-off"})
    wait_for_job(client, stop.json()["job_id"])

    job = client.post(f"/vm/{uuid}/password", json={"password": "Nowe-Haslo-123"})
    state = wait_for_job(client, job.json()["job_id"])
    assert state["status"] == "failed"
    assert "musi działać" in state["error"]


def test_haslo_nie_moze_dopisac_innego_konta(client, template):
    uuid = create_vm(client, template, server_id=3203)["result"]["uuid"]

    for bad in ["krotkie", "haslo\nadmin:x1234567", "ze spacja 123"]:
        assert client.post(f"/vm/{uuid}/password", json={"password": bad}).status_code == 422


def test_maszyny_kvm_dostaja_guest_agenta():
    data = render_user_data("vps", [], "haslo12345", packages=VM_PACKAGES)
    assert "qemu-guest-agent" in data
    assert "systemctl enable --now qemu-guest-agent" in data
