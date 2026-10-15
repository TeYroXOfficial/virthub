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

import base64
import ipaddress
import json
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
    scripts: list | None = None,
    swap_mb: int | None = None,
) -> str:
    """Konfiguracja pierwszego startu.

    `scripts` — provisionery z panelu (ProvisionScript): zapisywane przez
    write_files (base64) i uruchamiane po kolei na końcu runcmd, z logiem
    w /var/log/virthub-provision/.

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
    files: list[str] = []
    run_scripts: list[str] = []
    for index, script in enumerate(scripts or [], start=1):
        target = f"{script.path}/{index:02d}-{script.name}"
        log = f"/var/log/virthub-provision/{index:02d}-{script.name}.log"
        files += [
            f"  - path: {target}",
            "    permissions: '0700'",
            "    encoding: b64",
            f"    content: {base64.b64encode(script.content.encode()).decode()}",
        ]
        # Ścieżki pochodzą z walidowanego schematu (bez spacji i cudzysłowów).
        run_scripts.append(
            f"  - [ sh, -c, 'mkdir -p /var/log/virthub-provision && {target} > {log} 2>&1; "
            f"echo \"exit=$?\" >> {log}' ]"
        )

    if root_password:
        lines += [
            "chpasswd:",
            "  expire: false",
            "  list: |",
            f"    root:{root_password}",
            "ssh_pwauth: true",
        ]
        # Debian i Ubuntu mają domyślnie PermitRootLogin prohibit-password:
        # samo ssh_pwauth włącza hasła dla wszystkich poza rootem, czyli
        # dokładnie poza jedynym kontem, jakie klient ma.
        files += [
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

    if files:
        lines += ["write_files:", *files]

    if swap_mb:
        size = swap_mb * 1024 * 1024
        lines += ["swap:", "  filename: /swapfile", f"  size: {size}", f"  maxsize: {size}"]

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
    lines += run_scripts
    return "\n".join(lines) + "\n"


def render_network_config(
    interfaces: list[NetworkInterfaceSpec],
    nameservers: list[str],
    mac: str,
    private_nics: list | None = None,
    server_id: int = 0,
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

    # Karty w sieciach prywatnych: sam adres, bez bramy i DNS — trasa domyślna zostaje na karcie głównej.
    from .private_net import nic_mac

    for nic in sorted(private_nics or [], key=lambda n: n.slot):
        lines += [
            f"  private{nic.slot}:",
            "    match:",
            f"      macaddress: \"{(nic.mac or nic_mac(server_id, nic.slot)).lower()}\"",
            "    dhcp4: false",
            "    dhcp6: false",
        ]
        if nic.address:
            lines.append(f"    addresses:{_yaml_list([f'{nic.address}/{nic.prefix}'], 6)}")

    return "\n".join(lines) + "\n"


def render_windows_meta_data(server_id: int, hostname: str, password: str | None) -> str:
    """meta_data.json nośnika config-2 (OpenStack) dla cloudbase-init w Windows.

    cloudbase-init ustawia z niego hasło Administratora (admin_pass) i nazwę
    komputera (skróconą do 15 znaków NetBIOS)."""
    data: dict = {
        "uuid": f"virthub-{server_id}",
        "hostname": hostname,
        "name": hostname.split(".")[0],
        "launch_index": 0,
        "availability_zone": "virthub",
    }
    if password:
        data["admin_pass"] = password
    return json.dumps(data, indent=2)


def render_network_data(interfaces: list[NetworkInterfaceSpec], nameservers: list[str], mac: str,
                        private_nics: list | None = None, server_id: int = 0) -> str:
    """network_data.json (format OpenStack) — statyczna adresacja dla cloudbase-init."""
    networks = []
    for index, iface in enumerate(interfaces):
        net = ipaddress.ip_interface(f"{iface.address}/{iface.prefix}").network
        entry: dict = {
            "id": f"network{index}",
            "link": "interface0",
            "type": "ipv4" if iface.version == 4 else "ipv6",
            "ip_address": iface.address,
            "netmask": str(net.netmask),
            "routes": [],
        }
        if iface.gateway:
            entry["routes"].append({
                "network": "0.0.0.0" if iface.version == 4 else "::",
                "netmask": "0.0.0.0" if iface.version == 4 else "::",
                "gateway": iface.gateway,
            })
        networks.append(entry)
    from .private_net import nic_mac

    links = [{"id": "interface0", "type": "phy", "ethernet_mac_address": mac, "mtu": 1500}]
    for nic in sorted(private_nics or [], key=lambda n: n.slot):
        link = f"interface{nic.slot}"
        links.append({"id": link, "type": "phy", "ethernet_mac_address": (nic.mac or nic_mac(server_id, nic.slot)).lower(), "mtu": 1500})
        if nic.address:
            net = ipaddress.ip_interface(f"{nic.address}/{nic.prefix}").network
            networks.append({"id": f"private{nic.slot}", "link": link, "type": "ipv4", "ip_address": nic.address,
                             "netmask": str(net.netmask), "routes": []})
    return json.dumps({
        "links": links,
        "networks": networks,
        "services": [{"type": "dns", "address": ns} for ns in nameservers],
    }, indent=2)


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
        os_type: str = "linux",
        scripts: list | None = None,
        private_nics: list | None = None,
        swap_mb: int | None = None,
    ) -> Path:
        target = self.seed_path(name)
        target.parent.mkdir(parents=True, exist_ok=True)

        if os_type == "windows":
            # cloudbase-init (obrazy Windows) czyta nośnik config-2 w formacie OpenStack.
            payload = {
                "openstack/latest/meta_data.json": render_windows_meta_data(server_id, hostname, root_password),
                "openstack/latest/network_data.json": render_network_data(interfaces, nameservers, mac, private_nics, server_id),
            }
            return self._write(target, payload, label="config-2")

        payload = {
            "meta-data": render_meta_data(server_id, hostname),
            "user-data": render_user_data(hostname, ssh_keys, root_password, packages=VM_PACKAGES, scripts=scripts, swap_mb=swap_mb),
            "network-config": render_network_config(interfaces, nameservers, mac, private_nics, server_id),
        }

        return self._write(target, payload, label="cidata")

    def _write(self, target: Path, payload: dict[str, str], *, label: str) -> Path:
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
                (tmp_dir / filename).parent.mkdir(parents=True, exist_ok=True)
                (tmp_dir / filename).write_text(content, encoding="utf-8")

            argv = self._iso_command(target, tmp_dir, label)
            try:
                run(argv, timeout=120)
            except CommandError as exc:
                raise CloudInitError(
                    f"Nie udało się zbudować nośnika cloud-init: {exc}"
                ) from exc

        target.chmod(0o600)  # zawiera hasło root do pierwszego logowania
        log.info("Zbudowano nośnik cloud-init %s", target)
        return target

    def _iso_command(self, target: Path, source_dir: Path, label: str = "cidata") -> list[str]:
        # Cały katalog jako korzeń nośnika — config-2 ma podkatalogi openstack/latest.
        if which("genisoimage"):
            return ["genisoimage", "-output", str(target), "-volid", label,
                    "-joliet", "-rock", str(source_dir)]
        if which("xorriso"):
            return ["xorriso", "-as", "mkisofs", "-output", str(target),
                    "-volid", label, "-joliet", "-rock", str(source_dir)]

        raise CloudInitError(
            "Na hypervisorze brakuje genisoimage lub xorriso — bez nich nie da się "
            "zbudować nośnika cloud-init. Zainstaluj pakiet genisoimage."
        )
