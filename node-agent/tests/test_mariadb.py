"""Instalacja MariaDB z panelu: adres panelu, zlecenie, stan i jednorazowe dane konta."""

from __future__ import annotations

import dataclasses
import json
from pathlib import Path

import pytest

from agent.mariadb import MariaDbSetup, MariaDbSetupMissing, PanelAddressUnknown, panel_address


@pytest.fixture()
def setup(settings, tmp_path):
    return MariaDbSetup(dataclasses.replace(settings, state_db=tmp_path / "state.db"), unit=tmp_path / "virthub-mariadb.path")


def test_adres_panelu_tylko_z_naglowka_nginx():
    assert panel_address("203.0.113.7") == "203.0.113.7"
    assert panel_address(" 2001:db8::1 ") == "2001:db8::1"
    assert panel_address("127.0.0.1") == "127.0.0.1"
    # Bez nagłówka nigdy „%” — konto administracyjne nie może być otwarte na świat.
    for bad in (None, "", "%", "10.0.0.1'; DROP USER x; --"):
        with pytest.raises(PanelAddressUnknown):
            panel_address(bad)


def test_bez_jednostki_systemd_odmawia(setup):
    with pytest.raises(MariaDbSetupMissing):
        setup.request("203.0.113.7")
    assert not (setup.dir / "request").exists()


def test_zlecenie_i_dane_konta_do_odebrania_raz(setup):
    setup.unit.write_text("[Path]\n")
    with pytest.raises(ValueError):
        setup.request("%")

    status = setup.request("203.0.113.7", open_firewall=True)
    assert status["state"] == "queued"
    assert json.loads((setup.dir / "request").read_text()) | {"requested_at": 0} == {
        "panel_host": "203.0.113.7", "open_firewall": True, "requested_at": 0,
    }

    # Usługa roota skończyła pracę.
    (setup.dir / "request").unlink()
    (setup.dir / "status.json").write_text(json.dumps({"state": "done", "version": "10.11.6-MariaDB"}))
    (setup.dir / "credentials.json").write_text(json.dumps(
        {"username": "virthub_panel", "password": "x" * 40, "port": 3306, "allowed_from": "203.0.113.7"}))
    status = setup.status()
    assert status["state"] == "done"
    assert status["credentials"]["password"] == "x" * 40

    status = setup.forget_credentials()
    assert "credentials" not in status
    assert not (setup.dir / "credentials.json").exists()


def test_skrypt_nie_otwiera_konta_ani_zapory_bez_zgody():
    script = (Path(__file__).parents[1] / "scripts" / "setup-mariadb.sh").read_text()
    assert "fail \"Zlecenie nie zawiera poprawnego adresu panelu.\"" in script
    assert "'%'" not in script
    assert 'if [ "$OPEN_FIREWALL" = "1" ]' in script
    unit = (Path(__file__).parents[1] / "scripts" / "install-mariadb-unit.sh").read_text()
    assert "ExecStartPre=/bin/mv -f $STATE_DIR/request $STATE_DIR/request.run" in unit
