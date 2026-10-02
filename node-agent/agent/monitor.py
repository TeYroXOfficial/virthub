"""Szczegółowy monitoring hosta dla panelu administratora.

Wszystko czytamy z /proc, /sys i drzewa cgroup v2 — bez roota i bez
dodatkowych narzędzi (agent działa jako zwykły użytkownik):

* procesor: zajętość ogółem i na rdzeń, user/system/iowait/steal, PSI,
* pamięć i swap, wolne/podręczne,
* dyski: przepustowość, IOPS, zajętość czasu (util) i czas oczekiwania,
  systemy plików z zajętością miejsca i i-węzłów,
* sieć: ruch na interfejsach hosta,
* temperatury: procesor i dyski z hwmon (NVMe zawsze, SATA z modułem drivetemp),
* usługi: maszyny KVM, kontenery LXC (Incus) i aplikacje (Docker) z ich
  cgroup — CPU, pamięć, I/O, liczba procesów i presja CPU (czekanie na
  procesor, odpowiednik „steal” widziany od strony gościa),
* najbardziej obciążające procesy z przypisaniem do usługi.

Wartości „na sekundę” to różnice między dwoma odczytami. Poprzedni odczyt
trzymamy w pamięci; gdy jest za stary, robimy krótką pauzę i drugi odczyt.
"""

from __future__ import annotations

import os
import re
import threading
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Callable

SECTOR = 512
MAX_INTERVAL = 30.0
MIN_INTERVAL = 0.5

_KVM = re.compile(r"qemu.*?virthub(?:\\x2d|-)(\d+)")
_LXC = re.compile(r"^lxc\.payload\.(.+)$")
_DOCKER = re.compile(r"^(?:docker-)?([0-9a-f]{64})(?:\.scope)?$")
_VIRTHUB_ID = re.compile(r"virthub-(\d+)$")

_SKIP_DISKS = ("loop", "ram", "zram", "sr", "fd", "nbd")
_SKIP_NICS = ("lo", "veth", "vnet", "tap", "fwbr", "fwln", "fwpr", "virbr0-nic")
_REAL_FS = {"ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "f2fs", "vfat", "ntfs3"}
_CPU_SENSORS = {"coretemp", "k10temp", "zenpower", "cpu_thermal", "soc_thermal", "x86_pkg_temp", "fam15h_power"}
_DISK_SENSORS = {"nvme", "drivetemp"}


def _read(path: Path) -> str | None:
    try:
        return path.read_text()
    except OSError:
        return None


def _int(path: Path) -> int | None:
    text = _read(path)
    try:
        return int(text.strip()) if text is not None else None
    except ValueError:
        return None


def _rate(new: float, old: float | None, seconds: float) -> float | None:
    if old is None or seconds <= 0 or new < old:
        return None
    return (new - old) / seconds


def classify(cgroup_name: str) -> tuple[str, str] | None:
    """(rodzaj, identyfikator) usługi z nazwy katalogu cgroup albo None.

    kvm → id maszyny w panelu, lxc → nazwa instancji Incus, app → id kontenera."""
    if m := _KVM.search(cgroup_name):
        return ("kvm", m.group(1))
    if m := _LXC.match(cgroup_name):
        return ("lxc", m.group(1))
    if m := _DOCKER.match(cgroup_name):
        return ("app", m.group(1))
    return None


@dataclass
class _Snapshot:
    at: float
    cpu: dict[str, list[int]] = field(default_factory=dict)
    disks: dict[str, list[int]] = field(default_factory=dict)
    nics: dict[str, tuple[int, int]] = field(default_factory=dict)
    cgroups: dict[str, dict[str, Any]] = field(default_factory=dict)
    procs: dict[int, dict[str, Any]] = field(default_factory=dict)


class HostMonitor:
    def __init__(
        self,
        proc: Path = Path("/proc"),
        sys: Path = Path("/sys"),
        cgroup: Path = Path("/sys/fs/cgroup"),
        app_resolver: Callable[[], dict[str, str]] | None = None,
        clock: Callable[[], float] = time.monotonic,
        sleep: Callable[[float], None] = time.sleep,
    ) -> None:
        self.proc, self.sys, self.cgroup = proc, sys, cgroup
        self.app_resolver = app_resolver
        self.clock, self.sleep = clock, sleep
        self._last: _Snapshot | None = None
        self._lock = threading.Lock()
        self._ticks = os.sysconf("SC_CLK_TCK") if hasattr(os, "sysconf") else 100
        self._page = os.sysconf("SC_PAGE_SIZE") if hasattr(os, "sysconf") else 4096

    # --- publiczne -------------------------------------------------------------

    def report(self) -> dict[str, Any]:
        with self._lock:
            prev = self._last
            now = self._snapshot()
            if prev is None or now.at - prev.at > MAX_INTERVAL or now.at - prev.at < MIN_INTERVAL:
                prev = now
                self.sleep(1.0)
                now = self._snapshot()
            self._last = now
        seconds = max(now.at - prev.at, 1e-6)
        apps = self._resolve_apps()

        return {
            "interval": round(seconds, 2),
            "host": self._host(),
            "cpu": self._cpu(prev, now),
            "pressure": self._pressure(self.proc / "pressure"),
            "memory": self._memory(),
            "disks": self._disks(prev, now, seconds),
            "filesystems": self._filesystems(),
            "network": self._network(prev, now, seconds),
            "temperatures": self._temperatures(),
            "services": self._services(prev, now, seconds, apps),
            "processes": self._processes(prev, now, seconds, apps),
        }

    # --- odczyt surowych liczników --------------------------------------------

    def _snapshot(self) -> _Snapshot:
        snap = _Snapshot(at=self.clock())
        for line in (_read(self.proc / "stat") or "").splitlines():
            if line.startswith("cpu"):
                name, *vals = line.split()
                snap.cpu[name] = [int(v) for v in vals[:8]] + [0] * max(0, 8 - len(vals))
        for line in (_read(self.proc / "diskstats") or "").splitlines():
            parts = line.split()
            if len(parts) >= 14:
                snap.disks[parts[2]] = [int(v) for v in parts[3:14]]
        for line in (_read(self.proc / "net" / "dev") or "").splitlines()[2:]:
            name, _, rest = line.partition(":")
            vals = rest.split()
            if len(vals) >= 9:
                snap.nics[name.strip()] = (int(vals[0]), int(vals[8]))
        snap.cgroups = self._cgroup_counters()
        snap.procs = self._proc_counters()
        return snap

    def _cgroup_counters(self) -> dict[str, dict[str, Any]]:
        out: dict[str, dict[str, Any]] = {}

        def walk(directory: Path, depth: int) -> None:
            try:
                children = [p for p in directory.iterdir() if p.is_dir()]
            except OSError:
                return
            for child in children:
                kind = classify(child.name)
                if kind is None:
                    if depth < 3:
                        walk(child, depth + 1)
                    continue
                cpu = {}
                for line in (_read(child / "cpu.stat") or "").splitlines():
                    key, _, value = line.partition(" ")
                    if value.strip().isdigit():
                        cpu[key] = int(value)
                if "usage_usec" not in cpu and (ns := _int(child / "cpuacct.usage")) is not None:
                    cpu["usage_usec"] = ns // 1000  # cgroup v1 (np. Ubuntu 20.04)
                rbytes = wbytes = rios = wios = 0
                io_stat = _read(child / "io.stat")
                blkio = _read(child / "blkio.throttle.io_service_bytes")
                for line in (io_stat or "").splitlines():
                    for token in line.split()[1:]:
                        key, _, value = token.partition("=")
                        if value.isdigit():
                            rbytes += int(value) if key == "rbytes" else 0
                            wbytes += int(value) if key == "wbytes" else 0
                            rios += int(value) if key == "rios" else 0
                            wios += int(value) if key == "wios" else 0
                for line in (blkio or "").splitlines():
                    parts = line.split()
                    if len(parts) == 3 and parts[2].isdigit():
                        rbytes += int(parts[2]) if parts[1] == "Read" else 0
                        wbytes += int(parts[2]) if parts[1] == "Write" else 0
                row = {
                    "kind": kind[0],
                    "ref": kind[1],
                    "usage_usec": cpu.get("usage_usec"),
                    "throttled_usec": cpu.get("throttled_usec", cpu["throttled_time"] // 1000 if "throttled_time" in cpu else None),
                    "memory": _int(child / "memory.current") or _int(child / "memory.usage_in_bytes"),
                    "memory_max": ((_read(child / "memory.max") or _read(child / "memory.limit_in_bytes") or "")).strip(),
                    "pids": _int(child / "pids.current"),
                    "io": (rbytes, wbytes, rios, wios) if io_stat is not None or blkio is not None else None,
                    "pressure": self._pressure_file(child / "cpu.pressure"),
                }
                # cgroup v1 ma osobne drzewo na każdy kontroler — ta sama usługa
                # pojawia się kilka razy; scalamy, biorąc pierwszą znaną wartość.
                key = f"{kind[0]}:{kind[1]}"
                if key in out:
                    for field_name, value in row.items():
                        if out[key].get(field_name) in (None, "") and value not in (None, ""):
                            out[key][field_name] = value
                else:
                    out[key] = row

        walk(self.cgroup, 0)
        return out

    def _proc_counters(self) -> dict[int, dict[str, Any]]:
        out: dict[int, dict[str, Any]] = {}
        try:
            entries = [e for e in os.listdir(self.proc) if e.isdigit()]
        except OSError:
            return out
        for entry in entries:
            base = self.proc / entry
            stat = _read(base / "stat")
            if not stat:
                continue
            # comm jest w nawiasach i może zawierać spacje — dzielimy po ostatnim „)”.
            head, _, tail = stat.rpartition(")")
            fields = tail.split()
            if len(fields) < 22:
                continue
            cgroup_line = (_read(base / "cgroup") or "").strip().splitlines()
            cgroup_path = cgroup_line[-1].partition("::")[2] if cgroup_line else ""
            out[int(entry)] = {
                "comm": head.partition("(")[2],
                "ticks": int(fields[11]) + int(fields[12]),
                "rss": int(fields[21]) * self._page,
                "threads": int(fields[17]),
                "cgroup": cgroup_path,
            }
        return out

    # --- sekcje raportu --------------------------------------------------------

    def _host(self) -> dict[str, Any]:
        cpuinfo = _read(self.proc / "cpuinfo") or ""
        model = next((ln.split(":", 1)[1].strip() for ln in cpuinfo.splitlines() if ln.startswith("model name")), None)
        mhz = [float(ln.split(":", 1)[1]) for ln in cpuinfo.splitlines() if ln.startswith("cpu MHz")]
        load = (_read(self.proc / "loadavg") or "0 0 0").split()[:3]
        uptime = (_read(self.proc / "uptime") or "0").split()[0]
        uname = os.uname()
        return {
            "hostname": uname.nodename,
            "kernel": uname.release,
            "cpu_model": model,
            "cpu_mhz": round(sum(mhz) / len(mhz)) if mhz else None,
            "load": [float(v) for v in load],
            "uptime": int(float(uptime)),
        }

    def _cpu(self, prev: _Snapshot, now: _Snapshot) -> dict[str, Any]:
        def split(name: str) -> dict[str, float] | None:
            if name not in now.cpu or name not in prev.cpu:
                return None
            delta = [max(0, a - b) for a, b in zip(now.cpu[name], prev.cpu[name])]
            total = sum(delta) or 1
            user, nice, system, idle, iowait, irq, softirq, steal = delta
            pct = lambda v: round(v / total * 100, 1)  # noqa: E731
            return {
                "usage": pct(total - idle - iowait),
                "user": pct(user + nice),
                "system": pct(system + irq + softirq),
                "iowait": pct(iowait),
                "steal": pct(steal),
                "idle": pct(idle),
            }

        cores = sorted((n for n in now.cpu if n != "cpu"), key=lambda n: int(n[3:]))
        per_core = [s["usage"] for s in map(split, cores) if s]
        return {**(split("cpu") or {}), "cores": len(cores), "per_core": per_core}

    def _pressure_file(self, path: Path) -> dict[str, float] | None:
        text = _read(path)
        if not text:
            return None
        out = {}
        for line in text.splitlines():
            kind, *pairs = line.split()
            values = dict(p.split("=", 1) for p in pairs)
            if "avg10" in values:
                out[kind] = float(values["avg10"])
        return out or None

    def _pressure(self, directory: Path) -> dict[str, Any]:
        return {res: self._pressure_file(directory / res) for res in ("cpu", "memory", "io")}

    def _memory(self) -> dict[str, int]:
        info: dict[str, int] = {}
        for line in (_read(self.proc / "meminfo") or "").splitlines():
            key, _, value = line.partition(":")
            parts = value.split()
            if parts and parts[0].isdigit():
                info[key] = int(parts[0]) * 1024
        total = info.get("MemTotal", 0)
        available = info.get("MemAvailable", info.get("MemFree", 0))
        return {
            "total": total,
            "used": total - available,
            "available": available,
            "cached": info.get("Cached", 0) + info.get("Buffers", 0),
            "dirty": info.get("Dirty", 0),
            "swap_total": info.get("SwapTotal", 0),
            "swap_used": info.get("SwapTotal", 0) - info.get("SwapFree", 0),
            "hugepages_total": info.get("HugePages_Total", 0),
        }

    def _disks(self, prev: _Snapshot, now: _Snapshot, seconds: float) -> list[dict[str, Any]]:
        try:
            block = set(os.listdir(self.sys / "block"))
        except OSError:
            block = set(now.disks)
        out = []
        for name in sorted(block & set(now.disks)):
            if name.startswith(_SKIP_DISKS):
                continue
            cur, old = now.disks[name], prev.disks.get(name)
            reads, _, rsec, rms, writes, _, wsec, wms, _, busy, _ = cur
            dev = self.sys / "block" / name
            size = (_int(dev / "size") or 0) * SECTOR
            if size == 0:
                continue
            row: dict[str, Any] = {
                "name": name,
                "model": (_read(dev / "device" / "model") or "").strip() or None,
                "rotational": _int(dev / "queue" / "rotational") == 1,
                "size": size,
            }
            if old is not None:
                d = [max(0, a - b) for a, b in zip(cur, old)]
                ios = d[0] + d[4]
                row.update({
                    "read_bps": round(d[2] * SECTOR / seconds),
                    "write_bps": round(d[6] * SECTOR / seconds),
                    "read_iops": round(d[0] / seconds, 1),
                    "write_iops": round(d[4] / seconds, 1),
                    "util": round(min(100.0, d[9] / (seconds * 1000) * 100), 1),
                    "await_ms": round((d[3] + d[7]) / ios, 2) if ios else 0.0,
                })
            out.append(row)
        return out

    def _filesystems(self) -> list[dict[str, Any]]:
        seen: set[str] = set()
        out = []
        for line in (_read(self.proc / "mounts") or "").splitlines():
            parts = line.split()
            if len(parts) < 3 or parts[2] not in _REAL_FS or parts[0] in seen:
                continue
            seen.add(parts[0])
            mount = parts[1].replace("\\040", " ")
            try:
                st = os.statvfs(mount)
            except OSError:
                continue
            total = st.f_blocks * st.f_frsize
            if total == 0:
                continue
            out.append({
                "device": parts[0],
                "mount": mount,
                "type": parts[2],
                "total": total,
                "used": (st.f_blocks - st.f_bfree) * st.f_frsize,
                "inodes_used_pct": round((st.f_files - st.f_ffree) / st.f_files * 100, 1) if st.f_files else None,
            })
        return out

    def _network(self, prev: _Snapshot, now: _Snapshot, seconds: float) -> list[dict[str, Any]]:
        out = []
        for name, (rx, tx) in sorted(now.nics.items()):
            if name.startswith(_SKIP_NICS):
                continue
            old = prev.nics.get(name)
            out.append({
                "name": name,
                "rx_bps": round(_rate(rx, old[0] if old else None, seconds) or 0),
                "tx_bps": round(_rate(tx, old[1] if old else None, seconds) or 0),
                "rx_total": rx,
                "tx_total": tx,
            })
        return out

    def _temperatures(self) -> list[dict[str, Any]]:
        out = []
        base = self.sys / "class" / "hwmon"
        try:
            monitors = sorted(base.iterdir())
        except OSError:
            monitors = []
        for hw in monitors:
            name = (_read(hw / "name") or "").strip()
            category = "cpu" if name in _CPU_SENSORS else "disk" if name in _DISK_SENSORS else "other"
            device = None
            if category == "disk":
                real = (hw / "device").resolve()
                blocks = list((real / "block").glob("*")) if (real / "block").is_dir() else []
                device = blocks[0].name if blocks else real.name
            for temp in sorted(hw.glob("temp*_input")):
                value = _int(temp)
                if value is None:
                    continue
                prefix = temp.name[: -len("_input")]
                label = (_read(hw / f"{prefix}_label") or "").strip() or name
                crit = _int(hw / f"{prefix}_crit") or _int(hw / f"{prefix}_max")
                out.append({
                    "category": category,
                    "sensor": name,
                    "device": device,
                    "label": label,
                    "celsius": round(value / 1000, 1),
                    "critical": round(crit / 1000, 1) if crit and crit > 0 else None,
                })
        if not any(t["category"] == "cpu" for t in out):
            # Bez hwmon procesora (np. maszyna wirtualna, ARM) — strefy termiczne.
            for zone in sorted((self.sys / "class" / "thermal").glob("thermal_zone*")):
                value = _int(zone / "temp")
                kind = (_read(zone / "type") or "").strip()
                if value is not None and value > 0:
                    out.append({"category": "cpu" if "pkg" in kind or "cpu" in kind else "other",
                                "sensor": kind, "device": None, "label": kind,
                                "celsius": round(value / 1000, 1), "critical": None})
        return out

    def _services(self, prev: _Snapshot, now: _Snapshot, seconds: float, apps: dict[str, str]) -> list[dict[str, Any]]:
        cores = max(1, len(now.cpu) - 1)
        out = []
        for path, cur in now.cgroups.items():
            old = prev.cgroups.get(path)
            cpu_pct = None
            read_bps = write_bps = None
            throttled = None
            if old is not None:
                if cur["usage_usec"] is not None and old["usage_usec"] is not None:
                    cpu_pct = round(max(0, cur["usage_usec"] - old["usage_usec"]) / (seconds * 1e6) * 100, 1)
                if cur["throttled_usec"] is not None and old["throttled_usec"] is not None:
                    throttled = round(max(0, cur["throttled_usec"] - old["throttled_usec"]) / (seconds * 1e6) * 100, 1)
                if cur["io"] and old["io"]:
                    read_bps = round(max(0, cur["io"][0] - old["io"][0]) / seconds)
                    write_bps = round(max(0, cur["io"][1] - old["io"][1]) / seconds)
            ref = cur["ref"]
            server_id = None
            if cur["kind"] == "kvm":
                server_id = int(ref)
            elif cur["kind"] == "lxc" and (m := _VIRTHUB_ID.search(ref)):
                server_id = int(m.group(1))
            mem_max = cur["memory_max"]
            out.append({
                "kind": cur["kind"],
                "server_id": server_id,
                "app_uuid": apps.get(ref) if cur["kind"] == "app" else None,
                "ref": ref if cur["kind"] != "app" else ref[:12],
                "cpu_pct": cpu_pct,
                "cpu_host_pct": round(cpu_pct / cores, 1) if cpu_pct is not None else None,
                "throttled_pct": throttled,
                "cpu_wait_pct": (cur["pressure"] or {}).get("some"),
                "memory": cur["memory"],
                "memory_limit": int(mem_max) if mem_max.isdigit() and int(mem_max) < 1 << 60 else None,
                "pids": cur["pids"],
                "read_bps": read_bps,
                "write_bps": write_bps,
            })
        # Aplikacje bez naszego kontenera (np. inne kontenery Dockera) pomijamy.
        out = [s for s in out if s["kind"] != "app" or s["app_uuid"]]
        return sorted(out, key=lambda s: -(s["cpu_pct"] or 0))

    def _processes(self, prev: _Snapshot, now: _Snapshot, seconds: float, apps: dict[str, str], limit: int = 25) -> list[dict[str, Any]]:
        rows = []
        for pid, cur in now.procs.items():
            old = prev.procs.get(pid)
            cpu = round(max(0, cur["ticks"] - old["ticks"]) / self._ticks / seconds * 100, 1) if old else 0.0
            owner = None
            for part in reversed(cur["cgroup"].split("/")):
                if kind := classify(part):
                    owner = {"kind": kind[0], "ref": kind[1]}
                    if kind[0] == "app":
                        owner["app_uuid"] = apps.get(kind[1])
                        owner["ref"] = kind[1][:12]
                    elif kind[0] == "kvm":
                        owner["server_id"] = int(kind[1])
                    elif m := _VIRTHUB_ID.search(kind[1]):
                        owner["server_id"] = int(m.group(1))
                    break
            rows.append({
                "pid": pid,
                "name": cur["comm"],
                "cpu_pct": cpu,
                "rss": cur["rss"],
                "threads": cur["threads"],
                "owner": owner,
            })
        rows.sort(key=lambda r: (-r["cpu_pct"], -r["rss"]))
        return rows[:limit]

    def _resolve_apps(self) -> dict[str, str]:
        if self.app_resolver is None:
            return {}
        try:
            return self.app_resolver()
        except Exception:  # Docker niedostępny — usługi aplikacji bez nazw
            return {}
