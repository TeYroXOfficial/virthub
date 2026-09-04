"""Uwierzytelnianie: agent nie może przyjąć żądania bez ważnego podpisu."""

from __future__ import annotations

import time

from agent.security import sign
from tests.conftest import TEST_TOKEN


def test_odrzuca_zadanie_bez_podpisu(settings):
    from fastapi.testclient import TestClient

    from agent.main import app

    with TestClient(app) as unsigned:
        response = unsigned.get("/health")

    assert response.status_code == 401
    assert "podpisane" in response.json()["detail"].lower()


def test_ping_nie_wymaga_podpisu(client):
    response = client.get("/ping")
    assert response.status_code == 200
    assert response.json()["driver"] == "mock"


def test_zly_podpis_konczy_sie_401(client):
    timestamp = str(int(time.time()))
    response = client.get(
        "/health",
        headers={"X-VH-Timestamp": timestamp, "X-VH-Signature": "0" * 64},
    )
    assert response.status_code == 401


def test_przeterminowany_znacznik_czasu_konczy_sie_401(client):
    stale = str(int(time.time()) - 4000)
    response = client.get(
        "/health",
        headers={
            "X-VH-Timestamp": stale,
            "X-VH-Signature": sign(TEST_TOKEN, stale, "GET", "/health", b""),
        },
    )
    assert response.status_code == 401
    assert "zegar" in response.json()["detail"].lower()


def test_podpis_zalezy_od_tresci_zadania():
    timestamp = "1700000000"
    a = sign("sekret", timestamp, "POST", "/vm", b'{"vcpu":1}')
    b = sign("sekret", timestamp, "POST", "/vm", b'{"vcpu":64}')
    assert a != b, "Zmiana ciała żądania musi unieważniać podpis"


def test_podpis_zalezy_od_sciezki():
    timestamp = "1700000000"
    a = sign("sekret", timestamp, "DELETE", "/vm/abc", b"")
    b = sign("sekret", timestamp, "DELETE", "/vm/xyz", b"")
    assert a != b, "Podpis dla jednej maszyny nie może działać na innej"
