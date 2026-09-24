"""Obrazy ISO: biblioteka węzła, płyta w maszynie i kolejność rozruchu."""

from __future__ import annotations

import hashlib
import http.server
import threading
from xml.etree import ElementTree as ET

import pytest

from agent.domain_xml import build_domain_xml, with_iso
from agent.isos import IsoError, IsoLibrary
from test_provisioning import create_vm, wait_for_job


def base_xml() -> str:
    return build_domain_xml(
        name="virthub-5", uuid="6f1d2a4e-9c3b-4b8e-8f2a-1d2c3b4a5e6f", vcpu=1, ram_mb=512, disk_path="/d.qcow2",
        seed_path="/seed.iso", bridge="br0", mac="52:54:00:00:00:05",
        interface_target="vh5", vnc_listen="127.0.0.1", vnc_password="x",
    )


def boot_order(xml: str) -> dict[str, str]:
    root = ET.fromstring(xml)
    return {
        d.find("target").get("dev"): d.find("boot").get("order")
        for d in root.find("devices").findall("disk") if d.find("boot") is not None
    }


# --- definicja domeny ------------------------------------------------------------

def test_plyta_w_osobnym_napedzie_i_rozruch_z_niej():
    xml = with_iso(base_xml(), "/isos/debian.iso", boot_from_iso=True)
    root = ET.fromstring(xml)

    cdroms = {d.find("target").get("dev"): d for d in root.iter("disk") if d.get("device") == "cdrom"}
    assert cdroms["sda"].find("source").get("file") == "/seed.iso", "nośnik cloud-init zostaje nietknięty"
    assert cdroms["sdb"].find("source").get("file") == "/isos/debian.iso"
    assert cdroms["sdb"].find("readonly") is not None
    assert boot_order(xml) == {"sdb": "1", "vda": "2"}
    assert root.find("os").find("boot") is None, "libvirt odrzuca <os><boot> razem z <boot order>"


def test_plyta_bez_rozruchu_z_niej():
    xml = with_iso(base_xml(), "/isos/debian.iso", boot_from_iso=False)
    assert boot_order(xml) == {"vda": "1"}


def test_wysuniecie_plyty_usuwa_naped_i_przywraca_dysk():
    mounted = with_iso(base_xml(), "/isos/debian.iso", boot_from_iso=True)
    ejected = with_iso(mounted, None, boot_from_iso=True)

    targets = [d.find("target").get("dev") for d in ET.fromstring(ejected).iter("disk")]
    assert "sdb" not in targets
    assert boot_order(ejected) == {"vda": "1"}


def test_zmiana_plyty_nie_dubluje_napedu():
    xml = with_iso(with_iso(base_xml(), "/isos/a.iso", True), "/isos/b.iso", True)
    sdb = [d for d in ET.fromstring(xml).iter("disk") if d.find("target").get("dev") == "sdb"]
    assert len(sdb) == 1
    assert sdb[0].find("source").get("file") == "/isos/b.iso"
    assert len(sdb[0].findall("boot")) == 1


# --- biblioteka --------------------------------------------------------------------

@pytest.fixture()
def iso_server(tmp_path):
    payload = b"ISO9660-fake-content" * 1000
    (tmp_path / "test.iso").write_bytes(payload)

    handler = lambda *a, **kw: http.server.SimpleHTTPRequestHandler(*a, directory=str(tmp_path), **kw)
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{server.server_address[1]}/test.iso", hashlib.sha256(payload).hexdigest(), len(payload)
    server.shutdown()


def test_pobranie_ze_sprawdzeniem_sumy(settings, tmp_path, iso_server):
    url, sha, size = iso_server
    lib = IsoLibrary(settings)
    lib.dir = tmp_path / "lib"

    result = lib.download("debian.iso", url, sha)

    assert result["size_bytes"] == size
    assert lib.exists("debian.iso")
    assert not (lib.dir / "debian.iso.part").exists()


def test_zla_suma_nie_zostawia_pliku(settings, tmp_path, iso_server):
    url, _, _ = iso_server
    lib = IsoLibrary(settings)
    lib.dir = tmp_path / "lib"

    with pytest.raises(IsoError):
        lib.download("debian.iso", url, "0" * 64)
    assert list(lib.dir.iterdir()) == []


@pytest.mark.parametrize("name", ["../etc/passwd.iso", "a b.iso", "x.img", ".iso"])
def test_nazwa_nie_wyjdzie_poza_katalog(settings, name):
    with pytest.raises(IsoError):
        IsoLibrary(settings).path(name)


# --- API ---------------------------------------------------------------------------

def test_montowanie_przez_api(client, template, settings, iso_server):
    url, sha, _ = iso_server
    uuid = create_vm(client, template, server_id=3101)["result"]["uuid"]

    missing = client.post(f"/vm/{uuid}/iso", json={"iso": "brak.iso", "boot": True})
    assert wait_for_job(client, missing.json()["job_id"])["status"] == "failed"

    job = client.post("/images/iso", json={"name": "netinst.iso", "url": url, "sha256": sha})
    assert wait_for_job(client, job.json()["job_id"])["status"] == "done"
    assert client.get("/images/iso").json()["data"][0]["name"] == "netinst.iso"

    mounted = client.post(f"/vm/{uuid}/iso", json={"iso": "netinst.iso", "boot": True, "restart": True})
    state = wait_for_job(client, mounted.json()["job_id"])
    assert state["status"] == "done", state.get("error")
    assert state["result"]["boot"] is True

    assert client.delete("/images/iso/netinst.iso").json()["deleted"] is True
