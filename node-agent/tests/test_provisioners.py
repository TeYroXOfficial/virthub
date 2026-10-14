"""Provisionery: skrypty pierwszego uruchomienia z panelu w cloud-init."""

from __future__ import annotations

import base64

import pytest
import yaml
from pydantic import ValidationError

from agent.cloudinit import render_user_data
from agent.schemas import CreateVmRequest, ProvisionScript
from tests.test_provisioning import create_vm


def test_skrypty_trafiaja_do_write_files_i_runcmd():
    data = yaml.safe_load(render_user_data(
        "vm1", [], "Haslo-12345",
        scripts=[
            ProvisionScript(name="docker", content="#!/bin/bash\ncurl -fsSL https://get.docker.com | sh\n"),
            ProvisionScript(name="motd", content="#!/bin/sh\necho 'it works' > /etc/motd\n", path="/root/setup/"),
        ],
    ))

    files = {f["path"]: f for f in data["write_files"]}
    assert "/etc/ssh/sshd_config.d/10-virthub.conf" in files
    docker = files["/var/lib/virthub/provision/01-docker"]
    assert docker["permissions"] == "0700"
    assert base64.b64decode(docker["content"]).decode().startswith("#!/bin/bash\ncurl")
    assert "/root/setup/02-motd" in files

    commands = [c[2] for c in data["runcmd"] if isinstance(c, list) and len(c) == 3]
    # Skrypty na końcu, po kolei, z logiem.
    assert "/var/lib/virthub/provision/01-docker > /var/log/virthub-provision/01-docker.log" in commands[-2]
    assert "/root/setup/02-motd" in commands[-1]


def test_bez_hasla_i_bez_skryptow_nie_ma_write_files():
    data = yaml.safe_load(render_user_data("vm1", [], None))
    assert "write_files" not in data


def test_skrypt_musi_miec_shebang_i_bezpieczna_sciezke():
    with pytest.raises(ValidationError):
        ProvisionScript(name="x", content="echo bez shebang")
    with pytest.raises(ValidationError):
        ProvisionScript(name="x", content="#!/bin/sh\n", path="/tmp/../etc")
    with pytest.raises(ValidationError):
        ProvisionScript(name="x", content="#!/bin/sh\n", path="/tmp/a b")
    with pytest.raises(ValidationError):
        ProvisionScript(name="Zła nazwa", content="#!/bin/sh\n")


def test_zadanie_tworzenia_przyjmuje_skrypty(client, template):
    job = create_vm(client, template, server_id=1077, scripts=[{"name": "hello", "content": "#!/bin/sh\necho hi\n"}])
    assert job["status"] == "done", job.get("error")


def test_api_odrzuca_skrypt_bez_shebang(client, template):
    response = client.post("/vm", json={
        "server_id": 1078, "hostname": "x.example.test", "vcpu": 1, "ram_mb": 512, "disk_gb": 5,
        "template": template, "scripts": [{"name": "zly", "content": "echo hi"}],
    })
    assert response.status_code == 422


def test_limit_liczby_skryptow():
    with pytest.raises(ValidationError):
        CreateVmRequest(server_id=1, hostname="a.test", vcpu=1, ram_mb=512, disk_gb=5, template="t",
                        scripts=[{"name": f"s{i}", "content": "#!/bin/sh\n"} for i in range(21)])
