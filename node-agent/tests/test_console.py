"""Konsola przez WebSocket: podpis, terminal (pty) i tunel do VNC."""

from __future__ import annotations

import socket
import threading
import time

import pytest
from starlette.websockets import WebSocketDisconnect

from agent.console import TerminalTarget, VncTarget
from agent.security import sign
from conftest import TEST_TOKEN
from test_provisioning import create_vm


def signed_headers(path: str, token: str = TEST_TOKEN) -> dict[str, str]:
    timestamp = str(int(time.time()))
    return {
        "X-VH-Timestamp": timestamp,
        "X-VH-Signature": sign(token, timestamp, "GET", path, b""),
    }


def read_until(ws, needle: bytes, limit: int = 50) -> bytes:
    buffer = b""
    for _ in range(limit):
        buffer += ws.receive_bytes()
        if needle in buffer:
            return buffer
    pytest.fail(f"Nie doczekano się {needle!r}, odebrano {buffer!r}")


def test_konsola_bez_podpisu_jest_odrzucana(client, template):
    uuid = create_vm(client, template, server_id=2101)["result"]["uuid"]

    with pytest.raises(WebSocketDisconnect):
        with client.websocket_connect(f"/vm/{uuid}/console") as ws:
            ws.receive_bytes()


def test_podpis_innym_sekretem_jest_odrzucany(client, template):
    uuid = create_vm(client, template, server_id=2102)["result"]["uuid"]
    path = f"/vm/{uuid}/console"

    with pytest.raises(WebSocketDisconnect):
        with client.websocket_connect(path, headers=signed_headers(path, "cudzy-sekret")) as ws:
            ws.receive_bytes()


def test_terminal_przesyla_dane_w_obie_strony(client, template):
    uuid = create_vm(client, template, server_id=2103)["result"]["uuid"]
    path = f"/vm/{uuid}/console"

    with client.websocket_connect(path, headers=signed_headers(path)) as ws:
        assert b"tryb mock" in read_until(ws, b"tryb mock")

        ws.send_text('{"type": "resize", "cols": 120, "rows": 40}')
        ws.send_bytes(b"echo-z-przegladarki\n")
        assert b"echo-z-przegladarki" in read_until(ws, b"echo-z-przegladarki")


def test_konsola_nieistniejacej_maszyny(client):
    path = "/vm/00000000-0000-0000-0000-000000000000/console"

    with pytest.raises(WebSocketDisconnect):
        with client.websocket_connect(path, headers=signed_headers(path)) as ws:
            ws.receive_bytes()


def test_vnc_jest_przepuszczany_bez_zmian(client, monkeypatch):
    """Agent jest rurą dla RFB: bajty z VNC trafiają do przeglądarki i z powrotem."""
    from agent import main

    server = socket.socket()
    server.bind(("127.0.0.1", 0))
    server.listen(1)
    port = server.getsockname()[1]
    received = []

    def fake_qemu():
        conn, _ = server.accept()
        conn.sendall(b"RFB 003.008\n")
        received.append(conn.recv(64))
        conn.close()

    thread = threading.Thread(target=fake_qemu, daemon=True)
    thread.start()
    monkeypatch.setattr(main.driver, "console_target", lambda uuid: VncTarget("127.0.0.1", port))

    path = "/vm/abc/console"
    with client.websocket_connect(path, headers=signed_headers(path), subprotocols=["binary"]) as ws:
        assert ws.accepted_subprotocol == "binary", "noVNC wymaga potwierdzenia podprotokołu"
        assert ws.receive_bytes() == b"RFB 003.008\n"
        ws.send_bytes(b"RFB 003.008\n")
        thread.join(timeout=5)

    server.close()
    assert received == [b"RFB 003.008\n"]


def test_kontener_loguje_przez_incus_exec(settings, monkeypatch):
    import dataclasses

    from agent import lxc_driver

    drv = lxc_driver.IncusDriver(dataclasses.replace(settings, driver="lxc"))
    monkeypatch.setattr(drv, "_name_for", lambda uuid: "virthub-7")
    monkeypatch.setattr(drv, "_state", lambda name: "running")

    target = drv.console_target("uuid")

    assert isinstance(target, TerminalTarget)
    assert target.argv[:4] == ["incus", "exec", "virthub-7", "-t"]
    assert "/bin/login" in target.argv[-1]
