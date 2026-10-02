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


# --- polityka zagnieżdżania ---------------------------------------------------------

def _security(tmp_path, changelog=None, arch="x86_64", unit=True):
    host(tmp_path, changelog=changelog, arch=arch)
    if unit:
        _w(tmp_path / "unit.path", "")
    return HostSecurity(kvm_host=True, root=tmp_path,
                        uname=types.SimpleNamespace(release="6.1.0-30-amd64", machine=arch),
                        policy_file=tmp_path / "state/nested-policy", request_file=tmp_path / "state/request",
                        unit=tmp_path / "unit.path")


def test_jadro_z_poprawkami_pozwala_na_zagniezdzanie(tmp_path):
    assert _security(tmp_path / "a").kernel_patched() is False
    assert _security(tmp_path / "b", changelog="* KVM (CVE-2026-53359)").kernel_patched() is False, "potrzebne obie poprawki"
    assert _security(tmp_path / "c", changelog="CVE-2026-53359 CVE-2026-64561").kernel_patched() is True
    assert _security(tmp_path / "d", arch="aarch64").kernel_patched() is None


def test_polityka_z_panelu_trafia_do_pliku_i_zlecenia(tmp_path):
    sec = _security(tmp_path)
    assert sec.nested_policy() == "auto"
    assert sec.set_nested_policy("allow") == {"policy": "allow", "requested": True}
    assert (tmp_path / "state/nested-policy").read_text().strip() == "allow"
    assert (tmp_path / "state/request").exists()
    assert sec.nested_policy() == "allow"
    assert sec.report(fresh=True)["nested_policy"] == "allow"

    import pytest
    with pytest.raises(ValueError):
        sec.set_nested_policy("rm -rf /")


def test_brak_uslugi_roota(tmp_path):
    import pytest

    from agent.host_security import HardenerMissing

    with pytest.raises(HardenerMissing):
        _security(tmp_path, unit=False).set_nested_policy("allow")


def test_zagniezdzanie_na_zalatanym_jadrze_nie_jest_alarmem(tmp_path):
    r = host(tmp_path, nested="Y", changelog="CVE-2026-53359 CVE-2026-64561")
    assert not any(f["title"].startswith("Zagnieżdżona") for f in r["findings"])
    assert r["overall"] == "ok"


def test_endpoint_polityki_bez_uslugi_roota(client):
    assert client.put("/system/nested", json={"policy": "allow"}).status_code == 409
    assert client.put("/system/nested", json={"policy": "zle"}).status_code == 422


def test_cpu_maszyn_podaza_za_hostem(settings, monkeypatch):
    import sys
    import xml.etree.ElementTree as ET

    monkeypatch.setitem(sys.modules, "libvirt", types.SimpleNamespace(VIR_DOMAIN_XML_INACTIVE=2, libvirtError=Exception))
    from agent.driver import LibvirtDriver

    class Dom:
        xml = "<domain><name>virthub-1</name><cpu mode='host-passthrough' check='none'/></domain>"

        def XMLDesc(self, flags=0):
            return self.xml

        def name(self):
            return "virthub-1"

    dom = Dom()

    class Conn:
        def isAlive(self):
            return True

        def listAllDomains(self):
            return [dom]

        def defineXML(self, xml):
            dom.xml = xml

    drv = LibvirtDriver.__new__(LibvirtDriver)
    drv.settings = settings
    drv._conn = Conn()

    state = {"nested": False}
    monkeypatch.setattr(drv, "nested_enabled", lambda: state["nested"])

    assert drv.sync_guest_cpu() == 1
    disabled = {f.get("name") for f in ET.fromstring(dom.xml).find("cpu").findall("feature")}
    assert disabled == {"vmx", "svm"}
    assert drv.sync_guest_cpu() == 0, "bez zmiany stanu hosta nic nie robimy"

    state["nested"] = True
    assert drv.sync_guest_cpu() == 1
    assert ET.fromstring(dom.xml).find("cpu").findall("feature") == []
