"""Rozpoznanie systemu w maszynie i procesora hosta."""

from __future__ import annotations

from agent.guest_os import cpu_model, from_guest_agent, parse_os_release
from test_provisioning import create_vm

UBUNTU = '''PRETTY_NAME="Ubuntu 24.04.1 LTS"
NAME="Ubuntu"
VERSION_ID="24.04"
ID=ubuntu
ID_LIKE=debian
'''


def test_os_release():
    info = parse_os_release(UBUNTU)
    assert info == {"id": "ubuntu", "id_like": "debian", "name": "Ubuntu",
                    "version": "24.04", "pretty_name": "Ubuntu 24.04.1 LTS"}


def test_os_release_bez_cudzyslowow_i_z_komentarzem():
    info = parse_os_release("# test\nID=alpine\nVERSION_ID=3.20.1\nNAME='Alpine Linux'\n")
    assert info["id"] == "alpine"
    assert info["pretty_name"] == "Alpine Linux"


def test_windows_z_guest_agenta():
    info = from_guest_agent({
        "os.id": "mswindows", "os.name": "Microsoft Windows",
        "os.pretty-name": "Windows Server 2022 Standard", "os.version-id": "2022",
    })
    assert info["id"] == "mswindows"
    assert info["pretty_name"] == "Windows Server 2022 Standard"


def test_model_procesora(tmp_path):
    cpuinfo = tmp_path / "cpuinfo"
    cpuinfo.write_text("processor\t: 0\nvendor_id\t: AuthenticAMD\nmodel name\t: AMD EPYC  7763 64-Core Processor\n")
    assert cpu_model(cpuinfo) == "AMD EPYC 7763 64-Core Processor"


def test_endpoint_systemu_i_procesor_w_heartbeat(client, template):
    uuid = create_vm(client, template, server_id=3401)["result"]["uuid"]

    os_info = client.get(f"/vm/{uuid}/os").json()
    assert os_info["id"] == "ubuntu"
    assert os_info["version"] == "24.04"
    assert "cpu_model" in client.get("/health").json()
