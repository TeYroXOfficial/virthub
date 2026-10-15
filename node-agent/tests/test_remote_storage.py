"""Zdalne magazyny: NFS / SMB / CephFS / iSCSI montowane przez agenta, StorPool i Lightbits."""

from __future__ import annotations

import dataclasses
import json
from pathlib import Path

import pytest
from pydantic import ValidationError

from agent import remote_storage
from agent.datastores import StorageRouter
from agent.remote_storage import LightbitsDriver, RemoteMount, StorPoolDriver
from agent.schemas import DatastoreSpec
from agent.shell import CommandError
from agent.storage import Qcow2LocalDriver, StorageError

KEY = "AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ=="
JWT = "eyJhbGciOiJSUzI1NiJ9.eyJzdWIiOiJ2aCJ9.c2lnbmF0dXJl"


class Recorder:
    """Zapisuje polecenia i treść plików z sekretami w chwili wywołania."""

    def __init__(self, handler=None):
        self.calls: list[list[str]] = []
        self.files: dict[str, str] = {}
        self.envs: list[dict] = []
        self.handler = handler

    def __call__(self, argv, timeout=300, env=None, **_):
        self.calls.append(argv)
        if env is not None:
            self.envs.append(env)
        for arg in argv:
            for part in str(arg).split(","):
                if "=" in part and part.split("=", 1)[1].startswith("/") and Path(part.split("=", 1)[1]).exists():
                    self.files[part.split("=", 1)[0]] = Path(part.split("=", 1)[1]).read_text()
        return self.handler(argv) if self.handler else ""


@pytest.fixture(autouse=True)
def no_sleep(monkeypatch):
    monkeypatch.setattr(remote_storage.time, "sleep", lambda s: None)


def test_specyfikacje_wymagaja_pol():
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="nfs", server="nas")  # bez eksportu
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="nfs", server="nas", export="/srv/../etc")
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="nfs", server="nas;rm", export="/vms")
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="iscsi", portal="10.0.0.5")  # bez IQN
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="lightbits", nqn="nqn.2016-01.com.lightbitslabs:uuid:x", targets=["10.0.0.9:4420"])
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="nfs", server="nas", export="/vms", options="vers=4.2;reboot")
    spec = DatastoreSpec(id=1, type="cifs", server="nas", export="vms", username="vh", password="tajne hasło")
    assert "tajne" not in repr(spec) and spec.mounted and spec.file_based
    assert not DatastoreSpec(id=2, type="storpool", template="nvme").file_based


def test_nfs_montuje_z_opcjami(settings):
    rec = Recorder()
    spec = DatastoreSpec(id=21, type="nfs", server="10.0.0.5", export="srv/vms", options="vers=4.2,nconnect=4")
    mount = RemoteMount(settings, spec, runner=rec, is_mount=lambda p: False)
    path = mount.ensure()
    assert path == settings.state_db.parent / "mnt" / "21" and path.is_dir()
    assert rec.calls == [["mount", "-t", "nfs", "-o", "hard,_netdev,vers=4.2,nconnect=4", "10.0.0.5:/srv/vms", str(path)]]

    rec.calls.clear()
    RemoteMount(settings, spec, runner=rec, is_mount=lambda p: True).ensure()
    assert rec.calls == []  # już zamontowany


def test_cifs_haslo_tylko_w_pliku(settings):
    rec = Recorder()
    spec = DatastoreSpec(id=22, type="cifs", server="nas.local", export="/maszyny", username="vh", password="S3kret!")
    path = RemoteMount(settings, spec, runner=rec, is_mount=lambda p: False).ensure()
    argv = rec.calls[0]
    assert argv[:3] == ["mount", "-t", "cifs"] and argv[-2:] == ["//nas.local/maszyny", str(path)]
    assert "S3kret!" not in " ".join(argv)
    assert rec.files["credentials"] == "username=vh\npassword=S3kret!\n"
    creds = next(p.split("=", 1)[1] for p in argv[4].split(",") if p.startswith("credentials="))
    assert not Path(creds).exists()  # plik usunięty po montowaniu


def test_cephfs_klucz_w_secretfile(settings):
    rec = Recorder()
    spec = DatastoreSpec(id=23, type="cephfs", monitors=["10.0.0.1", "ceph2:3300"], user="vh", key=KEY, export="/vms")
    RemoteMount(settings, spec, runner=rec, is_mount=lambda p: False).ensure()
    argv = rec.calls[0]
    assert argv[4].startswith("name=vh,secretfile=") and KEY not in " ".join(argv)
    assert argv[5] == "10.0.0.1:6789,ceph2:3300:/vms"
    assert rec.files["secretfile"] == KEY


def test_iscsi_logowanie_chap_i_montowanie(settings, monkeypatch):
    rec = Recorder()
    spec = DatastoreSpec(id=24, type="iscsi", portal="10.0.0.7", iqn="iqn.2026-01.pl.example:vms", lun=1,
                         username="chap", password="chapsecret12")
    mount = RemoteMount(settings, spec, runner=rec, is_mount=lambda p: False)
    assert mount.iscsi_device() == Path("/dev/disk/by-path/ip-10.0.0.7:3260-iscsi-iqn.2026-01.pl.example:vms-lun-1")
    mount.ensure()
    assert rec.calls[0] == ["iscsiadm", "-m", "discovery", "-t", "sendtargets", "-p", "10.0.0.7"]
    assert ["-n", "node.session.auth.username", "-v", "chap"] == rec.calls[2][-4:]
    assert any(c[-1] == "--login" for c in rec.calls)
    assert rec.calls[-1][0] == "mount" and rec.calls[-1][-2] == str(mount.iscsi_device())


def test_brak_narzedzia_daje_czytelny_blad(settings):
    def missing(argv):
        raise CommandError(argv, 127, "Nie znaleziono programu")

    spec = DatastoreSpec(id=25, type="iscsi", portal="10.0.0.7", iqn="iqn.2026-01.pl.example:vms")
    with pytest.raises(StorageError, match="open-iscsi"):
        RemoteMount(settings, spec, runner=Recorder(missing), is_mount=lambda p: False).ensure()


def test_router_montuje_i_trzyma_qcow2(settings, monkeypatch):
    real = dataclasses.replace(settings, driver="libvirt")
    mounted = []
    monkeypatch.setattr(RemoteMount, "ensure", lambda self: mounted.append(self.spec.id) or self.mountpoint)
    router = StorageRouter(real)
    spec = DatastoreSpec(id=26, type="nfs", server="nas", export="/vms", thin=False)
    driver = router.driver_for_spec(spec)
    assert isinstance(driver, Qcow2LocalDriver) and driver.root == real.state_db.parent / "mnt" / "26" and not driver.thin
    assert isinstance(router.driver_for_spec(DatastoreSpec(id=27, type="storpool", template="nvme")), StorPoolDriver)

    router.bind("virthub-260", spec)
    router.bind("virthub-261", DatastoreSpec(id=3, type="directory", path="/srv/x"))
    mounted.clear()
    assert router.restore_mounts() == 1 and mounted == [26]
    router.unbind("virthub-260")
    router.unbind("virthub-261")


# --- StorPool ----------------------------------------------------------------------


class FakeStorPool:
    def __init__(self):
        self.volumes: set[str] = set()
        self.snapshots: set[str] = set()
        self.calls: list[list[str]] = []

    def __call__(self, argv, timeout=300, **_):
        self.calls.append(argv)
        if argv[0] == "qemu-img":
            return json.dumps({"virtual-size": 3 * 1024**3}) if argv[1] == "info" else ""
        if argv[0] == "blockdev":
            return str(20 * 1024**3)
        args = argv[1:]
        if args[0] == "-j":
            return json.dumps({"data": [{"name": "nvme", "capacityPlaceAll": 1000 * 1024**3, "availablePlaceAll": 400 * 1024**3}]})
        if args[0] == "snapshot" and args[2] == "info":
            if args[1] not in self.snapshots:
                raise CommandError(argv, 1, f"Error: snapshot '{args[1]}' does not exist")
            return ""
        if args[0] == "volume":
            vol, cmd = args[1], args[2]
            if cmd == "info" and vol not in self.volumes:
                raise CommandError(argv, 1, f"Error: volume '{vol}' does not exist")
            if cmd == "create":
                self.volumes.add(vol)
            elif cmd == "delete":
                self.volumes.discard(vol)
            elif cmd == "snapshot":
                self.snapshots.add(args[3])
        return ""


def test_storpool_klonuje_z_migawki_szablonu(settings):
    (settings.template_dir / "alma-9.qcow2").write_bytes(b"q")
    fake = FakeStorPool()
    driver = StorPoolDriver(settings, DatastoreSpec(id=30, type="storpool", template="nvme"), runner=fake)

    dev = driver.create_volume("virthub-31", "alma-9.qcow2", 20)
    assert dev == Path("/dev/storpool/virthub-31")
    sp = [c[1:] for c in fake.calls if c[0] == "storpool"]
    base = next(s for s in fake.snapshots if s.startswith("vh-tpl-alma-9-"))
    assert ["volume", f"{base}-import", "create", "size", "3G", "template", "nvme"] in sp
    assert ["volume", "virthub-31", "create", "size", "20G", "template", "nvme", "parent", base] in sp
    assert f"{base}-import" not in fake.volumes  # wolumin importu usunięty, migawka zostaje
    assert "type='block'" in driver.disk_xml("virthub-31") and "/dev/storpool/virthub-31" in driver.disk_xml("virthub-31")

    fake.calls.clear()
    driver.create_volume("virthub-32", "alma-9.qcow2", 10)
    assert not any(c[0] == "qemu-img" and c[1] == "convert" for c in fake.calls)  # bez ponownego importu

    with pytest.raises(StorageError, match="już istnieje"):
        driver.create_volume("virthub-31", "alma-9.qcow2", 20)
    with pytest.raises(StorageError, match="Szablon ma 3 GB"):
        driver.create_volume("virthub-33", "alma-9.qcow2", 2)


def test_storpool_rozmiar_migawki_usuwanie(settings):
    fake = FakeStorPool()
    fake.volumes.add("virthub-34")
    driver = StorPoolDriver(settings, DatastoreSpec(id=30, type="storpool", template="nvme"), runner=fake)
    assert driver.size_gb("virthub-34") == 20
    with pytest.raises(StorageError):
        driver.resize_volume("virthub-34", 10)
    driver.resize_volume("virthub-34", 40)
    driver.snapshot_volume("virthub-34", "przed")
    driver.restore_volume("virthub-34", "przed")
    driver.delete_volume("virthub-34")
    sp = [c[1:] for c in fake.calls if c[0] == "storpool"]
    assert ["volume", "virthub-34", "update", "size", "40G"] in sp
    assert ["volume", "virthub-34", "revertToSnapshot", "virthub-34-przed"] in sp
    assert ["detach", "volume", "virthub-34", "all"] in sp and ["volume", "virthub-34", "delete", "virthub-34"] in sp
    assert driver.capacity() == (1000, 400)
    with pytest.raises(StorageError):
        driver.volume_path("virthub-34")


# --- Lightbits ---------------------------------------------------------------------


def lightbits_spec():
    return DatastoreSpec(id=40, type="lightbits", nqn="nqn.2016-01.com.lightbitslabs:uuid:abc", targets=["10.0.0.9:4420", "10.0.0.10:4420"],
                         endpoint="10.0.0.9:443", jwt=JWT, replicas=3, project="vh")


def test_lightbits_tworzy_wolumin_i_laczy_nvme(settings, monkeypatch):
    volumes: dict[str, str] = {}

    def handler(argv):
        if argv[0] == "lbcli":
            args = argv[3:-2]
            name = next((a.split("=", 1)[1] for a in args if a.startswith("--name=")), "")
            if args[:2] == ["create", "volume"]:
                volumes[name] = "6F1D2A4E-0000-4000-8000-000000000001"
            elif args[:2] == ["get", "volume"]:
                if name not in volumes:
                    raise CommandError(argv, 5, "volume not found")
                return json.dumps({"UUID": volumes[name], "name": name})
            elif args[:2] == ["get", "cluster"]:
                return json.dumps({"statistics": {"effectivePhysicalStorage": 2000 * 1024**3, "freePhysicalStorage": 1500 * 1024**3}})
        if argv[0] == "qemu-img" and argv[1] == "info":
            return json.dumps({"virtual-size": 2 * 1024**3})
        return ""

    rec = Recorder(handler)
    monkeypatch.setattr(LightbitsDriver, "_hostnqn", lambda self: "nqn.2014-08.org.nvmexpress:uuid:host1")
    (settings.template_dir / "deb-12.qcow2").write_bytes(b"q")
    driver = LightbitsDriver(settings, lightbits_spec(), runner=rec)

    dev = driver.create_volume("virthub-41", "deb-12.qcow2", 30)
    assert dev == Path("/dev/disk/by-id/nvme-uuid.6f1d2a4e-0000-4000-8000-000000000001")
    create = next(c for c in rec.calls if c[0] == "lbcli" and c[3:5] == ["create", "volume"])
    assert create[:3] == ["lbcli", "--endpoint", "10.0.0.9:443"] and "--size=30 GiB" in create
    assert "--acl=nqn.2014-08.org.nvmexpress:uuid:host1" in create and "--replica-count=3" in create
    assert JWT not in " ".join(sum(rec.calls, [])) and rec.envs[0]["LIGHTOS_JWT"] == JWT
    connects = [c for c in rec.calls if c[:2] == ["nvme", "connect"]]
    assert [c[c.index("-a") + 1] for c in connects[:2]] == ["10.0.0.9", "10.0.0.10"]
    assert any(c[:3] == ["qemu-img", "convert", "-n"] and c[-1] == str(dev) for c in rec.calls)

    assert driver.capacity() == (2000, 1500)
    driver.delete_volume("virthub-41")
    assert any(c[3:5] == ["delete", "volume"] for c in rec.calls if c[0] == "lbcli")
    with pytest.raises(StorageError, match="Migawki"):
        driver.snapshot_volume("virthub-41", "x")
