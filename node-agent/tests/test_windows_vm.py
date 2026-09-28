"""Maszyny Windows: nośnik config-2 dla cloudbase-init zamiast NoCloud."""

from __future__ import annotations

import json

from agent.cloudinit import render_network_data, render_windows_meta_data
from agent.schemas import NetworkInterfaceSpec
from test_provisioning import create_vm


def test_meta_data_z_haslem_administratora():
    data = json.loads(render_windows_meta_data(7, "win7.example.com", "Haslo#123abc"))
    assert data["admin_pass"] == "Haslo#123abc"
    assert data["hostname"] == "win7.example.com" and data["name"] == "win7"
    assert "admin_pass" not in json.loads(render_windows_meta_data(7, "w", None))


def test_network_data_ipv4_i_ipv6():
    data = json.loads(render_network_data([
        NetworkInterfaceSpec(address="203.0.113.14", prefix=24, gateway="203.0.113.1", version=4),
        NetworkInterfaceSpec(address="2001:db8::14", prefix=64, gateway="2001:db8::1", version=6),
    ], ["1.1.1.1"], "52:54:00:00:00:07"))

    assert data["links"][0]["ethernet_mac_address"] == "52:54:00:00:00:07"
    v4, v6 = data["networks"]
    assert (v4["type"], v4["ip_address"], v4["netmask"]) == ("ipv4", "203.0.113.14", "255.255.255.0")
    assert v4["routes"][0]["gateway"] == "203.0.113.1"
    assert (v6["type"], v6["netmask"]) == ("ipv6", "ffff:ffff:ffff:ffff::")
    assert data["services"] == [{"type": "dns", "address": "1.1.1.1"}]


def test_maszyna_windows_dostaje_config_2(client, template, settings):
    job = create_vm(client, template, server_id=4201, os_type="windows", root_password="Haslo#123abc")
    assert job["status"] == "done", job

    seed = (settings.seed_dir / "virthub-4201-seed.iso").read_text()
    assert "openstack/latest/meta_data.json" in seed and '"admin_pass": "Haslo#123abc"' in seed
    assert "#cloud-config" not in seed


def test_reset_hasla_administratora_przez_api(client, template):
    uuid = create_vm(client, template, server_id=4202, os_type="windows")["result"]["uuid"]
    response = client.post(f"/vm/{uuid}/password", json={"password": "Nowe#Haslo123", "username": "Administrator"})
    assert response.status_code == 202
    bad = client.post(f"/vm/{uuid}/password", json={"password": "Nowe#Haslo123", "username": "admin"})
    assert bad.status_code == 422
