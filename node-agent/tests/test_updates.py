"""Zdalna aktualizacja węzła: zlecenie, stan i wersja kodu."""

from __future__ import annotations

import dataclasses
import json

import pytest

from agent.updates import Updates, UpdaterMissing


@pytest.fixture()
def updates(settings, tmp_path):
    unit = tmp_path / "virthub-agent-update.path"
    u = Updates(dataclasses.replace(settings, state_db=tmp_path / "state.db"), unit=unit)
    u.version_file = tmp_path / "VERSION"
    return u


def test_bez_jednostki_systemd_nie_ma_zdalnej_aktualizacji(updates):
    with pytest.raises(UpdaterMissing):
        updates.request()
    assert not (updates.dir / "request").exists()


def test_zlecenie_zostawia_plik_dla_systemd(updates):
    updates.unit.write_text("[Path]\n")

    status = updates.request()

    assert (updates.dir / "request").exists()
    assert status["state"] == "queued"
    assert status["remote_update"] is True


def test_stan_z_pliku_uslugi(updates):
    updates.dir.mkdir(parents=True)
    (updates.dir / "status.json").write_text(json.dumps({"state": "failed", "message": "brak sieci"}))
    updates.version_file.write_text("abc123\n")

    status = updates.status()

    assert status["state"] == "failed"
    assert status["message"] == "brak sieci"
    assert status["build"] == "abc123"


def test_uszkodzony_plik_stanu_to_bezczynnosc(updates):
    updates.dir.mkdir(parents=True)
    (updates.dir / "status.json").write_text("{nie-json")
    assert updates.status()["state"] == "idle"


def test_endpointy_sa_podpisane(client, monkeypatch, updates):
    from agent import main

    monkeypatch.setattr(main, "updates", updates)

    assert client.get("/system/update", headers={"X-VH-Signature": "zly", "X-VH-Timestamp": "1"}).status_code == 401
    assert client.post("/system/update").status_code == 409, "bez jednostki systemd — 409 z instrukcją"

    updates.unit.write_text("[Path]\n")
    response = client.post("/system/update")
    assert response.status_code == 202
    assert client.get("/system/update").json()["state"] == "queued"


def test_heartbeat_podaje_wersje(client, monkeypatch, updates):
    from agent import main

    updates.version_file.write_text("deadbeef\n")
    monkeypatch.setattr(main, "updates", updates)

    health = client.get("/health").json()
    assert health["build"] == "deadbeef"
    assert health["remote_update"] is False
