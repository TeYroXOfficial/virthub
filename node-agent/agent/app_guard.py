"""Ochrona węzła przed nadużyciami w aplikacjach (PteroVM i podobne).

Aplikacje to serwery gier i boty, a nie VPS-y. Popularne „eggi VPS”
(PteroVM, Pterodactyl-VPS-Egg itp.) uruchamiają w kontenerze cały system
przez proot albo QEMU bez KVM, żeby dać klientowi powłokę root, kopać
kryptowaluty albo wystawić tunel/SSH. Obrona ma dwie warstwy:

1. zapobieganie (apps.py) — seccomp bez ptrace (na nim stoi proot),
   brak wszystkich uprawnień, no-new-privileges, użytkownik bez roota;
2. wykrywanie (ten moduł) — co minutę lista procesów każdej działającej
   aplikacji, a co kilka minut i przed każdym startem przegląd jej plików:
   binarki proot/QEMU/koparek, rozpakowany system Linux (rootfs), serwery
   SSH i zdalne powłoki.

Znalezisko z kategorii „block” zabija kontener (proces) albo blokuje start
(plik) i trafia do panelu, który zawiesza aplikację. Tunele („warn”) są
tylko zgłaszane — bywają używane legalnie, ocenia je personel.
"""

from __future__ import annotations

import logging
import os
import re
import stat
import threading
import time
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any, Callable

log = logging.getLogger("virthub.guard")

BLOCK = "block"
WARN = "warn"

# nazwa pliku wykonywalnego / procesu → (kategoria, poziom)
_NAMES: dict[str, tuple[str, str]] = {}
for _name in ("proot", "proot-static", "udocker", "fakechroot", "pterovm", "box64-vps"):
    _NAMES[_name] = ("vm", BLOCK)
for _name in ("xmrig", "xmr-stak", "xmr-stak-rx", "cpuminer", "cpuminer-opt", "minerd", "t-rex", "nbminer",
              "lolminer", "gminer", "srbminer", "srbminer-multi", "phoenixminer", "ethminer", "nanominer",
              "cgminer", "bfgminer", "ccminer", "xmrig-notls", "cryptonight", "hellminer", "rplant"):
    _NAMES[_name] = ("miner", BLOCK)
for _name in ("sshd", "dropbear", "tmate", "ttyd", "gotty", "shellinaboxd", "sshx", "upterm", "wetty"):
    _NAMES[_name] = ("remote-shell", BLOCK)
for _name in ("ngrok", "cloudflared", "frpc", "chisel", "bore", "zrok", "pinggy", "localxpose", "loclx"):
    _NAMES[_name] = ("tunnel", WARN)

# QEMU (emulacja całego systemu bez KVM) — różne warianty nazw.
_QEMU = re.compile(r"^qemu-(system-[a-z0-9_]+|x86_64|i386|aarch64|arm|riscv64)(-static)?$")

# Fragmenty wiersza poleceń zdradzające kopanie albo system w proot.
_CMDLINE: list[tuple[re.Pattern[str], str, str]] = [
    (re.compile(r"stratum\+(tcp|ssl|tls)://", re.I), "miner", BLOCK),
    (re.compile(r"--donate-level|--coin[= ]|--algo[= ](rx|randomx|cn|kawpow|ethash)", re.I), "miner", BLOCK),
    (re.compile(r"\bproot\b.*(-r|--rootfs)\b", re.I), "vm", BLOCK),
    (re.compile(r"pterovm|pterodactyl-vps", re.I), "vm", BLOCK),
]

SCAN_MAX_ENTRIES = 30_000
SCAN_MAX_DEPTH = 5
FILE_SCAN_EVERY = 600
REPORT_AGAIN_AFTER = 3600


@dataclass(frozen=True)
class Finding:
    category: str   # vm | miner | remote-shell | tunnel
    level: str      # block | warn
    source: str     # process | file
    detail: str

    def label(self) -> str:
        return f"{self.category}: {self.detail}"


def classify_name(name: str) -> tuple[str, str] | None:
    base = os.path.basename(name).lower()
    if base in _NAMES:
        return _NAMES[base]
    if _QEMU.match(base):
        return ("vm", BLOCK)
    return None


def classify_process(argv: str) -> tuple[str, str] | None:
    parts = argv.split()
    if not parts:
        return None
    hit = classify_name(parts[0])
    if hit:
        return hit
    # Interpretery uruchamiające binarkę (sh -c ./xmrig, ld-linux … proot).
    for part in parts[1:4]:
        if not part.startswith("-"):
            hit = classify_name(part)
            if hit and hit[1] == BLOCK:
                return hit
    for pattern, category, level in _CMDLINE:
        if pattern.search(argv):
            return (category, level)
    return None


def scan_processes(container: Any) -> list[Finding]:
    """Procesy kontenera widziane z hosta (docker top) — nie da się ich ukryć
    w kontenerze zmianą nazwy katalogu czy aliasem."""
    try:
        top = container.top(ps_args="-eo pid,args")
    except Exception as exc:
        log.debug("docker top %s nie powiódł się: %s", getattr(container, "name", "?"), exc)
        return []
    titles = [t.upper() for t in top.get("Titles") or []]
    col = titles.index("COMMAND") if "COMMAND" in titles else len(titles) - 1
    findings: dict[str, Finding] = {}
    for row in top.get("Processes") or []:
        argv = " ".join(row[col:]) if col < len(row) else ""
        hit = classify_process(argv)
        if hit:
            detail = argv[:160]
            findings.setdefault(hit[0] + detail, Finding(hit[0], hit[1], "process", detail))
    return list(findings.values())


def scan_files(root: Path) -> list[Finding]:
    """Przegląd katalogu aplikacji bez podążania za dowiązaniami (lstat)."""
    findings: list[Finding] = []
    seen = 0
    stack: list[tuple[Path, int]] = [(root, 0)]
    while stack and seen < SCAN_MAX_ENTRIES:
        directory, depth = stack.pop()
        try:
            entries = list(os.scandir(directory))
        except OSError:
            continue
        names = {e.name for e in entries}
        rel_dir = directory.relative_to(root).as_posix()
        # Rozpakowany system Linux: /etc/os-release, /usr/bin i /bin razem.
        if {"etc", "usr", "bin"} <= names and (directory / "etc" / "os-release").exists():
            findings.append(Finding("vm", BLOCK, "file", f"system Linux w /{rel_dir}".rstrip("/") if rel_dir != "." else "system Linux w katalogu głównym"))
        for entry in entries:
            seen += 1
            try:
                st = entry.stat(follow_symlinks=False)
            except OSError:
                continue
            if stat.S_ISDIR(st.st_mode):
                if depth < SCAN_MAX_DEPTH:
                    stack.append((Path(entry.path), depth + 1))
                continue
            if not stat.S_ISREG(st.st_mode):
                continue
            hit = classify_name(entry.name)
            if hit and (st.st_mode & 0o111 or _is_elf(entry.path)):
                rel = Path(entry.path).relative_to(root).as_posix()
                findings.append(Finding(hit[0], hit[1], "file", f"/{rel}"))
    return findings


def _is_elf(path: str) -> bool:
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    except OSError:
        return False
    try:
        return os.read(fd, 4) == b"\x7fELF"
    finally:
        os.close(fd)


def blocking(findings: list[Finding]) -> list[Finding]:
    return [f for f in findings if f.level == BLOCK]


def describe(findings: list[Finding]) -> str:
    return "; ".join(f.label() for f in findings[:5])


class AppGuard:
    """Wątek w tle: skanuje działające aplikacje i reaguje na znaleziska."""

    def __init__(self, manager: Any, report: Callable[[str, list[Finding], str], None],
                 mode: str = "kill", interval: int = 60):
        self.apps = manager
        self.report = report
        self.mode = mode          # kill | report | off
        self.interval = interval
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None
        self._last_files: dict[str, float] = {}
        self._reported: dict[tuple[str, str], float] = {}

    def start(self) -> None:
        if self.mode == "off" or self._thread is not None:
            return
        self._thread = threading.Thread(target=self._run, name="virthub-app-guard", daemon=True)
        self._thread.start()
        log.info("Ochrona aplikacji włączona (tryb %s, co %s s)", self.mode, self.interval)

    def stop(self) -> None:
        self._stop.set()

    def _run(self) -> None:
        while not self._stop.wait(self.interval):
            try:
                self.scan_all()
            except Exception:
                log.exception("Skanowanie aplikacji nie powiodło się")

    def scan_all(self) -> None:
        from .apps import CONTAINER_PREFIX, LABEL

        try:
            containers = self.apps.client.containers.list(filters={"label": LABEL, "status": "running"})
        except Exception as exc:
            log.debug("Docker niedostępny dla ochrony aplikacji: %s", exc)
            return
        now = time.time()
        for container in containers:
            if container.labels.get("virthub.role") == "installer" or not container.name.startswith(CONTAINER_PREFIX):
                continue
            uuid = container.name[len(CONTAINER_PREFIX):]
            if self.exempt(uuid):
                continue
            findings = scan_processes(container)
            if now - self._last_files.get(uuid, 0) >= FILE_SCAN_EVERY:
                self._last_files[uuid] = now
                findings += scan_files(self.apps.data_dir(uuid))
            if findings:
                self.handle(uuid, findings, container)

    def exempt(self, uuid: str) -> bool:
        try:
            return bool(self.apps.load_spec(uuid).guard_exempt)
        except Exception:
            return False

    def handle(self, uuid: str, findings: list[Finding], container: Any | None = None) -> str:
        """Reakcja na znaleziska; zwraca podjętą akcję (killed | reported)."""
        action = "reported"
        if blocking(findings) and self.mode == "kill" and container is not None:
            try:
                container.kill()
                action = "killed"
            except Exception as exc:
                log.warning("Nie udało się zabić aplikacji %s: %s", uuid, exc)
        log.warning("Aplikacja %s: wykryto %s (%s)", uuid, describe(findings), action)
        self._send(uuid, findings, action)
        return action

    def check_before_start(self, uuid: str) -> list[Finding]:
        """Pliki przed startem — zwraca znaleziska blokujące start (i je zgłasza)."""
        if self.mode != "kill" or self.exempt(uuid):
            return []
        found = blocking(scan_files(self.apps.data_dir(uuid)))
        if found:
            self._send(uuid, found, "blocked")
        return found

    def _send(self, uuid: str, findings: list[Finding], action: str) -> None:
        key = (uuid, "|".join(sorted(f.label() for f in findings)))
        now = time.time()
        if action == "reported" and now - self._reported.get(key, 0) < REPORT_AGAIN_AFTER:
            return
        self._reported[key] = now
        try:
            self.report(uuid, findings, action)
        except Exception as exc:
            log.warning("Nie udało się zgłosić nadużycia aplikacji %s do panelu: %s", uuid, exc)


def findings_payload(findings: list[Finding]) -> list[dict[str, str]]:
    return [asdict(f) for f in findings[:20]]
