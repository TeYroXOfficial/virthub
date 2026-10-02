"""Audyt bezpieczeństwa hosta: znane ucieczki z maszyn KVM i stan ochrony.

Bez roota i bez dodatkowych narzędzi — czytamy /proc, /sys, /etc i changelog
pakietu działającego jądra. Wynik trafia do panelu (Infrastruktura →
Bezpieczeństwo) i do raportu zdrowia węzła.

Stan poprawki ustalamy tak:
* `fixed` — numer CVE jest w changelogu pakietu DZIAŁAJĄCEGO jądra (Debian i
  Ubuntu wpisują tam numery CVE łatanych błędów),
* `mitigated` — poprawki nie widać, ale konfiguracja zamyka znaną drogę ataku
  (np. zagnieżdżona wirtualizacja wyłączona),
* `partial` — konfiguracja tylko utrudnia atak (np. Zapscape na AMD),
* `vulnerable` — trzeba zaktualizować jądro i zrestartować węzeł,
* `not_affected` — inna architektura albo węzeł nie uruchamia maszyn KVM.
"""

from __future__ import annotations

import gzip
import os
import re
import threading
import time
from pathlib import Path
from typing import Any

CACHE_SECONDS = 600

# Polityka zagnieżdżonej wirtualizacji ustawiana z panelu (plik zapisuje agent,
# czyta go harden-host.sh uruchamiany jako root):
#   auto  — włączona tylko na jądrze z poprawkami znanych ucieczek z maszyn,
#   allow — włączona mimo braku poprawek (świadoma decyzja administratora).
NESTED_POLICIES = ("auto", "allow")
POLICY_FILE = Path("/var/lib/virthub/security/nested-policy")
REQUEST_FILE = Path("/var/lib/virthub/security/request")
HARDEN_UNIT = Path("/etc/systemd/system/virthub-harden.path")


# Znane ucieczki z maszyn wirtualnych do hosta. `rule` wybiera sposób oceny.
KNOWN_ISSUES: list[dict[str, Any]] = [
    {
        "id": "CVE-2026-53359",
        "name": "Januscape",
        "arch": ("x86_64",),
        "rule": "nested",
        "summary": "Use-after-free w shadow MMU KVM (x86, Intel i AMD) — gość z zagnieżdżoną wirtualizacją może wywrócić host albo z niego uciec.",
    },
    {
        "id": "CVE-2026-64561",
        "name": "Zapscape",
        "arch": ("x86_64",),
        "rule": "zapscape",
        "summary": "Use-after-free przy zwalnianiu stron shadow MMU KVM — ucieczka z maszyny do hosta z uprawnieniami roota. Na AMD do ataku nie trzeba zagnieżdżania.",
    },
    {
        "id": "CVE-2026-46316",
        "name": "ITScape",
        "arch": ("aarch64",),
        "rule": "kernel",
        "summary": "Wyścig w emulacji vGIC-ITS KVM na arm64 — ucieczka z maszyny do hosta.",
    },
]


def _read(path: Path) -> str | None:
    try:
        return path.read_text().strip()
    except OSError:
        return None


class HostSecurity:
    def __init__(self, kvm_host: bool, allow_nested: bool = False,
                 root: Path = Path("/"), uname: Any = None, clock=time.monotonic,
                 policy_file: Path = POLICY_FILE, request_file: Path = REQUEST_FILE,
                 unit: Path = HARDEN_UNIT) -> None:
        self.kvm_host = kvm_host
        self.allow_nested = allow_nested
        self.policy_file = policy_file
        self.request_file = request_file
        self.unit = unit
        self.root = root
        self.uname = uname or os.uname()
        self.clock = clock
        self._cache: tuple[float, dict[str, Any]] | None = None
        self._lock = threading.Lock()

    def report(self, fresh: bool = False) -> dict[str, Any]:
        with self._lock:
            if not fresh and self._cache and self.clock() - self._cache[0] < CACHE_SECONDS:
                return self._cache[1]
            result = self._build()
            self._cache = (self.clock(), result)
            return result

    # --- polityka zagnieżdżania -------------------------------------------------

    def nested_policy(self) -> str:
        if self.allow_nested:
            return "allow"
        value = _read(self.policy_file) or "auto"
        return value if value in NESTED_POLICIES else "auto"

    def set_nested_policy(self, policy: str) -> dict[str, Any]:
        """Zapisuje politykę i zleca jej zastosowanie usłudze roota (virthub-harden)."""
        if policy not in NESTED_POLICIES:
            raise ValueError(f"Nieznana polityka: {policy}")
        if not self.unit.exists():
            raise HardenerMissing(
                "Ten węzeł nie ma jeszcze usługi zabezpieczeń — zaktualizuj go raz z panelu albo ręcznie."
            )
        self.policy_file.parent.mkdir(parents=True, exist_ok=True)
        self.policy_file.write_text(policy + "\n", encoding="utf-8")
        self.request_file.write_text(str(int(time.time())), encoding="utf-8")
        with self._lock:
            self._cache = None
        return {"policy": policy, "requested": True}

    def kernel_patched(self) -> bool | None:
        """Czy działające jądro ma poprawki wszystkich znanych ucieczek związanych
        z zagnieżdżaniem na tej architekturze. None — nie dotyczy (inna architektura)."""
        relevant = [i for i in KNOWN_ISSUES if self.uname.machine in i["arch"] and i["rule"] in ("nested", "zapscape")]
        if not relevant:
            return None
        changelog = self._kernel_changelog()
        return all(i["id"] in changelog for i in relevant)

    # --- składniki ------------------------------------------------------------

    def _p(self, path: str) -> Path:
        return self.root / path.lstrip("/")

    def _cpu_vendor(self) -> str | None:
        text = _read(self._p("/proc/cpuinfo")) or ""
        m = re.search(r"^vendor_id\s*:\s*(\S+)", text, re.M)
        if m:
            return {"GenuineIntel": "intel", "AuthenticAMD": "amd", "HygonGenuine": "amd"}.get(m.group(1), m.group(1))
        return None

    def _kvm(self) -> dict[str, Any]:
        out: dict[str, Any] = {"module": None, "nested": None, "tdp": None}
        for module, tdp in (("kvm_intel", "ept"), ("kvm_amd", "npt")):
            base = self._p(f"/sys/module/{module}/parameters")
            if base.is_dir():
                yes = lambda v: v in ("1", "Y", "y")  # noqa: E731
                out["module"] = module
                out["nested"] = yes(_read(base / "nested") or "")
                tdp_value = _read(base / tdp)
                out["tdp"] = None if tdp_value is None else yes(tdp_value)
        out["nested_configured_off"] = "nested=0" in (_read(self._p("/etc/modprobe.d/virthub-kvm.conf")) or "")
        return out

    def _os(self) -> dict[str, str]:
        info: dict[str, str] = {}
        for line in (_read(self._p("/etc/os-release")) or "").splitlines():
            key, _, value = line.partition("=")
            info[key] = value.strip('"')
        return info

    def _kernel_changelog(self) -> str:
        """Changelog pakietu działającego jądra (Debian: linux-image-*, Ubuntu: linux-modules-*)."""
        release = self.uname.release
        doc = self._p("/usr/share/doc")
        for pattern in (f"linux-image-{release}*", f"linux-modules-{release}*", f"linux-image-unsigned-{release}*"):
            for directory in sorted(doc.glob(pattern)):
                for name in ("changelog.Debian.gz", "changelog.gz"):
                    path = directory / name
                    try:
                        with gzip.open(path, "rt", errors="replace") as fh:
                            return fh.read(8_000_000)
                    except OSError:
                        continue
        return ""

    def _reboot(self) -> dict[str, Any]:
        running = self.uname.release
        kernels = [p.name.removeprefix("vmlinuz-") for p in self._p("/boot").glob("vmlinuz-*")]

        def key(v: str) -> list:
            return [int(x) if x.isdigit() else x for x in re.split(r"[.\-+~]", v)]

        newest = max(kernels, key=key) if kernels else None
        flag = self._p("/run/reboot-required").exists()
        return {
            "running_kernel": running,
            "newest_kernel": newest,
            "required": flag or (newest is not None and newest != running),
        }

    def _cpu_vulnerabilities(self) -> dict[str, str]:
        base = self._p("/sys/devices/system/cpu/vulnerabilities")
        try:
            return {p.name: _read(p) or "" for p in sorted(base.iterdir())}
        except OSError:
            return {}

    def _auto_updates(self) -> bool | None:
        conf = self._p("/etc/apt/apt.conf.d/20auto-upgrades")
        if not self._p("/etc/apt").is_dir():
            return None
        return 'Unattended-Upgrade "1"' in (_read(conf) or "")

    # --- ocena ----------------------------------------------------------------

    def _build(self) -> dict[str, Any]:
        arch = self.uname.machine
        vendor = self._cpu_vendor()
        kvm = self._kvm()
        changelog = self._kernel_changelog()
        os_info = self._os()
        reboot = self._reboot()
        nested_on = bool(kvm["nested"])

        issues = []
        for issue in KNOWN_ISSUES:
            status, detail = self._evaluate(issue, arch, vendor, kvm, changelog)
            if status == "fixed" and reboot["required"]:
                detail += " Zainstalowane nowsze jądro zadziała po restarcie."
            issues.append({k: issue[k] for k in ("id", "name", "summary")} | {"status": status, "detail": detail})

        findings: list[dict[str, str]] = []
        if self.kvm_host and nested_on and not self.kernel_patched():
            allowed = self.nested_policy() == "allow"
            findings.append({"severity": "critical",
                             "title": "Zagnieżdżona wirtualizacja włączona na jądrze bez poprawek",
                             "detail": "Maszyny mogą uruchamiać własne KVM — to główna droga ucieczek do hosta. "
                                       + ("Włączona świadomie przez administratora." if allowed
                                          else "Zaktualizuj węzeł — wyłączy ją; przy działających maszynach zadziała po restarcie.")})
        if self.kvm_host and kvm["tdp"] is False:
            findings.append({"severity": "critical", "title": "EPT/NPT wyłączone",
                             "detail": "KVM używa shadow MMU dla każdej maszyny — podatnego na Januscape i Zapscape nawet bez zagnieżdżania. Usuń ept=0/npt=0 z /etc/modprobe.d."})
        if reboot["required"]:
            findings.append({"severity": "warning", "title": "Wymagany restart węzła",
                             "detail": f"Działa jądro {reboot['running_kernel']}, zainstalowane jest {reboot['newest_kernel']} — poprawki bezpieczeństwa zaczną działać po restarcie."})
        if os_info.get("ID") == "debian" and os_info.get("VERSION_ID") == "11":
            findings.append({"severity": "warning", "title": "Debian 11 nie dostaje już poprawek jądra",
                             "detail": "Nowe luki w jądrze (np. ucieczki z maszyn) nie zostaną załatane. Zaplanuj przejście na Debian 12 lub 13."})
        auto = self._auto_updates()
        if auto is False:
            findings.append({"severity": "warning", "title": "Automatyczne poprawki bezpieczeństwa wyłączone",
                             "detail": "Zaktualizuj węzeł z panelu — włączy unattended-upgrades (bez automatycznego restartu)."})

        cpu_vulns = self._cpu_vulnerabilities()
        for name, state in cpu_vulns.items():
            if state.startswith("Vulnerable"):
                findings.append({"severity": "warning", "title": f"Podatność procesora: {name}",
                                 "detail": f"{state}. Pomaga aktualny mikrokod (pakiet intel-microcode / amd64-microcode) i jądro."})

        statuses = {i["status"] for i in issues} | {f["severity"] for f in findings}
        overall = "critical" if statuses & {"vulnerable", "critical"} else "warning" if statuses & {"partial", "warning"} else "ok"
        return {
            "checked_at": int(time.time()),
            "overall": overall,
            "arch": arch,
            "cpu_vendor": vendor,
            "kvm_host": self.kvm_host,
            "kvm": kvm,
            "nested_policy": self.nested_policy(),
            "kernel_patched": self.kernel_patched(),
            "hardener": self.unit.exists(),
            "os": f"{os_info.get('PRETTY_NAME', '?')}",
            "kernel": reboot,
            "auto_updates": auto,
            "smt": _read(self._p("/sys/devices/system/cpu/smt/control")),
            "issues": issues,
            "findings": findings,
            "cpu_vulnerabilities": cpu_vulns,
        }

    def _evaluate(self, issue: dict[str, Any], arch: str, vendor: str | None,
                  kvm: dict[str, Any], changelog: str) -> tuple[str, str]:
        if arch not in issue["arch"]:
            return "not_affected", f"Dotyczy tylko {', '.join(issue['arch'])} — ten węzeł to {arch}."
        if not self.kvm_host:
            return "not_affected", "Węzeł nie uruchamia maszyn KVM (kontenery nie mają dostępu do KVM)."
        if issue["id"] in changelog:
            return "fixed", "Poprawka jest w działającym jądrze (changelog pakietu)."

        if kvm["module"] is None and issue["rule"] in ("nested", "zapscape"):
            return "unknown", "Moduł KVM (kvm_intel/kvm_amd) nie jest załadowany — nie da się sprawdzić konfiguracji."
        nested_on = bool(kvm["nested"])
        shadow_everywhere = kvm["tdp"] is False
        if issue["rule"] == "nested":
            if shadow_everywhere:
                return "vulnerable", "EPT/NPT wyłączone — shadow MMU działa dla każdej maszyny. Zaktualizuj jądro i włącz EPT/NPT."
            if not nested_on:
                return "mitigated", "Zagnieżdżona wirtualizacja wyłączona — znana droga ataku zamknięta. Zaktualizuj jądro, gdy dystrybucja wyda poprawkę."
            return "vulnerable", "Zagnieżdżona wirtualizacja włączona, jądro bez poprawki."
        if issue["rule"] == "zapscape":
            if shadow_everywhere:
                return "vulnerable", "EPT/NPT wyłączone — shadow MMU działa dla każdej maszyny."
            if vendor == "intel" and not nested_on:
                return "mitigated", "Intel bez zagnieżdżonej wirtualizacji — atak wymaga zagnieżdżonego EPT. Zaktualizuj jądro, gdy dystrybucja wyda poprawkę."
            if vendor == "amd" and not nested_on:
                return "partial", "AMD: wyłączone zagnieżdżanie utrudnia atak, ale go nie zamyka — potrzebne jądro z poprawką i restart węzła."
            return "vulnerable", "Zagnieżdżona wirtualizacja włączona, jądro bez poprawki."
        return "vulnerable", "Brak obejścia konfiguracją — potrzebne jądro z poprawką i restart węzła."


class HardenerMissing(RuntimeError):
    """Węzeł bez jednostki virthub-harden (stary instalator)."""


def main() -> int:
    """`python -m agent.host_security --kernel-patched` dla harden-host.sh:
    kod 0 — jądro ma poprawki, 1 — nie ma, 2 — nie dotyczy tej architektury."""
    import sys

    if "--kernel-patched" in sys.argv:
        patched = HostSecurity(kvm_host=True).kernel_patched()
        return 2 if patched is None else (0 if patched else 1)
    return 64


if __name__ == "__main__":
    raise SystemExit(main())
