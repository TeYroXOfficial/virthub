"""SFTP aplikacji: logowanie hasłem sprawdzanym w panelu i zamknięcie w katalogu."""

from __future__ import annotations

import asyncio
import dataclasses
import hashlib
import hmac
import json
import os
import socket
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

import pytest

asyncssh = pytest.importorskip("asyncssh")

from agent.apps import AppManager, AppSpec  # noqa: E402
from agent.sftp import AUTH_PATH, SftpService  # noqa: E402

SECRET = "sekret-wezla"
UUID = "0b5e6c1a-1111-4222-8333-444455556666"
USER = "u7.0b5e6c1a"
PASSWORD = "haslo-z-panelu"


class FakePanel(BaseHTTPRequestHandler):
    calls: list[dict] = []

    def do_POST(self):  # noqa: N802
        body = self.rfile.read(int(self.headers["Content-Length"]))
        ts = self.headers["X-VH-Timestamp"]
        canonical = f"{ts}\nPOST\n{self.path}\n{hashlib.sha256(body).hexdigest()}"
        expected = hmac.new(SECRET.encode(), canonical.encode(), hashlib.sha256).hexdigest()
        data = json.loads(body)
        FakePanel.calls.append(data)
        ok = (
            self.path == AUTH_PATH
            and hmac.compare_digest(expected, self.headers["X-VH-Signature"])
            and data == {"uuid": UUID, "user_id": 7, "password": PASSWORD}
        )
        payload = json.dumps({"allowed": ok}).encode()
        self.send_response(200 if ok else 403)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)

    def log_message(self, *args):
        pass


def _free_port() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


@pytest.fixture()
def service(settings, tmp_path):
    panel = HTTPServer(("127.0.0.1", 0), FakePanel)
    threading.Thread(target=panel.serve_forever, daemon=True).start()
    cfg = dataclasses.replace(
        settings,
        apps_dir=tmp_path / "apps",
        state_db=tmp_path / "state" / "db.sqlite3",
        control_plane_url=f"http://127.0.0.1:{panel.server_port}",
        callback_secret=SECRET,
    )
    manager = AppManager(cfg, client=object())
    root = manager.data_dir(UUID)
    root.mkdir(parents=True)
    (root / "server.properties").write_text("motd=hej\n")
    (tmp_path / "sekret.txt").write_text("poza katalogiem")
    os.symlink(tmp_path / "sekret.txt", root / "link")
    manager.save_spec(AppSpec(uuid=UUID, image="x", startup="x", memory_mb=64, disk_mb=1))
    manager._usage_cache[UUID] = (float("inf"), 0)  # bez liczenia dysku w teście
    FakePanel.calls = []
    yield SftpService(cfg, manager, _free_port(), "127.0.0.1"), manager, tmp_path
    panel.shutdown()


def _run(coro):
    return asyncio.run(coro)


def test_logowanie_i_operacje_na_plikach(service):
    svc, manager, tmp_path = service

    async def scenario():
        assert await svc.start()
        try:
            async with asyncssh.connect("127.0.0.1", svc.port, username=USER, password=PASSWORD,
                                        known_hosts=None) as conn:
                async with conn.start_sftp_client() as sftp:
                    assert await sftp.realpath(".") == "/"
                    names = sorted(await sftp.listdir("/"))
                    assert "server.properties" in names and "link" in names

                    async with sftp.open("/server.properties") as fh:
                        assert await fh.read() == "motd=hej\n"

                    await sftp.mkdir("/plugins")
                    async with sftp.open("/plugins/a.txt", "w") as fh:
                        await fh.write("x" * 5000)
                    assert (await sftp.stat("/plugins/a.txt")).size == 5000
                    await sftp.rename("/plugins/a.txt", "/plugins/b.txt")
                    await sftp.remove("/plugins/b.txt")
                    await sftp.rmdir("/plugins")

                    # „..” nie wychodzi ponad katalog aplikacji.
                    assert "server.properties" in await sftp.listdir("/../..")
                    # Dowiązanie do pliku spoza katalogu nie daje się otworzyć.
                    with pytest.raises(asyncssh.SFTPError):
                        async with sftp.open("/link") as fh:
                            await fh.read()
                    with pytest.raises(asyncssh.SFTPError):
                        await sftp.symlink("/etc/passwd", "/zlo")
                    assert not (manager.data_dir(UUID) / "zlo").exists()

            # Drugie połączenie tym samym hasłem idzie z pamięci — bez pytania panelu.
            async with asyncssh.connect("127.0.0.1", svc.port, username=USER, password=PASSWORD,
                                        known_hosts=None) as conn:
                async with conn.start_sftp_client() as sftp:
                    assert await sftp.exists("/server.properties")
        finally:
            svc.stop()

    _run(scenario())
    assert len(FakePanel.calls) == 1
    assert (manager.data_dir(UUID).parent.parent / "state" / "sftp_host_ed25519").exists()


def test_limit_dysku_blokuje_zapis(service):
    svc, manager, _ = service
    manager._usage_cache[UUID] = (float("inf"), 2 * 1024 * 1024)  # 2 MB przy limicie 1 MB

    async def scenario():
        await svc.start()
        try:
            async with asyncssh.connect("127.0.0.1", svc.port, username=USER, password=PASSWORD,
                                        known_hosts=None) as conn:
                async with conn.start_sftp_client() as sftp:
                    with pytest.raises(asyncssh.SFTPError, match="limit dysku"):
                        async with sftp.open("/nowy.txt", "w") as fh:
                            await fh.write("x")
                    async with sftp.open("/server.properties") as fh:  # odczyt dalej działa
                        assert await fh.read()
        finally:
            svc.stop()

    _run(scenario())


@pytest.mark.parametrize("username,password", [
    (USER, "zle-haslo"),
    ("u8.0b5e6c1a", PASSWORD),     # panel odmówi innemu użytkownikowi
    ("u7.ffffffff", PASSWORD),     # aplikacji nie ma na węźle — panel nawet nie jest pytany
    ("root", PASSWORD),
])
def test_bledne_logowanie(service, username, password):
    svc, _, _ = service

    async def scenario():
        await svc.start()
        try:
            with pytest.raises(asyncssh.PermissionDenied):
                await asyncssh.connect("127.0.0.1", svc.port, username=username, password=password,
                                       known_hosts=None)
        finally:
            svc.stop()

    _run(scenario())
    if username in ("root", "u7.ffffffff"):
        assert FakePanel.calls == []
