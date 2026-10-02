"""Audyt ochrony hosta przed ucieczkami z maszyn (Januscape, Zapscape, ITScape)."""

from __future__ import annotations

import gzip
import types
from pathlib import Path

from agent.domain_xml import cpu_xml
from agent.host_security import HostSecurity


def _w(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text)


def host(tmp_path, *, vendor="GenuineIntel", nested="N", tdp="Y", arch="x86_64", release="6.1.0-30-amd64",
         changelog=None, boot=(), reboot_flag=False, os_release='ID=debian\nVERSION_ID="12"\nPRETTY_NAME="Debian 12"',
         auto=True, kvm_host=True, vulns=None):
    _w(tmp_path / "proc/cpuinfo", f"vendor_id\t: {vendor}\n")
    module, tdp_name = ("kvm_intel", "ept") if vendor == "GenuineIntel" else ("kvm_amd", "npt")
    _w(tmp_path / f"sys/module/{module}/parameters/nested", nested + "\n")
    _w(tmp_path / f"sys/module/{module}/parameters/{tdp_name}", tdp + "\n")
    _w(tmp_path / "etc/os-release", os_release)
    if auto:
        _w(tmp_path / "etc/apt/apt.conf.d/20auto-upgrades", 'APT::Periodic::Unattended-Upgrade "1";\n')
    else:
        (tmp_path / "etc/apt").mkdir(parents=True, exist_ok=True)
    for k in boot:
        _w(tmp_path / f"boot/vmlinuz-{k}", "")
    if reboot_flag:
        _w(tmp_path / "run/reboot-required", "")
    if changelog is not None:
        path = tmp_path / f"usr/share/doc/linux-image-{release}/changelog.Debian.gz"
        path.parent.mkdir(parents=True, exist_ok=True)
        with gzip.open(path, "wt") as fh:
            fh.write(changelog)
    for name, state in (vulns or {"meltdown": "Not affected"}).items():
        _w(tmp_path / f"sys/devices/system/cpu/vulnerabilities/{name}", state)
    uname = types.SimpleNamespace(release=release, machine=arch)
    return HostSecurity(kvm_host=kvm_host, root=tmp_path, uname=uname).report()


def status(report, cve):
    return next(i["status"] for i in report["issues"] if i["id"] == cve)


def test_intel_bez_nested_ma_obejscia(tmp_path):
    r = host(tmp_path, boot=["6.1.0-30-amd64"])
    assert status(r, "CVE-2026-53359") == "mitigated"
    assert status(r, "CVE-2026-64561") == "mitigated"
    assert status(r, "CVE-2026-46316") == "not_affected"
    assert r["overall"] == "ok"


def test_nested_wlaczony_to_podatnosc_krytyczna(tmp_path):
    r = host(tmp_path, nested="Y")
    assert status(r, "CVE-2026-53359") == "vulnerable"
    assert r["overall"] == "critical"
    assert any(f["title"].startswith("Zagnieżdżona") for f in r["findings"])


def test_amd_bez_nested_tylko_czesciowo_chroniony(tmp_path):
    r = host(tmp_path, vendor="AuthenticAMD")
    assert status(r, "CVE-2026-53359") == "mitigated"
    assert status(r, "CVE-2026-64561") == "partial"
    assert r["overall"] == "warning"


def test_poprawka_w_changelogu_jadra(tmp_path):
    r = host(tmp_path, vendor="AuthenticAMD", nested="Y",
             changelog="linux (6.1.140-1) bookworm-security\n  * KVM: x86/mmu (CVE-2026-53359, CVE-2026-64561)\n")
    assert status(r, "CVE-2026-53359") == "fixed"
    assert status(r, "CVE-2026-64561") == "fixed"


def test_wylaczone_ept_to_shadow_mmu_dla_kazdej_maszyny(tmp_path):
    r = host(tmp_path, tdp="N")
    assert status(r, "CVE-2026-53359") == "vulnerable"
    assert any("EPT/NPT" in f["title"] for f in r["findings"])


def test_restart_i_debian_11_i_brak_aktualizacji(tmp_path):
    r = host(tmp_path, boot=["6.1.0-30-amd64", "6.1.0-31-amd64"], auto=False,
             os_release='ID=debian\nVERSION_ID="11"\nPRETTY_NAME="Debian 11"',
             vulns={"mds": "Vulnerable: Clear CPU buffers attempted, no microcode"})
    titles = [f["title"] for f in r["findings"]]
    assert r["kernel"]["required"] is True and r["kernel"]["newest_kernel"] == "6.1.0-31-amd64"
    assert "Wymagany restart węzła" in titles
    assert "Debian 11 nie dostaje już poprawek jądra" in titles
    assert "Automatyczne poprawki bezpieczeństwa wyłączone" in titles
    assert "Podatność procesora: mds" in titles


def test_arm64_itscape_i_wezel_kontenerow(tmp_path):
    r = host(tmp_path, arch="aarch64")
    assert status(r, "CVE-2026-46316") == "vulnerable"
    assert status(r, "CVE-2026-53359") == "not_affected"

    r = host(tmp_path / "lxc", nested="Y", kvm_host=False)
    assert status(r, "CVE-2026-53359") == "not_affected"


def test_gosc_bez_vmx_i_svm():
    xml = cpu_xml()
    assert "name='vmx'" in xml and "name='svm'" in xml and "policy='disable'" in xml
    assert "feature" not in cpu_xml(allow_nested=True)


def test_raport_w_health(client):
    body = client.get("/health").json()
    assert "issues" in body["host_security"]
    assert client.get("/system/security").status_code == 200
