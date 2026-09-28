"""Ochrona aplikacji przed nadużyciami: PteroVM/proot, koparki, zdalne powłoki."""

from __future__ import annotations

import json
import os
import stat

import pytest

from agent.app_guard import (
    BLOCK, WARN, AppGuard, Finding, blocking, classify_process, scan_files,
)
from agent.apps import SECCOMP_PROFILE


@pytest.mark.parametrize("argv,expected", [
    ("/home/container/proot -r /home/container/ubuntu /bin/bash", ("vm", BLOCK)),
    ("./xmrig -o pool.example:3333", ("miner", BLOCK)),
    ("node miner.js stratum+tcp://pool.example:3333", ("miner", BLOCK)),
    ("qemu-system-x86_64 -m 2048 -hda disk.qcow2", ("vm", BLOCK)),
    ("/usr/sbin/sshd -D -p 25565", ("remote-shell", BLOCK)),
    ("sh -c ./tmate -F", ("remote-shell", BLOCK)),
    ("bash /home/container/PteroVM/start.sh", ("vm", BLOCK)),
    ("./ngrok http 8080", ("tunnel", WARN)),
    ("java -Xms128M -Xmx2048M -jar server.jar nogui", None),
    ("node /home/container/index.js", None),
    ("python3 bot.py --proot-free-name", None),
])
def test_klasyfikacja_procesow(argv, expected):
    assert classify_process(argv) == expected


def _exe(path, content=b"#!/bin/sh\n"):
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(content)
    path.chmod(0o755)


def test_skan_plikow_znajduje_proot_rootfs_i_koparki(tmp_path):
    root = tmp_path / "app"
    _exe(root / "proot")
    _exe(root / "tools" / "bin" / "xmrig")
    for d in ("etc", "usr/bin", "bin"):
        (root / "ubuntu" / d).mkdir(parents=True)
    (root / "ubuntu" / "etc" / "os-release").write_text('ID=ubuntu\n')
    # nieszkodliwe: plik bez prawa wykonania i bez nagłówka ELF, zwykły serwer
    (root / "xmrig").write_text("notatki")
    _exe(root / "server.jar")
    # dowiązanie do systemu hosta nie jest odwiedzane
    os.symlink("/usr", root / "host-usr")

    found = {(f.category, f.detail) for f in scan_files(root)}
    assert ("vm", "/proot") in found
    assert ("miner", "/tools/bin/xmrig") in found
    assert ("vm", "system Linux w /ubuntu") in found
    assert not any(d == "/xmrig" for _, d in found)
    assert all(f.level == BLOCK for f in scan_files(root))


def test_elf_bez_prawa_wykonania_tez_jest_wykrywany(tmp_path):
    (tmp_path / "proot").write_bytes(b"\x7fELF\x02\x01\x01" + b"\0" * 32)
    os.chmod(tmp_path / "proot", stat.S_IRUSR | stat.S_IWUSR)
    assert [f.detail for f in scan_files(tmp_path)] == ["/proot"]


class _Manager:
    def __init__(self, root, exempt=False):
        self.root = root
        self.exempt = exempt

    def data_dir(self, uuid):
        return self.root

    def load_spec(self, uuid):
        return type("Spec", (), {"guard_exempt": self.exempt})()


def test_start_zablokowany_i_zgloszony(tmp_path):
    _exe(tmp_path / "proot")
    reports = []
    guard = AppGuard(_Manager(tmp_path), lambda u, f, a: reports.append((u, a, [x.detail for x in f])))

    found = guard.check_before_start("abc")
    assert [f.detail for f in found] == ["/proot"]
    assert reports == [("abc", "blocked", ["/proot"])]

    # tryb „report” i zwolnienie z ochrony nie blokują startu
    assert AppGuard(_Manager(tmp_path), lambda *a: None, mode="report").check_before_start("abc") == []
    assert AppGuard(_Manager(tmp_path, exempt=True), lambda *a: None).check_before_start("abc") == []


def test_proces_zabija_kontener_a_tunel_tylko_zglasza():
    class Container:
        killed = False

        def kill(self):
            self.killed = True

    reports = []
    guard = AppGuard(_Manager(None), lambda u, f, a: reports.append(a))

    tunnel = Container()
    assert guard.handle("a", [Finding("tunnel", WARN, "process", "ngrok http 80")], tunnel) == "reported"
    assert not tunnel.killed
    # to samo zgłoszenie „warn” nie wraca co minutę
    guard.handle("a", [Finding("tunnel", WARN, "process", "ngrok http 80")], tunnel)
    assert reports == ["reported"]

    miner = Container()
    assert guard.handle("a", [Finding("miner", BLOCK, "process", "xmrig")], miner) == "killed"
    assert miner.killed
    assert blocking([Finding("miner", BLOCK, "process", "x"), Finding("tunnel", WARN, "process", "y")])[0].category == "miner"


def test_profil_seccomp_blokuje_ptrace_i_ucieczki():
    profile = json.loads(SECCOMP_PROFILE.read_text())
    denied = {n for rule in profile["syscalls"] if rule["action"] == "SCMP_ACT_ERRNO" and "args" not in rule
              for n in rule["names"]}
    assert {"ptrace", "process_vm_readv", "unshare", "setns", "mount", "bpf", "keyctl",
            "init_module", "io_uring_setup", "userfaultfd", "clone3"} <= denied
    # clone blokowany tylko z flagami nowych przestrzeni nazw — zwykłe wątki działają
    ns_flags = {rule["args"][0]["value"] for rule in profile["syscalls"] if rule["names"] == ["clone"]}
    assert 0x10000000 in ns_flags and 0x20000 in ns_flags and len(ns_flags) == 7
    # 32-bitowe programy (SteamCMD) muszą przejść
    assert "SCMP_ARCH_X86" in profile["archMap"][0]["subArchitectures"]


# --- na prawdziwym Dockerze ------------------------------------------------------

def test_kontener_aplikacji_nie_ma_ptrace_ani_uprawnien(manager):
    from test_apps import _spec, _wait_for

    spec = _spec(startup="sh probe.sh")
    manager.install(spec)
    manager.files(spec.uuid).write("probe.sh", (
        "grep -E '^(CapEff|Seccomp|NoNewPrivs)' /proc/self/status\n"
        "echo dziala\n"
        "exec sleep 60\n"
    ).encode())
    manager.power(spec.uuid, "start")
    text = _wait_for(lambda: "dziala" in (t := "\n".join(manager.logs(spec.uuid)["lines"])) and t, timeout=40)
    assert "CapEff:\t0000000000000000" in text
    assert "NoNewPrivs:\t1" in text
    assert "Seccomp:\t2" in text

    manager.delete(spec.uuid)


PTRACE_PROBE = (
    "import ctypes; libc = ctypes.CDLL(None, use_errno=True); "
    "rc = libc.ptrace(0, 0, None, None); print('ptrace', rc, ctypes.get_errno())"
)


def test_ptrace_zablokowany_proot_nie_ruszy(manager):
    """PTRACE_TRACEME (pierwsze, co robi proot) — EPERM z naszym profilem,
    a bez niego (domyślny Docker) działa: dowód, że to profil blokuje."""
    from agent.apps import CAP_DROP, security_options

    image = "ghcr.io/parkervcp/yolks:python_3.12"
    try:
        manager.client.images.get(image)
    except Exception:
        pytest.skip(f"brak obrazu {image}")

    def probe(options):
        out = manager.client.containers.run(
            image, entrypoint=["python3", "-c", PTRACE_PROBE], user=f"{manager.uid}:{manager.gid}",
            cap_drop=CAP_DROP, security_opt=options, remove=True, network_mode="none",
        )
        return out.decode().strip()

    assert probe(security_options(manager.settings)) == "ptrace -1 1"
    assert probe(["no-new-privileges"]) == "ptrace 0 0"


def test_straznik_zabija_koparke_i_blokuje_ponowny_start(manager):
    from test_apps import _spec, _wait_for

    reports = []
    manager.guard = AppGuard(manager, lambda u, f, a: reports.append((u, a, [x.category for x in f])))
    # „Koparka”: kopia node pod nazwą xmrig, uruchomiona z katalogu aplikacji
    # (sleep w tym obrazie to aplet busyboxa — pod inną nazwą nie ruszy).
    spec = _spec(startup="sh miner.sh")
    manager.install(spec)
    manager.files(spec.uuid).write("miner.sh", (
        'cp "$(command -v node)" /home/container/xmrig\n'
        "echo start\n"
        "exec /home/container/xmrig -e 'setTimeout(() => {}, 300000)'\n"
    ).encode())
    manager.power(spec.uuid, "start")
    assert _wait_for(lambda: "start" in "\n".join(manager.logs(spec.uuid)["lines"]), timeout=40)

    manager.guard.scan_all()
    assert _wait_for(lambda: manager.status(spec.uuid)["state"] == "offline", timeout=15)
    assert reports and reports[0][0] == spec.uuid and reports[0][1] == "killed" and "miner" in reports[0][2]

    from agent.apps import AppError

    with pytest.raises(AppError, match="Start zablokowany"):
        manager.power(spec.uuid, "start")
    assert reports[-1][1] == "blocked"
    manager.guard = None
    manager.delete(spec.uuid)


from test_apps import manager  # noqa: E402,F401 — fixture z Dockerem
