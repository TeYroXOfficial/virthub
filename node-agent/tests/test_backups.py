"""Kopie zapasowe na zewnętrzne cele: podpis S3, wysyłka wieloczęściowa, SFTP, katalog, API."""

from __future__ import annotations

import asyncio
import datetime as dt
import hashlib
import socket
import threading

import httpx
import pytest

from agent import backup_targets as bt
from test_provisioning import create_vm, wait_for_job


def test_podpis_sigv4_zgodny_z_przykladem_aws():
    # Przykład „GET Object” z dokumentacji Amazon S3 (Signature Version 4).
    headers = bt.sigv4_headers(
        "GET", "https://examplebucket.s3.amazonaws.com/test.txt", "us-east-1",
        "AKIAIOSFODNN7EXAMPLE", "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
        hashlib.sha256(b"").hexdigest(), now=dt.datetime(2013, 5, 24, tzinfo=dt.timezone.utc), extra={"Range": "bytes=0-9"},
    )
    assert headers["Authorization"].endswith("Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41")
    assert "SignedHeaders=host;range;x-amz-content-sha256;x-amz-date" in headers["Authorization"]


class FakeS3:
    def __init__(self):
        self.objects: dict[str, bytes] = {}
        self.parts: dict[int, bytes] = {}
        self.requests: list[tuple[str, str]] = []

    def handler(self, request: httpx.Request) -> httpx.Response:
        assert request.headers["authorization"].startswith("AWS4-HMAC-SHA256 Credential=AK/")
        path, query = request.url.path, request.url.query.decode()
        self.requests.append((request.method, path + ("?" + query if query else "")))
        if request.method == "POST" and query == "uploads":
            return httpx.Response(200, text="<InitiateMultipartUploadResult><UploadId>U1</UploadId></InitiateMultipartUploadResult>")
        if request.method == "PUT" and "partNumber" in query:
            number = int(dict(p.split("=") for p in query.split("&"))["partNumber"])
            self.parts[number] = request.content
            return httpx.Response(200, headers={"ETag": f'"e{number}"'})
        if request.method == "POST" and "uploadId" in query:
            assert b"<PartNumber>2</PartNumber>" in request.content
            self.objects[path] = b"".join(self.parts[i] for i in sorted(self.parts))
            return httpx.Response(200, text="<CompleteMultipartUploadResult/>")
        if request.method == "PUT":
            self.objects[path] = request.content
            return httpx.Response(200)
        if request.method == "GET":
            return httpx.Response(200, content=self.objects[path]) if path in self.objects else httpx.Response(404)
        if request.method == "DELETE":
            self.objects.pop(path, None)
            return httpx.Response(204)
        return httpx.Response(400)


def s3_target(fake: FakeS3) -> bt.S3Target:
    client = httpx.Client(transport=httpx.MockTransport(fake.handler))
    return bt.S3Target({"endpoint": "https://s3.example.test", "bucket": "kopie", "access_key": "AK", "secret_key": "SK", "prefix": "node1"}, client)


def test_s3_wysylka_pobranie_usuniecie(tmp_path):
    fake = FakeS3()
    target = s3_target(fake)
    source = tmp_path / "disk.qcow2"
    source.write_bytes(b"x" * 1000)

    target.upload(source, "vm1/2026-10-14.qcow2")
    assert fake.objects["/kopie/node1/vm1/2026-10-14.qcow2"] == b"x" * 1000
    out = tmp_path / "back.qcow2"
    target.download("vm1/2026-10-14.qcow2", out)
    assert out.read_bytes() == b"x" * 1000
    target.delete("vm1/2026-10-14.qcow2")
    target.delete("vm1/2026-10-14.qcow2")  # brak = bez błędu
    assert fake.objects == {}


def test_s3_duzy_plik_wieloczesciowo(tmp_path, monkeypatch):
    monkeypatch.setattr(bt, "PART_SIZE", 400)
    fake = FakeS3()
    source = tmp_path / "big.qcow2"
    source.write_bytes(bytes(range(256)) * 4)  # 1024 B = 3 części
    s3_target(fake).upload(source, "vm1/big.qcow2")
    assert sorted(fake.parts) == [1, 2, 3]
    assert fake.objects["/kopie/node1/vm1/big.qcow2"] == source.read_bytes()


def test_klucz_nie_wyjdzie_poza_cel(tmp_path):
    target = bt.LocalTarget({"path": str(tmp_path)})
    for key in ["../etc/passwd", "/abs", "a/../../b", ""]:
        with pytest.raises(bt.BackupTargetError):
            target.delete(key)
    with pytest.raises(bt.BackupTargetError):
        bt.LocalTarget({"path": "relative/dir"})
    with pytest.raises(bt.BackupTargetError):
        bt.build_target({"type": "ftp"})


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def test_sftp_prawdziwy_serwer(tmp_path):
    asyncssh = pytest.importorskip("asyncssh")
    root = tmp_path / "remote"
    root.mkdir()
    port = _free_port()
    key = asyncssh.generate_private_key("ssh-ed25519")
    ready = threading.Event()
    loop = asyncio.new_event_loop()

    class Server(asyncssh.SSHServer):
        def begin_auth(self, username):
            return True

        def password_auth_supported(self):
            return True

        def validate_password(self, username, password):
            return username == "kopie" and password == "tajne"

    async def start():
        await asyncssh.listen("127.0.0.1", port, server_host_keys=[key], server_factory=Server,
                              sftp_factory=lambda chan: asyncssh.SFTPServer(chan, chroot=str(root)))
        ready.set()

    thread = threading.Thread(target=lambda: (loop.run_until_complete(start()), loop.run_forever()), daemon=True)
    thread.start()
    assert ready.wait(10)
    try:
        target = bt.SftpTarget({"host": "127.0.0.1", "port": port, "username": "kopie", "password": "tajne", "path": "/backups",
                                "host_key_fingerprint": key.get_fingerprint("sha256")})
        source = tmp_path / "d.qcow2"
        source.write_bytes(b"sftp-data")
        target.upload(source, "vm7/a.qcow2")
        assert (root / "backups" / "vm7" / "a.qcow2").read_bytes() == b"sftp-data"
        out = tmp_path / "o.qcow2"
        target.download("vm7/a.qcow2", out)
        assert out.read_bytes() == b"sftp-data"
        target.delete("vm7/a.qcow2")
        assert not (root / "backups" / "vm7" / "a.qcow2").exists()

        wrong = bt.SftpTarget({"host": "127.0.0.1", "port": port, "username": "kopie", "password": "tajne", "path": "/backups",
                               "host_key_fingerprint": "SHA256:zly"})
        with pytest.raises(bt.BackupTargetError, match="Klucz hosta"):
            wrong.upload(source, "vm7/b.qcow2")
    finally:
        loop.call_soon_threadsafe(loop.stop)


def test_kopia_i_przywrocenie_przez_api(client, template, tmp_path):
    vm = create_vm(client, template, server_id=1501)["result"]
    target = {"type": "local", "config": {"path": str(tmp_path / "kopie")}}

    response = client.post(f"/vm/{vm['uuid']}/backups", json={"key": "vm1501/b1.qcow2", "target": target})
    job = wait_for_job(client, response.json()["job_id"])
    assert job["status"] == "done", job.get("error")
    stored = tmp_path / "kopie" / "vm1501" / "b1.qcow2"
    assert stored.exists()
    assert job["result"]["sha256"] == hashlib.sha256(stored.read_bytes()).hexdigest()

    bad = client.post(f"/vm/{vm['uuid']}/backups/restore", json={"key": "vm1501/b1.qcow2", "target": target, "sha256": "0" * 64})
    assert wait_for_job(client, bad.json()["job_id"])["status"] == "failed"
    ok = client.post(f"/vm/{vm['uuid']}/backups/restore", json={"key": "vm1501/b1.qcow2", "target": target, "sha256": job["result"]["sha256"]})
    assert wait_for_job(client, ok.json()["job_id"])["status"] == "done"

    gone = client.post("/backups/delete", json={"keys": ["vm1501/b1.qcow2"], "target": target})
    assert wait_for_job(client, gone.json()["job_id"])["status"] == "done"
    assert not stored.exists()
