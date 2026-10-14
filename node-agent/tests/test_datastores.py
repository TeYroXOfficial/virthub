"""Magazyny danych: katalog (cienki/gruby), Ceph RBD, przypisanie dysku do magazynu."""

from __future__ import annotations

import json
import os
import stat
from pathlib import Path
from xml.etree import ElementTree as ET

import pytest
from pydantic import ValidationError

from agent import storage as storage_mod
from agent.datastores import CephRbdDriver, StorageRouter, parse_monitor
from agent.domain_xml import build_domain_xml
from agent.schemas import DatastoreSpec
from agent.shell import CommandError
from agent.storage import Qcow2LocalDriver, StorageError
from test_provisioning import create_vm

KEY = "AQBzZWNyZXRrZXlrZXlrZXlrZXlrZXk9PQ=="


def ceph_spec(**extra):
    return DatastoreSpec(id=7, type="ceph", pool="vms", monitors=["10.0.0.1", "ceph2:3300", "[2001:db8::5]:6789"],
                         user="libvirt", key=KEY, **extra)


class FakeCeph:
    """Udaje `rbd`/`ceph`/`qemu-img`: pamięta obrazy i snapshoty, zapisuje polecenia."""

    def __init__(self, images=None):
        self.images: dict[str, int] = dict(images or {})
        self.calls: list[list[str]] = []
        self.keyfiles: list[str] = []

    def __call__(self, argv, timeout=300, **_):
        self.calls.append(argv)
        if argv[0] == "qemu-img":
            Path(argv[-1]).write_bytes(b"\0")
            return ""
        assert "--keyfile" in argv and KEY not in argv
        keyfile = argv[argv.index("--keyfile") + 1]
        assert Path(keyfile).read_text() == KEY
        assert stat.S_IMODE(os.stat(keyfile).st_mode) == 0o600
        self.keyfiles.append(keyfile)
        args = argv[argv.index("--keyfile") + 2:]
        if argv[0] == "ceph":
            return json.dumps({"pools": [{"name": "vms", "stats": {"stored": 300 * 1024**3, "max_avail": 700 * 1024**3}}]})
        cmd = args[0]
        if cmd == "info":
            image = args[-1]
            if image not in self.images:
                raise CommandError(argv, 2, f"rbd: error opening image {image}: (2) No such file or directory")
            return json.dumps({"size": self.images[image]})
        if cmd == "import":
            self.images[args[-1]] = 10 * 1024**3
        elif cmd == "snap" and args[1] == "create":
            image = args[-1].split("@")[0]
            self.images[args[-1]] = self.images[image]
        elif cmd == "clone":
            self.images[args[-1]] = self.images[args[-2]]
        elif cmd == "resize":
            self.images[args[-1]] = int(args[args.index("--size") + 1].rstrip("G")) * 1024**3
        elif cmd == "rm":
            self.images.pop(args[-1], None)
        return ""

    def subcommands(self):
        return [c[c.index("--keyfile") + 2:] for c in self.calls if c[0] == "rbd"]


@pytest.fixture()
def tpl(settings):
    path = settings.template_dir / "debian-13.qcow2"
    path.write_bytes(b"qcow")
    return path.name


def test_specyfikacja_wymaga_pol():
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="ceph", pool="vms", monitors=["10.0.0.1"])  # bez klucza
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="directory", path="/srv/../etc")
    with pytest.raises(ValidationError):
        DatastoreSpec(id=1, type="ceph", pool="vms; rm -rf", monitors=["10.0.0.1"], key=KEY)
    assert KEY not in repr(ceph_spec())


def test_monitory():
    assert parse_monitor("10.0.0.1") == ("10.0.0.1", "6789")
    assert parse_monitor("ceph2:3300") == ("ceph2", "3300")
    assert parse_monitor("[2001:db8::5]:6789") == ("2001:db8::5", "6789")


def test_ceph_pierwszy_dysk_importuje_szablon_i_klonuje(settings, tpl):
    fake = FakeCeph()
    driver = CephRbdDriver(settings, ceph_spec(), runner=fake)

    path = driver.create_volume("virthub-5", tpl, 40)

    base = f"vms/{CephRbdDriver.template_image(tpl)}"
    subs = fake.subcommands()
    assert ["import", "--no-progress", "--image-format", "2"] == subs[[s[0] for s in subs].index("import")][:4]
    assert ["snap", "protect", f"{base}@base"] in subs
    assert ["clone", f"{base}@base", "vms/virthub-5"] in subs
    assert ["resize", "--no-progress", "--size", "40G", "vms/virthub-5"] in subs
    assert str(path) == "rbd:vms/virthub-5"
    assert all(not Path(k).exists() for k in fake.keyfiles)
    assert not list(settings.image_dir.glob(".vh-tpl-*.raw"))
    assert all("--id" in c and "libvirt" in c for c in fake.calls if c[0] == "rbd")

    # Drugi dysk z tego samego szablonu: bez ponownego importu.
    fake.calls.clear()
    driver.create_volume("virthub-6", tpl, 10)
    assert not any(s[0] == "import" for s in fake.subcommands())
    assert not any(s[0] == "resize" for s in fake.subcommands())


def test_ceph_rozmiar_migawki_usuwanie(settings, tpl):
    fake = FakeCeph({"vms/virthub-5": 20 * 1024**3})
    driver = CephRbdDriver(settings, ceph_spec(), runner=fake)

    assert driver.size_gb("virthub-5") == 20
    with pytest.raises(StorageError):
        driver.resize_volume("virthub-5", 10)
    driver.resize_volume("virthub-5", 30)
    assert driver.size_gb("virthub-5") == 30

    driver.snapshot_volume("virthub-5", "przed-aktualizacja")
    driver.restore_volume("virthub-5", "przed-aktualizacja")
    assert ["snap", "rollback", "--no-progress", "vms/virthub-5@przed-aktualizacja"] in fake.subcommands()
    with pytest.raises(StorageError):
        driver.restore_volume("virthub-5", "nie-ma")

    driver.delete_volume("virthub-5")
    assert ["rm", "--no-progress", "vms/virthub-5"] in fake.subcommands()
    driver.delete_volume("virthub-5")  # drugi raz: nic się nie dzieje

    assert driver.capacity() == (1000, 700)
    with pytest.raises(StorageError):
        driver.volume_path("virthub-5")
    assert driver.has_overlay("virthub-5") is False
    with pytest.raises(StorageError):
        driver.overlay_path("virthub-5")


def test_ceph_dysk_za_maly_na_szablon(settings, tpl):
    fake = FakeCeph()
    with pytest.raises(StorageError, match="Szablon ma 10 GB"):
        CephRbdDriver(settings, ceph_spec(), runner=fake).create_volume("virthub-9", tpl, 5)


def test_ceph_xml_dysku_sieciowego(settings):
    driver = CephRbdDriver(settings, ceph_spec(), runner=FakeCeph())
    xml = build_domain_xml(
        name="virthub-5", uuid="6f1d2a4e-9c3b-4b8e-8f2a-1d2c3b4a5e6f", vcpu=1, ram_mb=512, disk_xml=driver.disk_xml("virthub-5"),
        seed_path="/seed.iso", bridge="br0", mac="52:54:00:00:00:05", interface_target="vh5", vnc_listen="127.0.0.1", vnc_password="x",
    )
    disk = ET.fromstring(xml).find("devices/disk[@device='disk']")
    assert disk.get("type") == "network"
    source = disk.find("source")
    assert source.get("protocol") == "rbd" and source.get("name") == "vms/virthub-5"
    assert [(h.get("name"), h.get("port")) for h in source.findall("host")] == [
        ("10.0.0.1", "6789"), ("ceph2", "3300"), ("2001:db8::5", "6789")]
    assert disk.find("auth").get("username") == "libvirt"
    assert disk.find("auth/secret").get("uuid") == driver.secret_uuid
    assert disk.find("driver").get("type") == "raw"
    assert KEY not in xml


def test_ceph_sekret_libvirt(settings, monkeypatch):
    import base64
    import sys
    import types

    class Err(Exception):
        pass

    libvirt = types.SimpleNamespace(libvirtError=Err)
    monkeypatch.setitem(sys.modules, "libvirt", libvirt)

    class Secret:
        value = None

        def setValue(self, value):
            Secret.value = value

    class Conn:
        defined = None

        def secretLookupByUUIDString(self, uuid):
            raise libvirt.libvirtError("brak")

        def secretDefineXML(self, xml):
            Conn.defined = xml
            return Secret()

    driver = CephRbdDriver(settings, ceph_spec(), runner=FakeCeph())
    driver.libvirt_prepare(Conn())
    assert driver.secret_uuid in Conn.defined and "type='ceph'" in Conn.defined
    assert Secret.value == base64.b64decode(KEY)


def test_katalog_gruby_rezerwuje_miejsce(settings, tpl, tmp_path, monkeypatch):
    calls = []
    monkeypatch.setattr(storage_mod, "run", lambda argv, **kw: calls.append(argv) or "")
    driver = Qcow2LocalDriver(settings, root=tmp_path / "ds", thin=False)
    path = driver.create_volume("virthub-5", tpl, 30)
    assert path == tmp_path / "ds" / "virthub-5.qcow2"
    assert calls[0][:2] == ["qemu-img", "convert"] and "preallocation=falloc" in calls[0]
    assert calls[1] == ["qemu-img", "resize", "--preallocation=falloc", str(path), "30G"]

    calls.clear()
    Qcow2LocalDriver(settings, root=tmp_path / "ds2").create_volume("virthub-6", tpl, 30)
    assert calls[0][:2] == ["qemu-img", "create"] and "-b" in calls[0]


def test_router_pamieta_magazyn_dysku(settings, tpl):
    router = StorageRouter(settings)
    spec = DatastoreSpec(id=3, type="directory", path="/srv/vh-ds3", thin=True)
    router.bind("virthub-40", spec)
    assert stat.S_IMODE(os.stat(router.registry_path).st_mode) == 0o600
    assert router.spec_for("virthub-40") == spec
    path = router.create_volume("virthub-40", tpl, 15)
    assert path.parent == settings.image_dir / "datastore-3"  # tryb mock: katalog magazynu w image_dir
    assert router.size_gb("virthub-40") == 15
    assert router.spec_for("virthub-41") is None
    router.unbind("virthub-40")
    assert router.spec_for("virthub-40") is None


def test_router_raport_magazynow(settings):
    router = StorageRouter(settings)
    ok, bad = router.status([
        DatastoreSpec(id=1, type="directory", path="/srv/x"),
        ceph_spec(),
    ])
    assert ok["id"] == 1 and ok["ok"] is True and ok["total_gb"] >= ok["free_gb"]
    # W trybie mock Ceph też jest katalogiem — raport działa.
    assert bad["id"] == 7 and bad["ok"] is True


def test_api_tworzy_maszyne_w_magazynie_i_raportuje(client, template, settings):
    storage = {"id": 12, "type": "directory", "path": "/srv/vh-ds12", "thin": True}
    job = create_vm(client, template, server_id=4012, storage=storage)
    assert job["status"] == "done", job
    assert (settings.image_dir / "datastore-12" / "virthub-4012.qcow2").exists()

    response = client.post("/system/datastores", json={"datastores": [storage]})
    assert response.status_code == 200, response.text
    [report] = response.json()["datastores"]
    assert report["id"] == 12 and report["ok"] is True

    uuid = job["result"]["uuid"]
    deleted = client.delete(f"/vm/{uuid}")
    assert deleted.status_code == 202
    from test_provisioning import wait_for_job

    assert wait_for_job(client, deleted.json()["job_id"])["status"] == "done"
    assert not (settings.image_dir / "datastore-12" / "virthub-4012.qcow2").exists()
    assert StorageRouter(settings).spec_for("virthub-4012") is None
