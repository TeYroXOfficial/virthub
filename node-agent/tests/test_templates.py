"""Szablony KVM z katalogu panelu: pobranie, suma kontrolna, format, brak nadpisania."""

from __future__ import annotations

import hashlib
import http.server
import json
import threading

import pytest

from agent import templates as templates_mod
from agent.templates import TemplateError, TemplateLibrary


@pytest.fixture()
def image_server(tmp_path):
    payload = b"QFI\xfb-fake-qcow2" * 5000
    (tmp_path / "srv").mkdir()
    (tmp_path / "srv" / "debian.qcow2").write_bytes(payload)
    handler = lambda *a, **kw: http.server.SimpleHTTPRequestHandler(*a, directory=str(tmp_path / "srv"), **kw)
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{server.server_address[1]}/debian.qcow2", payload
    server.shutdown()


def library(settings, tmp_path) -> TemplateLibrary:
    lib = TemplateLibrary(settings)
    lib.dir = tmp_path / "templates"
    return lib


def test_pobranie_z_suma_sha512(settings, tmp_path, image_server):
    url, payload = image_server
    lib = library(settings, tmp_path)

    result = lib.download("debian-12.qcow2", url, sha512=hashlib.sha512(payload).hexdigest())

    assert result["cached"] is False and result["size_bytes"] == len(payload)
    assert (lib.dir / "debian-12.qcow2").read_bytes() == payload
    assert [p.name for p in lib.dir.iterdir()] == ["debian-12.qcow2"], "bez pliku .part"


def test_zla_suma_nie_zostawia_pliku(settings, tmp_path, image_server):
    url, _ = image_server
    lib = library(settings, tmp_path)

    with pytest.raises(TemplateError, match="Suma"):
        lib.download("debian-12.qcow2", url, sha256="0" * 64)
    assert list(lib.dir.iterdir()) == []


def test_istniejacy_szablon_nie_jest_nadpisywany(settings, tmp_path, image_server):
    url, _ = image_server
    lib = library(settings, tmp_path)
    lib.dir.mkdir()
    (lib.dir / "debian-12.qcow2").write_bytes(b"obraz bazowy maszyn")

    result = lib.download("debian-12.qcow2", url, sha256="0" * 64)

    assert result["cached"] is True
    assert (lib.dir / "debian-12.qcow2").read_bytes() == b"obraz bazowy maszyn"


@pytest.mark.parametrize("name", ["../etc/x.qcow2", "a b.qcow2", "x.img", ".qcow2"])
def test_nazwa_nie_wyjdzie_poza_katalog(settings, name):
    with pytest.raises(TemplateError):
        TemplateLibrary(settings).path(name)


@pytest.mark.parametrize("info, ok", [
    ({"format": "qcow2"}, True),
    ({"format": "raw"}, False),
    ({"format": "qcow2", "backing-filename": "/etc/shadow"}, False),
])
def test_tylko_samodzielny_qcow2(settings, tmp_path, monkeypatch, info, ok):
    lib = library(settings, tmp_path)
    lib.mock = False
    monkeypatch.setattr(templates_mod, "run", lambda argv, timeout=0: json.dumps(info))
    if ok:
        lib._check_image(tmp_path / "x")
    else:
        with pytest.raises(TemplateError):
            lib._check_image(tmp_path / "x")


def test_api_przyjmuje_tylko_https(client):
    bad = client.post("/templates/download", json={"name": "debian-12.qcow2", "url": "http://example.com/x.qcow2"})
    assert bad.status_code == 422
    ok = client.post("/templates/download", json={
        "name": "debian-12.qcow2", "url": "https://example.invalid/x.qcow2", "sha256": "a" * 64,
    })
    assert ok.status_code == 202 and ok.json()["job_id"]
