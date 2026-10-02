"""Monitoring hosta: liczniki z /proc, /sys i cgroup na sztucznym drzewie plików."""

from pathlib import Path

from agent.monitor import HostMonitor, classify


def _write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text)


class FakeHost:
    def __init__(self, root: Path) -> None:
        self.proc, self.sys, self.cgroup = root / "proc", root / "sys", root / "sys" / "fs" / "cgroup"
        self.t = 100.0
        _write(self.proc / "loadavg", "1.50 1.00 0.50 2/300 1234\n")
        _write(self.proc / "uptime", "3600.5 100.0\n")
        _write(self.proc / "cpuinfo", "model name\t: Test CPU\ncpu MHz\t\t: 3000.000\nmodel name\t: Test CPU\ncpu MHz\t\t: 2000.000\n")
        _write(self.proc / "meminfo", "MemTotal: 1000 kB\nMemFree: 100 kB\nMemAvailable: 400 kB\nCached: 200 kB\nBuffers: 50 kB\nSwapTotal: 500 kB\nSwapFree: 400 kB\n")
        _write(self.proc / "mounts", "")
        _write(self.proc / "pressure" / "cpu", "some avg10=12.50 avg60=1.00 avg300=0.00 total=1\n")
        # Dysk: rozmiar, model; hwmon: procesor i NVMe.
        _write(self.sys / "block" / "nvme0n1" / "size", "2000000\n")
        _write(self.sys / "block" / "nvme0n1" / "device" / "model", "Fast SSD\n")
        _write(self.sys / "block" / "nvme0n1" / "queue" / "rotational", "0\n")
        _write(self.sys / "block" / "loop0" / "size", "100\n")
        hw = self.sys / "class" / "hwmon"
        _write(hw / "hwmon0" / "name", "coretemp\n")
        _write(hw / "hwmon0" / "temp1_input", "54000\n")
        _write(hw / "hwmon0" / "temp1_label", "Package id 0\n")
        _write(hw / "hwmon0" / "temp1_crit", "100000\n")
        _write(hw / "hwmon1" / "name", "nvme\n")
        _write(hw / "hwmon1" / "temp1_input", "41850\n")
        _write(hw / "hwmon1" / "temp1_label", "Composite\n")
        # Usługi: KVM (machined), LXC (Incus), aplikacja (Docker).
        self.kvm = self.cgroup / "machine.slice" / "machine-qemu\\x2d3\\x2dvirthub\\x2d42.scope"
        self.lxc = self.cgroup / "lxc.payload.virthub-7"
        self.app = self.cgroup / "system.slice" / ("docker-" + "a" * 64 + ".scope")
        self.set(0)

    def set(self, step: int) -> None:
        """Stan liczników po `step` sekundach."""
        c = step
        _write(self.proc / "stat",
               f"cpu  {100 + 50 * c} 0 {50 + 10 * c} {1000 + 30 * c} {10 + 5 * c} 0 0 {5 * c}\n"
               f"cpu0 {50 + 25 * c} 0 {25 + 5 * c} {500 + 15 * c} {5 + 5 * c} 0 0 0\n"
               f"cpu1 {50 + 25 * c} 0 {25 + 5 * c} {500 + 15 * c} {5} 0 0 {5 * c}\n")
        _write(self.proc / "diskstats",
               f"   7       0 loop0 1 0 2 0 0 0 0 0 0 0 0\n"
               f" 259       0 nvme0n1 {100 + 200 * c} 0 {1000 + 4096 * c} {10 + 100 * c} {50 + 100 * c} 0 {500 + 2048 * c} {5 + 100 * c} 0 {100 + 500 * c} 0\n")
        _write(self.proc / "net" / "dev",
               "Inter-|   Receive\n face |bytes packets\n"
               f"    lo: 999 1 0 0 0 0 0 0 999 1 0 0 0 0 0 0\n"
               f"  eth0: {1000 + 125000 * c} 1 0 0 0 0 0 0 {500 + 12500 * c} 1 0 0 0 0 0 0\n")
        for path, usage in ((self.kvm, 2_000_000), (self.lxc, 500_000), (self.app, 1_000_000)):
            _write(path / "cpu.stat", f"usage_usec {usage * c}\nthrottled_usec {100_000 * c}\n")
            _write(path / "memory.current", "268435456\n")
            _write(path / "memory.max", "max\n")
            _write(path / "pids.current", "12\n")
            _write(path / "io.stat", f"259:0 rbytes={1_048_576 * c} wbytes={524_288 * c} rios={10 * c} wios={5 * c}\n")
            _write(path / "cpu.pressure", "some avg10=3.25 avg60=0 avg300=0 total=1\nfull avg10=1.00 avg60=0 avg300=0 total=1\n")
        # Proces qemu w cgroup maszyny 42.
        _write(self.proc / "4242" / "stat", "4242 (qemu-system-x86) S " + " ".join(["0"] * 10) + f" {100 * c} {50 * c} 0 0 20 0 8 0 0 0 1000 0\n")
        _write(self.proc / "4242" / "cgroup", "0::/machine.slice/machine-qemu\\x2d3\\x2dvirthub\\x2d42.scope/libvirt/vcpu0\n")
        self.t = 100.0 + step

    def monitor(self) -> HostMonitor:
        return HostMonitor(
            proc=self.proc, sys=self.sys, cgroup=self.cgroup,
            app_resolver=lambda: {"a" * 64: "app-uuid-1"},
            clock=lambda: self.t, sleep=lambda _s: self.set(2),
        )


def test_klasyfikacja_cgroup():
    assert classify("machine-qemu\\x2d3\\x2dvirthub\\x2d42.scope") == ("kvm", "42")
    assert classify("qemu-3-virthub-42.libvirt-qemu") == ("kvm", "42")
    assert classify("lxc.payload.virthub-7") == ("lxc", "virthub-7")
    assert classify("docker-" + "b" * 64 + ".scope") == ("app", "b" * 64)
    assert classify("b" * 64) == ("app", "b" * 64)
    assert classify("system.slice") is None
    assert classify("user.slice") is None


def test_raport_liczy_roznice_miedzy_odczytami(tmp_path):
    host = FakeHost(tmp_path)
    report = host.monitor().report()  # pierwszy odczyt: pauza (sleep → +2 s) i drugi odczyt

    assert report["interval"] == 2.0
    cpu = report["cpu"]
    # Na sekundę: user 50, system 10, idle 30, iowait 5, steal 5 → razem 100 jednostek.
    assert cpu["usage"] == 65.0 and cpu["steal"] == 5.0 and cpu["iowait"] == 5.0 and cpu["user"] == 50.0
    assert cpu["cores"] == 2 and len(cpu["per_core"]) == 2
    assert report["pressure"]["cpu"]["some"] == 12.5

    assert report["host"]["cpu_model"] == "Test CPU" and report["host"]["cpu_mhz"] == 2500
    assert report["host"]["load"] == [1.5, 1.0, 0.5]

    mem = report["memory"]
    assert mem["total"] == 1000 * 1024 and mem["used"] == 600 * 1024 and mem["swap_used"] == 100 * 1024

    [disk] = report["disks"]  # loop0 pominięty
    assert disk["name"] == "nvme0n1" and disk["model"] == "Fast SSD" and disk["rotational"] is False
    assert disk["read_bps"] == 4096 * 512 and disk["write_iops"] == 100.0 and disk["util"] == 50.0
    assert disk["await_ms"] == 0.67  # 400 ms oczekiwania na 600 operacji

    [nic] = report["network"]
    assert nic["name"] == "eth0" and nic["rx_bps"] == 125000 and nic["tx_bps"] == 12500


def test_temperatury_procesora_i_dyskow(tmp_path):
    host = FakeHost(tmp_path)
    temps = host.monitor().report()["temperatures"]
    cpu = [t for t in temps if t["category"] == "cpu"]
    disk = [t for t in temps if t["category"] == "disk"]
    assert cpu[0]["celsius"] == 54.0 and cpu[0]["critical"] == 100.0 and cpu[0]["label"] == "Package id 0"
    assert disk[0]["celsius"] == 41.9 and disk[0]["sensor"] == "nvme"


def test_uslugi_kvm_lxc_i_aplikacje_z_cgroup(tmp_path):
    host = FakeHost(tmp_path)
    services = {s["kind"]: s for s in host.monitor().report()["services"]}

    kvm = services["kvm"]
    assert kvm["server_id"] == 42
    assert kvm["cpu_pct"] == 200.0 and kvm["cpu_host_pct"] == 100.0  # dwa rdzenie
    assert kvm["throttled_pct"] == 10.0 and kvm["cpu_wait_pct"] == 3.25
    assert kvm["memory"] == 268435456 and kvm["memory_limit"] is None and kvm["pids"] == 12
    assert kvm["read_bps"] == 1_048_576 and kvm["write_bps"] == 524_288

    assert services["lxc"]["server_id"] == 7 and services["lxc"]["cpu_pct"] == 50.0
    assert services["app"]["app_uuid"] == "app-uuid-1" and services["app"]["cpu_pct"] == 100.0


def test_procesy_przypisane_do_uslug(tmp_path):
    host = FakeHost(tmp_path)
    monitor = host.monitor()
    monitor._ticks = 100
    [proc] = [p for p in monitor.report()["processes"] if p["pid"] == 4242]
    assert proc["name"] == "qemu-system-x86"
    assert proc["owner"] == {"kind": "kvm", "ref": "42", "server_id": 42}
    assert proc["cpu_pct"] == 150.0  # 300 taktów w 2 s przy 100 taktach/s → 1,5 rdzenia
    assert proc["threads"] == 8


def test_aplikacje_bez_dockera_nie_psuja_raportu(tmp_path):
    host = FakeHost(tmp_path)
    monitor = host.monitor()

    def broken():
        raise RuntimeError("docker down")

    monitor.app_resolver = broken
    report = monitor.report()
    assert {s["kind"] for s in report["services"]} == {"kvm", "lxc"}  # nieznane kontenery pominięte
