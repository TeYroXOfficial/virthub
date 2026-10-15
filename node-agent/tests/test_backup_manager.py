"""Backup Manager: szyfrowanie, cele FTP/Rclone/PBS, kopie różnicowe, fsfreeze, Ceph."""

from __future__ import annotations

import base64
import os
import sys
import types
from pathlib import Path

import pytest

from agent.backup_crypto import BackupCryptoError, decrypt_file, encrypt_file, is_encrypted
from agent.backup_targets import BackupTargetError, FtpTarget, PbsTarget, RcloneTarget, build_target
from test_provisioning import create_vm, wait_for_job

KEY = base64.b64encode(b"k" * 32).decode()


def test_szyfrowanie_ramek(tmp_path):
    plain = tmp_path / "a"
    plain.write_bytes(os.urandom(5 * 1024 * 1024 + 7))
    sealed, back = tmp_path / "b", tmp_path / "c"
    encrypt_file(plain, sealed, KEY)
    assert is_encrypted(sealed) and not is_encrypted(plain)
    decrypt_file(sealed, back, KEY)
    assert back.read_bytes() == plain.read_bytes()

    with pytest.raises(BackupCryptoError):
        decrypt_file(sealed, tmp_path / "x", base64.b64encode(b"z" * 32).decode())
    # Ucięcie ostatniej ramki jest wykrywane (nie tylko uszkodzenie bajtów).
    data = sealed.read_bytes()
    cut = tmp_path / "cut"
    first_len = int.from_bytes(data[7:11], "big")
    cut.write_bytes(data[: 7 + 4 + 12 + first_len])
    with pytest.raises(BackupCryptoError):
        decrypt_file(cut, tmp_path / "y", KEY)


def test_kopia_szyfrowana_end_to_end(client, template, tmp_path, settings):
    target = {"type": "local", "config": {"path": str(tmp_path / "t")}, "encryption_key": KEY}
    vm = create_vm(client, template, server_id=2301)["result"]
    job = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups", json={"key": "vm2301/a.qcow2", "target": target}).json()["job_id"])
    assert job["status"] == "done", job.get("error")
    assert job["result"]["encrypted"] is True and job["result"]["fs_frozen"] is True
    stored = tmp_path / "t" / "vm2301" / "a.qcow2"
    assert is_encrypted(stored)

    restored = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups/restore", json={
        "key": "vm2301/a.qcow2", "target": target, "sha256": job["result"]["sha256"]}).json()["job_id"])
    assert restored["status"] == "done", restored.get("error")
    assert (settings.image_dir / "virthub-2301.qcow2").read_bytes().startswith(b"mock full backup")

    no_key = {"type": "local", "config": {"path": str(tmp_path / "t")}}
    failed = wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups/restore", json={"key": "vm2301/a.qcow2", "target": no_key}).json()["job_id"])
    assert failed["status"] == "failed" and "zaszyfrowana" in failed["error"]


def test_roznicowa_w_trybie_mock(client, template, tmp_path):
    target = {"type": "local", "config": {"path": str(tmp_path / "d")}}
    vm = create_vm(client, template, server_id=2302)["result"]

    def backup(key, **extra):
        return wait_for_job(client, client.post(f"/vm/{vm['uuid']}/backups", json={"key": key, "target": target, **extra}).json()["job_id"])["result"]

    full = backup("f.qcow2", checkpoint="vh-1")
    assert full["kind"] == "full" and full["checkpoint"] == "vh-1"
    d1 = backup("d1.qcow2", checkpoint="vh-2", incremental_from="vh-1", differential=True)
    assert d1["kind"] == "incremental" and d1["differential"] is True and d1["checkpoint"] is None
    d2 = backup("d2.qcow2", checkpoint="vh-3", incremental_from="vh-1", differential=True)
    assert d2["parent"] == "vh-1", "Kolejna różnicowa nadal od kopii pełnej"


def test_ftp_wysyla_przez_plik_tymczasowy(tmp_path):
    calls = []

    class FakeFtp:
        def connect(self, host, port):
            calls.append(("connect", host, port))

        def login(self, user, password):
            calls.append(("login", user))

        def prot_p(self):
            calls.append(("prot_p",))

        def set_pasv(self, value):
            pass

        def mkd(self, path):
            calls.append(("mkd", path))

        def storbinary(self, cmd, fh, blocksize):
            calls.append(("stor", cmd, fh.read()))

        def rename(self, a, b):
            calls.append(("rename", a, b))

        def close(self):
            pass

    source = tmp_path / "x"
    source.write_bytes(b"data")
    FtpTarget({"host": "ftp.example.test", "username": "u", "password": "p", "path": "/kopie"}, factory=FakeFtp).upload(source, "vm1/a.qcow2")
    assert ("prot_p",) in calls, "FTPS z chronionym kanałem danych"
    assert ("stor", "STOR /kopie/vm1/a.qcow2.part", b"data") in calls
    assert ("rename", "/kopie/vm1/a.qcow2.part", "/kopie/vm1/a.qcow2") in calls


def test_rclone_konfiguracja_tymczasowa(tmp_path):
    seen = {}

    def runner(argv, timeout):
        conf = Path(argv[argv.index("--config") + 1])
        seen["conf"] = conf.read_text()
        seen["argv"] = argv
        seen["mode"] = oct(conf.stat().st_mode & 0o777)

    target = RcloneTarget({"backend": "b2", "params": {"account": "id", "key": "sekret"}, "path": "kopie"}, runner=runner)
    target.upload(tmp_path / "a", "vm1/a.qcow2")
    assert "[vh]\ntype = b2\naccount = id\nkey = sekret" in seen["conf"] and seen["mode"] == "0o600"
    assert seen["argv"][-3:] == ["copyto", str(tmp_path / "a"), "vh:kopie/vm1/a.qcow2"]
    assert not Path(seen["argv"][seen["argv"].index("--config") + 1]).exists(), "Sekrety nie zostają na węźle"
    with pytest.raises(BackupTargetError):
        RcloneTarget({"backend": "b2", "params": {"key": "a\nb = c"}})


def test_pbs_migawka_z_klucza_i_haslo_w_srodowisku(tmp_path):
    ran = []

    def runner(argv, timeout, env=None):
        ran.append((argv, env))

    pbs = PbsTarget({"host": "pbs.example.test", "datastore": "ds1", "username": "root@pam!vh", "password": "TAJNE",
                     "fingerprint": "aa:bb", "namespace": "klienci"}, runner=runner)
    source = tmp_path / "a.qcow2"
    pbs.upload(source, "vm12/backup-1.qcow2")
    convert, backup = ran[0][0], ran[1]
    assert convert[:4] == ["qemu-img", "convert", "-O", "raw"]
    assert backup[0][:3] == ["proxmox-backup-client", "backup", f"disk.img:{source}.raw"]
    assert "--backup-id" in backup[0] and "vm12-backup-1" in backup[0] and backup[0][-2:] == ["--ns", "klienci"]
    assert backup[1]["PBS_PASSWORD"] == "TAJNE" and "TAJNE" not in " ".join(backup[0])
    assert backup[1]["PBS_REPOSITORY"] == "root@pam!vh@pbs.example.test:8007:ds1"
    assert pbs.snapshot("vm12/backup-1.qcow2") == "vm/vm12-backup-1/2000-01-01T00:00:00Z"
    assert build_target({"type": "pbs", "config": {"host": "h", "datastore": "d", "username": "u@pam", "password": "x"}}).__class__ is PbsTarget


def test_libvirt_fsfreeze_przy_kopii_i_ceph(settings, monkeypatch, tmp_path):
    fake = types.SimpleNamespace(libvirtError=type("libvirtError", (Exception,), {}))
    monkeypatch.setitem(sys.modules, "libvirt", fake)
    from agent.driver import LibvirtDriver
    from agent.schemas import BackupRequest

    events = []

    class Domain:
        def name(self):
            return "virthub-40"

        def isActive(self):
            return True

        def fsFreeze(self, mounts, flags):
            events.append("freeze")

        def fsThaw(self, mounts, flags):
            events.append("thaw")

    drv = LibvirtDriver(settings)
    monkeypatch.setattr(drv, "_domain", lambda uuid: Domain())
    monkeypatch.setattr(drv.storage, "is_file_based", lambda name: False)
    monkeypatch.setattr(drv.storage, "has_overlay", lambda name: False)

    def export(name, target, compress=True, after_snapshot=None):
        events.append("snapshot")
        after_snapshot and after_snapshot()
        events.append("export")
        target.write_bytes(b"ceph")

    monkeypatch.setattr(drv.storage, "export_image", export)
    result = drv.backup("u", BackupRequest(key="vm40/a.qcow2", target={"type": "local", "config": {"path": str(tmp_path)}}))
    assert events == ["freeze", "snapshot", "thaw", "export"], "Odmrożenie zaraz po migawce, przed długim eksportem"
    assert result["fs_frozen"] is True and result["kind"] == "full"
    assert (tmp_path / "vm40" / "a.qcow2").read_bytes() == b"ceph"


def test_ceph_eksport_i_import_bez_niszczenia_dysku(settings, tmp_path):
    from agent.datastores import CephRbdDriver
    from agent.schemas import DatastoreSpec

    ran = []

    def runner(argv, timeout=600, **kw):
        ran.append(argv)
        return ""

    ceph = CephRbdDriver(settings, DatastoreSpec(id=3, type="ceph", pool="vms", monitors=["10.0.0.1"], key="AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ=="), runner=runner)
    target = tmp_path / "out.qcow2"
    ceph.export_image("virthub-9", target)
    flat = [" ".join(x) for x in ran]
    assert any("snap create vms/virthub-9@vh-backup" in f for f in flat)
    assert any("export --no-progress vms/virthub-9@vh-backup" in f for f in flat)
    assert any(f.startswith("qemu-img convert -f raw -O qcow2 -c") for f in flat)
    assert "snap rm --no-progress vms/virthub-9@vh-backup" in flat[-1]

    ran.clear()
    ceph.import_image("virthub-9", tmp_path / "in.qcow2")
    flat = [" ".join(x) for x in ran]
    order = [i for i, f in enumerate(flat) if "import --no-progress" in f or " mv " in f]
    assert "mv vms/virthub-9 vms/virthub-9.vhold" in flat[order[1]] and "mv vms/virthub-9.vhrestore vms/virthub-9" in flat[order[2]], \
        "Stary obraz odsuwany dopiero po udanym imporcie nowego"
