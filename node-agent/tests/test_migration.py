"""Migracje między węzłami: eksport z tokenem, import z weryfikacją, migracja na żywo."""

from __future__ import annotations

import hashlib
import sys
import time
import types
from contextlib import contextmanager

import pytest

from agent import migration
from test_provisioning import create_vm, wait_for_job


@pytest.fixture()
def via_client(client, monkeypatch):
    """Węzeł docelowy „pobiera” dysk z tego samego agenta testowego."""

    @contextmanager
    def stream(url):
        path = "/" + url.split("/", 3)[3]
        with client.stream("GET", path) as response:
            yield response

    monkeypatch.setattr(migration, "http_stream", stream)
    return client


def _payload(template, server_id, **extra):
    return {
        "server_id": server_id, "hostname": f"vps{server_id}.example.com", "vcpu": 1, "ram_mb": 1024, "disk_gb": 20,
        "template": template, "interfaces": [{"address": "203.0.113.40", "prefix": 24, "gateway": "203.0.113.1", "version": 4}],
        **extra,
    }


def test_migracja_na_zimno(via_client, template, settings):
    client = via_client
    vm = create_vm(client, template, server_id=1901)["result"]
    export = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/export").json()["job_id"])
    assert export["status"] == "done", export.get("error")
    result = export["result"]
    assert result["was_running"] is True and len(result["token"]) >= 40

    served = client.get(f"/transfer/{result['token']}")
    assert served.status_code == 200
    assert hashlib.sha256(served.content).hexdigest() == result["sha256"]
    assert client.get("/transfer/zly-token").status_code == 404

    url = f"http://source-node:8080/transfer/{result['token']}"
    bad = wait_for_job(client, client.post("/vm/import", json=_payload(template, 1902, source={"url": url, "sha256": "0" * 64})).json()["job_id"])
    assert bad["status"] == "failed" and "Suma kontrolna" in bad["error"]
    assert not (settings.image_dir / "virthub-1902.qcow2").exists()

    job = wait_for_job(client, client.post("/vm/import", json=_payload(template, 1902, source={
        "url": url, "sha256": result["sha256"], "size_bytes": result["size_bytes"], "start": False})).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert job["result"]["state"] == "stopped"
    assert (settings.image_dir / "virthub-1902.qcow2").read_bytes() == served.content

    # Sprzątanie po migracji: token unieważniony, plik eksportu usunięty.
    assert client.delete(f"/transfer/{result['token']}").json() == {"revoked": True}
    assert client.get(f"/transfer/{result['token']}").status_code == 404
    assert not list(settings.image_dir.glob(".virthub-1901.export-*"))


def test_przeterminowany_token_sprzata_plik(settings, tmp_path):
    store = migration.TransferStore(settings)
    file = tmp_path / "x.qcow2"
    file.write_bytes(b"x")
    token = store.register(file, "virthub-1")
    assert store.resolve(token) == file
    data = store._load()
    data[token]["expires"] = time.time() - 1
    store._save(data)
    assert store.resolve(token) is None
    assert not file.exists()


def test_migracja_na_zywo_w_trybie_mock(client, template):
    running = create_vm(client, template, server_id=1903)["result"]
    job = wait_for_job(client, client.post(f"/vm/{running['uuid']}/migrate", json={"host": "kvm-2.example.com"}).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert job["result"]["uri"] == "qemu+tls://kvm-2.example.com/system"

    prepared = wait_for_job(client, client.post("/vm/prepare-migration", json=_payload(template, 1904)).json()["job_id"])
    assert prepared["status"] == "done", prepared.get("error")
    again = wait_for_job(client, client.post("/vm/prepare-migration", json=_payload(template, 1904)).json()["job_id"])
    assert again["status"] == "failed" and "już istnieje" in again["error"]


def test_libvirt_migracja_na_zywo_flagi(settings, monkeypatch):
    calls = {}
    fake = types.SimpleNamespace(
        VIR_MIGRATE_LIVE=1, VIR_MIGRATE_PEER2PEER=2, VIR_MIGRATE_PERSIST_DEST=8, VIR_MIGRATE_UNDEFINE_SOURCE=16,
        VIR_MIGRATE_NON_SHARED_DISK=64, VIR_MIGRATE_AUTO_CONVERGE=8192, VIR_MIGRATE_PARAM_BANDWIDTH="bandwidth",
        libvirtError=type("libvirtError", (Exception,), {}),
    )
    monkeypatch.setitem(sys.modules, "libvirt", fake)

    class Domain:
        def name(self):
            return "virthub-77"

        def isActive(self):
            return True

        def migrateToURI3(self, uri, params, flags):
            calls.update(uri=uri, params=params, flags=flags)

    class Conn:
        def isAlive(self):
            return True

        def lookupByUUIDString(self, uuid):
            return Domain()

    from agent.driver import LibvirtDriver

    drv = LibvirtDriver(settings)
    drv._conn = Conn()
    monkeypatch.setattr(drv.network, "teardown", lambda sid: calls.setdefault("teardown", sid))
    monkeypatch.setattr(drv.storage, "delete_volume", lambda name: calls.setdefault("deleted", name))
    monkeypatch.setattr(drv.storage, "has_overlay", lambda name: False)

    from agent.schemas import LiveMigrationRequest

    result = drv.migrate_live("u", LiveMigrationRequest(host="10.0.0.2", bandwidth_mbps=800))
    assert result["migrated"] is True
    assert calls["uri"] == "qemu+tls://10.0.0.2/system"
    assert calls["flags"] == 1 | 2 | 8 | 16 | 64 | 8192
    assert calls["params"] == {"bandwidth": 100}
    assert calls["teardown"] == 77 and calls["deleted"] == "virthub-77"


def test_anulowanie_migracji_na_zywo_i_sprzatanie_celu(via_client, template, settings):
    client = via_client
    vm = create_vm(client, template, server_id=1911)["result"]
    assert client.post(f"/vm/{vm['uuid']}/migrate/abort").json() == {"uuid": vm["uuid"], "aborted": True}
    job = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/migrate", json={"host": "kvm-2"}).json()["job_id"])
    assert job["status"] == "failed" and "anulowana" in job["error"]
    assert client.get(f"/vm/{vm['uuid']}/stats").status_code == 200  # maszyna została na źródle
    assert client.post("/vm/00000000-0000-0000-0000-000000000000/migrate/abort").status_code == 404

    # Cel po imporcie (np. anulowanym w trakcie): domena i dysk znikają.
    export = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/export").json()["job_id"])["result"]
    url = f"http://source-node:8080/transfer/{export['token']}"
    imported = wait_for_job(client, client.post("/vm/import", json=_payload(template, 1912, source={
        "url": url, "sha256": export["sha256"], "size_bytes": export["size_bytes"], "start": False})).json()["job_id"])
    assert imported["status"] == "done", imported.get("error")
    assert (settings.image_dir / "virthub-1912.qcow2").exists()
    cleaned = wait_for_job(client, client.post("/migration/cleanup/1912").json()["job_id"])
    assert cleaned["status"] == "done", cleaned.get("error")
    assert cleaned["result"]["domain_removed"] is True and "virthub-1912.qcow2" in cleaned["result"]["removed"]
    assert not (settings.image_dir / "virthub-1912.qcow2").exists()
    assert client.get(f"/vm/{imported['result']['uuid']}/stats").status_code == 404
    # Drugi raz: nic do sprzątania, bez błędu.
    again = wait_for_job(client, client.post("/migration/cleanup/1912").json()["job_id"])
    assert again["status"] == "done" and again["result"] == {"server_id": 1912, "domain_removed": False, "removed": []}


def test_postep_migracji_na_zywo_z_libvirt():
    import threading

    from agent.driver import LibvirtDriver

    reports = []
    stop = threading.Event()

    class Domain:
        calls = 0

        def jobStats(self):
            Domain.calls += 1
            if Domain.calls >= 2:
                stop.set()
            return {"data_total": 4096 * 1024 * 1024, "data_processed": 1024 * 1024 * 1024, "memory_bps": 100 * 1024 * 1024, "memory_iteration": 2}

    stop_wait = stop.wait
    stop.wait = lambda timeout: stop_wait(0.01)  # bez czekania 2 s w teście
    LibvirtDriver._watch_migration(Domain(), stop, lambda *a: reports.append(a))
    assert reports[0] == ("migrate", 31, "1024 MB z 4096 MB · 100 MB/s · przebieg pamięci 2")
