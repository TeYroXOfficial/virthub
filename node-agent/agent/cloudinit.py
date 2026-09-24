"""Generowanie nośnika cloud-init (NoCloud) dla nowego VPS-a.

Obraz szablonu jest niezmieniony i współdzielony przez wszystkie VPS-y — cała
personalizacja (hostname, adresacja, klucz SSH, hasło root) jedzie na osobnym
ISO montowanym jako drugi napęd. Gość wykrywa etykietę woluminu `cidata`,
czyta konfigurację przy pierwszym starcie i zapisuje ją u siebie.

Alternatywą byłoby modyfikowanie dysku gościa przez libguestfs, ale to wymaga
montowania cudzego systemu plików na hoście przy każdym provisioningu — dużo
większa powierzchnia ataku za tę samą funkcjonalność.
"""

from __future__ import annotations

import logging
import tempfile
from pathlib import Path

from .config import Settings
from .schemas import NetworkInterfaceSpec
from .shell import CommandError, run, which

log = logging.getLogger("virthub.cloudinit")


class CloudInitError(RuntimeError):
    pass


def _yaml_list(items: list[str], indent: int) -> str:
    if not items:
        return " []"
    pad = " " * indent
    return "\n" + "\n".join(f"{pad}- {item}" for item in items)


def render_meta_data(server_id: int, hostname: str) -> str:
    return f"instance-id: virthub-{server_id}\nlocal-hostname: {hostname}\n"


# qemu-guest-agent pozwala panelowi zmienić hasło roota w działającej maszynie
# (reset hasła) bez logowania się do niej. Obrazy cloud zwykle go nie mają.
VM_PACKAGES = ["qemu-guest-agent"]


def render_user_data(
    hostname: str,
    ssh_keys: list[str],
    root_password: str | None,
    packages: list[str] | None = None,
) -> str:
    """Konfiguracja pierwszego startu.

    `packages` — pakiety doinstalowywane przy pierwszym starcie. Obrazy
    kontenerów LXC w odróżnieniu od obrazów cloud dla maszyn wirtualnych
    nie mają serwera SSH, więc bez tego klient nie miałby jak się zalogować.
    """
    lines = [
        "#cloud-config",
        f"hostname: {hostname}",
        "manage_etc_hosts: true",
        # Obrazy cloud domyślnie blokują logowanie na roota — wpisują do jego
        # authorized_keys polecenie „zaloguj się jako debian/ubuntu". Klient
        # dostaje konto root, więc ta blokada musi zniknąć.
        "disable_root: false",
        "users:",
        "  - name: root",
        f"    ssh_authorized_keys:{_yaml_list(ssh_keys, 6)}",
    ]

    if root_password:
        lines += [
            "chpasswd:",
            "  expire: false",
            "  list: |",
            f"    root:{root_password}",
            "ssh_pwauth: true",
            # Debian i Ubuntu mają domyślnie PermitRootLogin prohibit-password:
            # samo ssh_pwauth włącza hasła dla wszystkich poza rootem, czyli
            # dokładnie poza jedynym kontem, jakie klient ma.
            "write_files:",
            "  - path: /etc/ssh/sshd_config.d/10-virthub.conf",
            "    permissions: '0644'",
            "    content: |",
            "      PermitRootLogin yes",
            "      PasswordAuthentication yes",
        ]
    else:
        # Bez hasła zostawiamy wyłączone logowanie hasłem — inaczej obraz z
        # domyślnym hasłem szablonu byłby dostępny dla każdego skanera.
        lines.append("ssh_pwauth: false")

    if packages:
        lines += ["package_update: true", f"packages:{_yaml_list(packages, 2)}"]
    else:
        lines.append("package_update: false")

    # Usługa nazywa się „ssh" w rodzinie Debiana i „sshd" w rodzinie RHEL.
    lines += [
        "runcmd:",
        "  - [ sh, -c, 'systemctl enable --now ssh 2>/dev/null || systemctl enable --now sshd' ]",
        "  - [ sh, -c, 'systemctl restart ssh 2>/dev/null || systemctl restart sshd' ]",
    ]
    if packages and "qemu-guest-agent" in packages:
        lines.append("  - [ sh, -c, 'systemctl enable --now qemu-guest-agent || true' ]")
    return "\n".join(lines) + "\n"


def render_network_config(
    interfaces: list[NetworkInterfaceSpec],
    nameservers: list[str],
    mac: str,
) -> str:
    """Netplan-owy format v2, dopasowanie po MAC zamiast po nazwie interfejsu.

    Nazwa (ens3/eth0) zależy od dystrybucji i wersji jądra gościa; MAC ustawiamy
    my w definicji domeny, więc jest jedyną stabilną kotwicą.
    """
    addresses = [f"{i.address}/{i.prefix}" for i in interfaces]
    gateway4 = next((i.gateway for i in interfaces if i.version == 4 and i.gateway), None)
    gateway6 = next((i.gateway for i in interfaces if i.version == 6 and i.gateway), None)

    lines = [
        "version: 2",
        "ethernets:",
        "  primary:",
        "    match:",
        f"      macaddress: \"{mac}\"",
        "    dhcp4: false",
        "    dhcp6: false",
        f"    addresses:{_yaml_list(addresses, 6)}",
        "    nameservers:",
        f"      addresses:{_yaml_list(nameservers, 8)}",
    ]

    routes = []
    if gateway4:
        routes.append(("0.0.0.0/0", gateway4))
    if gateway6:
        routes.append(("::/0", gateway6))
    if routes:
        lines.append("    routes:")
        for destination, via in routes:
            lines.append(f"      - to: {destination}")
            lines.append(f"        via: {via}")
            # Brama spoza podsieci adresu (typowe u dostawców bare-metal)
            # wymaga trasy on-link, inaczej gość nie zestawi domyślnej trasy.
            lines.append("        on-link: true")

    return "\n".join(lines) + "\n"


class CloudInitBuilder:
    def __init__(self, settings: Settings):
        self.settings = settings

    def seed_path(self, name: str) -> Path:
        return self.settings.seed_dir / f"{name}-seed.iso"

    def build(
        self,
        *,
        name: str,
        server_id: int,
        hostname: str,
        interfaces: list[NetworkInterfaceSpec],
        nameservers: list[str],
        ssh_keys: list[str],
        root_password: str | None,
        mac: str,
    ) -> Path:
        target = self.seed_path(name)
        target.parent.mkdir(parents=True, exist_ok=True)

        payload = {
            "meta-data": render_meta_data(server_id, hostname),
            "user-data": render_user_data(hostname, ssh_keys, root_password, packages=VM_PACKAGES),
            "network-config": render_network_config(interfaces, nameservers, mac),
        }

        if self.settings.is_mock:
            target.write_text(
                "\n---\n".join(f"# {k}\n{v}" for k, v in payload.items()),
                encoding="utf-8",
            )
            log.info("[mock] zapisano treść cloud-init do %s", target)
            return target

        with tempfile.TemporaryDirectory(prefix="virthub-seed-") as tmp:
            tmp_dir = Path(tmp)
            for filename, content in payload.items():
                (tmp_dir / filename).write_text(content, encoding="utf-8")

            argv = self._iso_command(target, tmp_dir, list(payload))
            try:
                run(argv, timeout=120)
            except CommandError as exc:
                raise CloudInitError(
                    f"Nie udało się zbudować nośnika cloud-init: {exc}"
                ) from exc

        target.chmod(0o600)  # zawiera hasło root do pierwszego logowania
        log.info("Zbudowano nośnik cloud-init %s", target)
        return target

    def _iso_command(self, target: Path, source_dir: Path, files: list[str]) -> list[str]:
        sources = [str(source_dir / f) for f in files]

        if which("genisoimage"):
            return ["genisoimage", "-output", str(target), "-volid", "cidata",
                    "-joliet", "-rock", *sources]
        if which("xorriso"):
            return ["xorriso", "-as", "mkisofs", "-output", str(target),
                    "-volid", "cidata", "-joliet", "-rock", *sources]

        raise CloudInitError(
            "Na hypervisorze brakuje genisoimage lub xorriso — bez nich nie da się "
            "zbudować nośnika cloud-init. Zainstaluj pakiet genisoimage."
        )
