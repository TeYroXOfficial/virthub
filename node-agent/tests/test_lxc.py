"""Sterownik kontenerów (Incus) na atrapie polecenia `incus`.

Atrapa trzyma stan jak prawdziwy Incus — kontenery, obrazy, urządzenia — więc
testy sprawdzają zarówno to, jakie polecenia sterownik wydaje, jak i to, czy
po serii operacji stan jest taki, jakiego oczekuje panel.
"""

from __future__ import annotations

import dataclasses
import json

import pytest

from agent import lxc_driver
from agent.driver import DriverError, LibvirtDriver, VmNotFound, build_driver
from agent.schemas import (
    CreateVmRequest,
    NetworkInterfaceSpec,
    PowerAction,
    RebuildVmRequest,
    ResizeVmRequest,
)
from agent.shell import CommandError


class FakeIncus:
    def __init__(self):
        self.images: set[str] = set()
        self.instances: dict[str, dict] = {}
        self.calls: list[list[str]] = []
        self.inputs: list[str | None] = []
        self.operations: dict[str, list[dict]] = {}
        self.rest_images = True  # False = API odrzuca POST /1.0/images (stary Incus)
        self.fail_on: str | None = None

    def __call__(self, argv, timeout=300, check=True, input=None):
        assert argv[0] == "incus", argv
        args = argv[1:]
        self.calls.append(args)
        self.inputs.append(input)
        if self.fail_on and args[0] == self.fail_on:
            raise CommandError(argv, 1, f"symulowany błąd {self.fail_on}")
        handler = getattr(self, "_" + args[0].replace("-", "_"))
        return handler(args[1:]) or ""

    # --- polecenia ----------------------------------------------------------

    def _image(self, args):
        if args[0] == "info":
            alias = args[1].removeprefix("local:")
            if alias not in self.images:
                raise CommandError(["incus", "image", "info"], 1, "Image not found")
        elif args[0] == "copy":
            self.images.add(args[args.index("--alias") + 1])

    def _init(self, args):
        image, name = args[0], args[1]
        config, devices = {}, {}
        i = 2
        while i < len(args):
            if args[i] == "-c":
                key, _, value = args[i + 1].partition("=")
                config[key] = value
                i += 2
            elif args[i] == "-d":
                device, _, rest = args[i + 1].partition(",")
                key, _, value = rest.partition("=")
                devices.setdefault(device, {})[key] = value
                i += 2
            else:
                i += 2 if args[i].startswith("--") else 1
        self.instances[name] = {
            "name": name, "image": image, "status": "Stopped",
            "config": config, "devices": devices,
        }

    def _config(self, args):
        if args[0] == "device":
            op, name, device = args[1], args[2], args[3]
            if op == "add":
                props = dict(a.split("=", 1) for a in args[5:])
                self.instances[name]["devices"][device] = {"type": args[4], **props}
            elif op == "set":
                key, value = args[4].split("=", 1)
                self.instances[name]["devices"].setdefault(device, {})[key] = value
        elif args[0] == "set":
            name = args[1]
            for pair in args[2:]:
                key, value = pair.split("=", 1)
                self.instances[name]["config"][key] = value

    def _start(self, args):
        self.instances[args[0]]["status"] = "Running"

    def _stop(self, args):
        self.instances[args[0]]["status"] = "Stopped"

    def _restart(self, args):
        self.instances[args[0]]["status"] = "Running"

    def _file(self, args):
        # file pull <name>/etc/os-release -
        name, _, path = args[1].partition("/")
        if path == "etc/os-release":
            return 'PRETTY_NAME="Debian GNU/Linux 12 (bookworm)"\nID=debian\nVERSION_ID="12"\n'
        raise CommandError(["incus", "file"], 1, "not found")

    def _exec(self, args):
        if self.instances[args[0]]["status"] != "Running":
            raise CommandError(["incus", "exec"], 1, "Instance is not running")

    def _delete(self, args):
        del self.instances[args[0]]

    def _rebuild(self, args):
        self.instances[args[1]]["image"] = args[0]

    def _snapshot(self, args):
        self.instances[args[1]].setdefault("snapshots", set())
        if args[0] == "create":
            self.instances[args[1]]["snapshots"].add(args[2])
        elif args[2] not in self.instances[args[1]]["snapshots"]:
            raise CommandError(["incus"], 1, "snapshot not found")

    def _list(self, args):
        return json.dumps([
            {"name": i["name"], "status": i["status"], "config": i["config"]}
            for i in self.instances.values()
        ])

    def _remote(self, args):
        return json.dumps({"images": {"addr": "https://images.linuxcontainers.org", "protocol": "simplestreams"}})

    def _query(self, args):
        if args[0] == "-X":
            body = json.loads(args[3])
            if not self.rest_images:
                raise CommandError(["incus", "query"], 1, "not supported")
            self.images.add(body["aliases"][0]["name"])
            self.last_image_request = body
            op = f"op{len(self.operations) + 1}"
            self.operations[op] = [
                {"id": op, "status": "Running", "metadata": {"download_progress": "rootfs: 42% (12.3MB/s)"}},
                {"id": op, "status": "Success", "metadata": {}},
            ]
            return json.dumps({"id": op, "status": "Running"})
        path = args[0]
        if path.startswith("/1.0/operations/"):
            steps = self.operations[path.rsplit("/", 1)[1]]
            return json.dumps(steps.pop(0) if len(steps) > 1 else steps[0])
        if path.startswith("/1.0/storage-pools/"):
            return json.dumps({"space": {"total": 200 * 1024**3, "used": 50 * 1024**3}})
        parts = path.split("/")
        instance = self.instances[parts[3]]
        if path.endswith("/state"):
            return json.dumps({
                "status": instance["status"],
                "cpu": {"usage": 5_000_000_000},
                "memory": {"usage": 300 * 1024**2},
                "network": {"eth0": {"counters": {"bytes_received": 1000, "bytes_sent": 2000}}},
            })
        return json.dumps({"config": instance["config"], "devices": instance["devices"]})


@pytest.fixture()
def incus(monkeypatch):
    fake = FakeIncus()
    monkeypatch.setattr(lxc_driver, "run", fake)
    return fake


class FakeNetwork:
    def __init__(self):
        self.configured: list[int] = []
        self.torn_down: list[int] = []
        self.prepared: list[str] = []

    def prepare(self, interfaces):
        bridge = "vhnat0" if any(i.mode == "nat" for i in interfaces) else "br0"
        self.prepared.append(bridge)
        return bridge

    def configure(self, server_id, interfaces, firewall):
        self.configured.append(server_id)

    def teardown(self, server_id):
        self.torn_down.append(server_id)


@pytest.fixture()
def driver(settings, incus):
    lxc_settings = dataclasses.replace(settings, driver="lxc")
    drv = lxc_driver.IncusDriver(lxc_settings)
    drv.network = FakeNetwork()   # nft nie istnieje na stacji testowej
    drv.poll_interval = 0
    return drv


def request(server_id=42, **overrides):
    data = dict(
        server_id=server_id,
        hostname=f"ct{server_id}.example.com",
        vcpu=2,
        ram_mb=2048,
        disk_gb=20,
        template="debian/12/cloud",
        interfaces=[NetworkInterfaceSpec(address="203.0.113.42", prefix=24, gateway="203.0.113.1")],
        ssh_keys=["ssh-ed25519 AAAA test@host"],
        root_password="TajneHaslo123",
    )
    data.update(overrides)
    return CreateVmRequest(**data)


# --- tworzenie --------------------------------------------------------------

def test_szablon_pobiera_sie_sam_przy_pierwszym_uzyciu(driver, incus):
    driver.create_vm(request())

    assert incus.last_image_request["source"]["alias"] == "debian/12/cloud"
    assert incus.last_image_request["auto_update"] is True, "obraz ma się sam odświeżać o poprawki dystrybucji"


def test_zapasowe_pobieranie_poleceniem_tez_sie_odswieza(driver, incus):
    incus.rest_images = False
    driver.create_vm(request())

    copy = next(c for c in incus.calls if c[:2] == ["image", "copy"])
    assert copy[2] == "images:debian/12/cloud"
    assert "--auto-update" in copy


def test_pobrany_szablon_nie_jest_sciagany_ponownie(driver, incus):
    incus.images.add("debian/12/cloud")
    driver.create_vm(request())

    assert not any(c[:2] == ["image", "copy"] for c in incus.calls)


def test_kontener_dostaje_limity_z_pakietu(driver, incus):
    driver.create_vm(request(vcpu=4, ram_mb=4096, disk_gb=40))

    ct = incus.instances["virthub-42"]
    assert ct["config"]["limits.cpu"] == "4"
    assert ct["config"]["limits.memory"] == "4096MiB"
    assert ct["devices"]["root"]["size"] == "40GiB"
    assert ct["config"]["security.privileged"] == "false", "root w kontenerze nie może być rootem hosta"


def test_interfejs_ma_nazwe_i_mac_jak_maszyny_kvm(driver, incus):
    driver.create_vm(request(server_id=1001))

    nic = incus.instances["virthub-1001"]["devices"]["eth0"]
    # Te same wartości co w KVM — dzięki nim działają istniejące reguły firewalla.
    assert nic["host_name"] == "vh1001"
    assert nic["hwaddr"] == "52:54:00:00:03:e9"
    assert nic["nictype"] == "bridged"
    assert nic["parent"] == "br0"


def test_kontener_za_nat_stoi_na_mostku_nat(driver, incus):
    nat = NetworkInterfaceSpec(
        address="10.10.0.5", prefix=24, gateway="10.10.0.1", mode="nat",
        nat={"network": "10.10.0.0/24", "port_from": 10100, "port_to": 10119},
    )
    driver.create_vm(request(server_id=7, interfaces=[nat]))

    nic = incus.instances["virthub-7"]["devices"]["eth0"]
    assert nic["parent"] == "vhnat0", "adres prywatny nie może wylądować na mostku z kartą fizyczną"
    assert driver.network.prepared == ["vhnat0"]


def test_cloud_init_instaluje_ssh_i_wpuszcza_roota(driver, incus):
    driver.create_vm(request())

    user_data = incus.instances["virthub-42"]["config"]["cloud-init.user-data"]
    assert "openssh-server" in user_data, "obrazy kontenerów nie mają serwera SSH"
    assert "disable_root: false" in user_data
    assert "PermitRootLogin yes" in user_data
    assert "ssh-ed25519 AAAA" in user_data


def test_kontener_startuje_i_zwraca_dane_dla_panelu(driver, incus):
    result = driver.create_vm(request())

    assert result["state"] == "running"
    assert result["virtualization"] == "lxc"
    assert result["vnc_port"] is None, "kontener nie ma konsoli VNC"
    assert incus.instances["virthub-42"]["config"]["user.virthub.uuid"] == result["uuid"]
    assert driver.network.configured == [42]


def test_nieudany_start_sprzata_kontener_i_reguly(driver, incus):
    incus.fail_on = "start"

    with pytest.raises(DriverError):
        driver.create_vm(request())

    assert "virthub-42" not in incus.instances, "półkontener nie może zostać na węźle"
    assert driver.network.torn_down == [42]


@pytest.mark.parametrize("alias", [
    "innyserwer:debian/12",   # zdalny serwer obrazów podany przez klienta
    "../../etc",
    "Debian/12",
    "debian/12; rm -rf /",
    "",
])
def test_podejrzany_alias_szablonu_jest_odrzucany(driver, incus, alias):
    with pytest.raises(DriverError, match="Nieprawidłowy alias"):
        driver.create_vm(request(template=alias))

    assert incus.instances == {}


# --- zarządzanie ------------------------------------------------------------

def test_sterowanie_zasilaniem(driver, incus):
    uuid = driver.create_vm(request())["uuid"]

    assert driver.power(uuid, PowerAction.STOP)["state"] == "stopped"
    assert driver.power(uuid, PowerAction.START)["state"] == "running"
    assert driver.power(uuid, PowerAction.REBOOT)["state"] == "running"
    assert driver.power(uuid, PowerAction.FORCE_OFF)["state"] == "stopped"

    stops = [c for c in incus.calls if c[0] == "stop"]
    assert "--timeout" in stops[0], "zwykłe zatrzymanie ma dać systemowi czas na zamknięcie"
    assert "--force" in stops[-1]


def test_nieznany_uuid(driver, incus):
    with pytest.raises(VmNotFound):
        driver.power("00000000-0000-0000-0000-000000000000", PowerAction.START)


def test_zmiana_pakietu(driver, incus):
    uuid = driver.create_vm(request(disk_gb=20))["uuid"]

    driver.resize(uuid, ResizeVmRequest(vcpu=8, ram_mb=8192, disk_gb=80))

    ct = incus.instances["virthub-42"]
    assert ct["config"]["limits.cpu"] == "8"
    assert ct["config"]["limits.memory"] == "8192MiB"
    assert ct["devices"]["root"]["size"] == "80GiB"


def test_nie_da_sie_zmniejszyc_dysku(driver, incus):
    uuid = driver.create_vm(request(disk_gb=40))["uuid"]

    with pytest.raises(DriverError, match="Zmniejszenie dysku"):
        driver.resize(uuid, ResizeVmRequest(vcpu=2, ram_mb=2048, disk_gb=20))


def test_przebudowa_zachowuje_kontener_i_podmienia_system(driver, incus):
    uuid = driver.create_vm(request())["uuid"]
    incus.images.add("ubuntu/24.04/cloud")

    result = driver.rebuild(uuid, RebuildVmRequest(
        template="ubuntu/24.04/cloud", root_password="NoweHaslo456",
    ))

    ct = incus.instances["virthub-42"]
    assert ct["image"] == "local:ubuntu/24.04/cloud"
    assert ct["config"]["user.virthub.uuid"] == uuid, "UUID musi przetrwać przebudowę"
    assert "NoweHaslo456" in ct["config"]["cloud-init.user-data"]
    assert result["state"] == "running"


def test_reset_hasla_idzie_przez_stdin(driver, incus):
    uuid = driver.create_vm(request())["uuid"]

    driver.reset_password(uuid, "NoweHaslo789")

    i = incus.calls.index(["exec", "virthub-42", "--", "chpasswd"])
    assert incus.inputs[i] == "root:NoweHaslo789\n", "hasło idzie przez stdin, nie przez argumenty"


def test_snapshot_i_przywrocenie_bez_zatrzymywania(driver, incus):
    uuid = driver.create_vm(request())["uuid"]

    driver.snapshot(uuid, "przed-aktualizacja")
    result = driver.restore(uuid, "przed-aktualizacja")

    assert "przed-aktualizacja" in incus.instances["virthub-42"]["snapshots"]
    assert result["state"] == "running"


def test_usuniecie_sprzata_reguly(driver, incus):
    uuid = driver.create_vm(request())["uuid"]

    driver.delete(uuid)

    assert incus.instances == {}
    assert driver.network.torn_down == [42]


# --- telemetria i węzeł -----------------------------------------------------

def test_statystyki_w_formacie_panelu(driver, incus):
    uuid = driver.create_vm(request(ram_mb=2048))["uuid"]

    stats = driver.stats(uuid)

    assert stats.state == "running"
    assert stats.cpu_time_ns == 5_000_000_000, "panel liczy procent z narastającego licznika"
    assert stats.ram_used_mb == 300
    assert stats.ram_total_mb == 2048, "limit kontenera, a nie pamięć hosta"
    assert stats.net_rx_bytes == 1000
    assert stats.net_tx_bytes == 2000


def test_health_raportuje_kontenery_i_pule(driver, incus):
    driver.create_vm(request(server_id=1))
    driver.create_vm(request(server_id=2))
    driver.power(driver.create_vm(request(server_id=3))["uuid"], PowerAction.STOP)

    health = driver.health()

    assert health.virtualization == "lxc"
    assert health.running_vms == 2
    assert health.disk_gb_total == 200, "pojemność puli Incusa, nie katalogu agenta"
    assert health.disk_gb_free == 150


def test_pobieranie_szablonu_z_wyprzedzeniem(driver, incus):
    first = driver.prefetch_image("almalinux/9/cloud")
    second = driver.prefetch_image("almalinux/9/cloud")

    assert first["downloaded"] is True
    assert second["cached"] is True
    posts = [c for c in incus.calls if c[:3] == ["query", "-X", "POST"]]
    assert len(posts) == 1, "drugi raz obraz jest już na węźle"
    source = incus.last_image_request["source"]
    assert source["alias"] == "almalinux/9/cloud"
    assert source["server"] == "https://images.linuxcontainers.org"
    assert incus.last_image_request["auto_update"] is True


def test_postep_pobierania_obrazu_trafia_do_zadania(driver, incus, monkeypatch):
    seen = []
    monkeypatch.setattr(lxc_driver, "progress", lambda stage, pct=None, detail=None: seen.append((stage, pct, detail)))

    driver.prefetch_image("debian/13/cloud")

    assert ("download", 42, "42% · 12.3MB/s") in seen
    assert seen[-1][:2] == ("download", 100)


def test_bez_api_obrazow_pobiera_poleceniem(driver, incus):
    incus.rest_images = False

    assert driver.prefetch_image("debian/12/cloud")["downloaded"] is True
    assert any(c[:2] == ["image", "copy"] for c in incus.calls)


def test_wezel_kvm_odmawia_pobierania_szablonow_kontenerow(settings):
    with pytest.raises(DriverError, match="KVM"):
        LibvirtDriver(settings).prefetch_image("debian/12/cloud")


def test_fabryka_wybiera_sterownik_kontenerow(settings, incus):
    drv = build_driver(dataclasses.replace(settings, driver="lxc"))
    assert isinstance(drv, lxc_driver.IncusDriver)


def test_io_dysku_kontenera_z_cgroup(tmp_path):
    cg = tmp_path / "lxc.payload.virthub-7"
    cg.mkdir()
    (cg / "io.stat").write_text(
        "8:0 rbytes=1000 wbytes=200 rios=3 wios=1 dbytes=0 dios=0\n"
        "259:0 rbytes=24 wbytes=800 rios=1 wios=2 dbytes=0 dios=0\n"
    )
    assert lxc_driver._cgroup_io("virthub-7", tmp_path) == {"disk_read_bytes": 1024, "disk_write_bytes": 1000}
    assert lxc_driver._cgroup_io("brak", tmp_path) == {"disk_read_bytes": 0, "disk_write_bytes": 0}


def test_system_kontenera_z_os_release(driver, incus):
    uuid = driver.create_vm(request())["uuid"]

    info = driver.guest_os(uuid)

    assert info.id == "debian"
    assert info.pretty_name == "Debian GNU/Linux 12 (bookworm)"
    assert info.source == "os-release"
