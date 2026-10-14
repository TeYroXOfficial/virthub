"""Kopie przyrostowe: checkpointy, łańcuch pełna + przyrostowe, przywracanie z łańcucha, libvirt backupBegin."""

from __future__ import annotations

import sys
import types

from test_provisioning import create_vm, wait_for_job


def _backup(client, uuid, target, key, checkpoint=None, parent=None):
    body = {"key": key, "target": target, **({"checkpoint": checkpoint} if checkpoint else {}), **({"incremental_from": parent} if parent else {})}
    job = wait_for_job(client, client.post(f"/vm/{uuid}/backups", json=body).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    return job["result"]


def test_lancuch_kopii_i_przywrocenie(client, template, tmp_path, settings):
    target = {"type": "local", "config": {"path": str(tmp_path / "inc")}}
    vm = create_vm(client, template, server_id=2001)["result"]

    full = _backup(client, vm["uuid"], target, "vm2001/b1.qcow2", "vh-1")
    assert full["kind"] == "full" and full["checkpoint"] == "vh-1"
    inc = _backup(client, vm["uuid"], target, "vm2001/b2.qcow2", "vh-2", parent="vh-1")
    assert inc["kind"] == "incremental" and inc["parent"] == "vh-1"
    # Checkpoint, którego nie ma (np. po przywróceniu) — agent robi pełną.
    fallback = _backup(client, vm["uuid"], target, "vm2001/b3.qcow2", "vh-3", parent="vh-1")
    assert fallback["kind"] == "full"

    chain = [{"key": "vm2001/b1.qcow2", "sha256": full["sha256"]}]
    job = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups/restore", json={
        "key": "vm2001/b2.qcow2", "target": target, "sha256": inc["sha256"], "chain": chain}).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert "mock incremental backup" in (settings.image_dir / "virthub-2001.qcow2").read_text()
    # Po przywróceniu stare checkpointy znikają — następna kopia jest pełna.
    assert _backup(client, vm["uuid"], target, "vm2001/b4.qcow2", "vh-4", parent="vh-3")["kind"] == "full"

    bad = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups/restore", json={
        "key": "vm2001/b2.qcow2", "target": target, "chain": [{"key": "vm2001/b1.qcow2", "sha256": "0" * 64}]}).json()["job_id"])
    assert bad["status"] == "failed" and "Suma kontrolna" in bad["error"]


def test_zatrzymana_maszyna_zawsze_pelna(client, template, tmp_path):
    target = {"type": "local", "config": {"path": str(tmp_path / "inc2")}}
    vm = create_vm(client, template, server_id=2002)["result"]
    _backup(client, vm["uuid"], target, "vm2002/a.qcow2", "vh-1")
    assert wait_for_job(client, client.post(f"/vm/{vm['uuid']}/power", json={"action": "force-off"}).json()["job_id"])["status"] == "done"
    result = _backup(client, vm["uuid"], target, "vm2002/b.qcow2", "vh-2", parent="vh-1")
    assert result["kind"] == "full" and result["checkpoint"] is None


def test_libvirt_backupbegin_przyrostowa(settings, monkeypatch, tmp_path):
    calls = {"deleted": []}
    fake = types.SimpleNamespace(VIR_DOMAIN_JOB_NONE=0, VIR_DOMAIN_JOB_STATS_COMPLETED=1, VIR_DOMAIN_JOB_COMPLETED=3,
                                 libvirtError=type("libvirtError", (Exception,), {}))
    monkeypatch.setitem(sys.modules, "libvirt", fake)

    class Checkpoint:
        def __init__(self, name):
            self.name = name

        def getName(self):
            return self.name

        def delete(self, flags=0):
            calls["deleted"].append(self.name)

    class Domain:
        def name(self):
            return "virthub-88"

        def isActive(self):
            return True

        def XMLDesc(self, flags):
            return "<domain><devices><disk device='disk'><target dev='vda'/></disk><disk device='cdrom'><target dev='sda'/></disk></devices></domain>"

        def checkpointLookupByName(self, name):
            if name != "vh-1":
                raise fake.libvirtError("brak")
            return Checkpoint(name)

        def backupBegin(self, xml, cp, flags):
            calls["xml"], calls["cp"] = xml, cp
            target = xml.split("target file='")[1].split("'")[0]
            open(target, "wb").write(b"delta")

        def jobInfo(self):
            return [0, 0, 0, 0, 0]

        def jobStats(self, flags):
            return {"type": 3}

        def listAllCheckpoints(self):
            return [Checkpoint("vh-1"), Checkpoint("vh-2")]

    from agent.driver import LibvirtDriver
    from agent.schemas import BackupRequest

    drv = LibvirtDriver(settings)
    drv.storage.default.root = tmp_path  # obraz kopii obok „dysku”
    monkeypatch.setattr(drv, "_domain", lambda uuid: Domain())
    monkeypatch.setattr(drv.storage, "has_overlay", lambda name: False)
    monkeypatch.setattr(drv.storage, "volume_path", lambda name: tmp_path / f"{name}.qcow2")
    req = BackupRequest(key="vm88/b.qcow2", target={"type": "local", "config": {"path": str(tmp_path / "t")}},
                        checkpoint="vh-2", incremental_from="vh-1", compress=True)
    result = drv.backup("u", req)

    assert result["kind"] == "incremental" and result["parent"] == "vh-1"
    assert "<incremental>vh-1</incremental>" in calls["xml"]
    assert "<disk name='sda' backup='no'/>" in calls["xml"]
    assert "<name>vh-2</name>" in calls["cp"] and "<disk name='sda' checkpoint='no'/>" in calls["cp"]
    assert calls["deleted"] == ["vh-1"]  # zostaje tylko najnowszy checkpoint
    assert (tmp_path / "t" / "vm88" / "b.qcow2").read_bytes() == b"delta"  # przyrostowej nie kompresujemy
