"""Izolacja maszyn od węzła: walidacja wejścia, ochrona usług węzła, stan reguł."""

from __future__ import annotations

import json

from agent.network import NetworkManager
from agent.shell import CommandError, run_bounded
from test_provisioning import create_vm

import pytest


@pytest.mark.parametrize("field,value", [
    ("ssh_keys", ["ssh-ed25519 AAAA\nruncmd: [reboot]"]),
    ("ssh_keys", ["ssh-ed25519 AAAAC3Nz x\n#cloud-config"]),
    ("hostname", "vps\nruncmd: [id]"),
    ("root_password", "haslo\nroot:inne"),
])
def test_wstrzykniecie_do_cloud_init_jest_odrzucane(client, template, field, value):
    payload = {
        "server_id": 3501, "hostname": "vps.example.com", "vcpu": 1, "ram_mb": 512, "disk_gb": 10,
        "template": template, "interfaces": [], field: value,
    }
    assert client.post("/vm", json=payload).status_code == 422


def test_poprawny_klucz_z_komentarzem_przechodzi(client, template):
    result = create_vm(client, template, server_id=3502,
                       ssh_keys=["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGtest jan@laptop"])
    assert result["status"] == "done"


def test_regula_sieci_zapisana_do_odtworzenia_i_sprzatana(settings):
    from agent.schemas import FirewallPolicy, FirewallRule, NetworkInterfaceSpec

    n = NetworkManager(settings)
    n.configure(
        3503,
        [NetworkInterfaceSpec(address="203.0.113.14", prefix=24, gateway="203.0.113.1", version=4)],
        [FirewallRule(action="accept", direction="in", protocol="tcp", port_from=22)],
        FirewallPolicy(enabled=True, inbound="drop", outbound="accept"),
    )
    state = json.loads((settings.network_state_dir / "3503.json").read_text())
    assert state["interfaces"][0]["address"] == "203.0.113.14"
    assert state["firewall"][0]["port_from"] == 22
    assert state["policy"]["inbound"] == "drop"

    n.teardown(3503)
    assert not (settings.network_state_dir / "3503.json").exists()


def test_ochrona_wezla_w_regulach(settings):
    n = NetworkManager(settings)
    mark = n.render_guard_mark()
    guard = n.render_guard()

    assert mark.startswith("insert rule bridge"), "przed skokiem do łańcucha maszyny"
    assert 'iifname "vh*"' in mark
    assert "ct state established,related accept" in guard
    assert "input counter drop" in guard
    assert "forward meta mark set meta mark & 0xf7ffffff" in guard, "ruch przez węzeł bez znacznika"


def test_ograniczony_odczyt_wyjscia():
    assert run_bounded(["echo", "ok"], max_bytes=100) == "ok\n"
    with pytest.raises(CommandError, match="limit"):
        run_bounded(["yes"], max_bytes=4096)
    with pytest.raises(CommandError, match="czasu"):
        run_bounded(["sleep", "5"], max_bytes=10, timeout=1)

