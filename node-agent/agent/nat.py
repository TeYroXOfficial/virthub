"""NAT węzła: mostek dla adresów prywatnych, maskarada i przekierowanie portów.

Maszyna z adresem z puli NAT nie stoi na mostku z kartą fizyczną, tylko na
osobnym mostku (domyślnie `vhnat0`), którego adresem jest brama puli. Węzeł
routuje jej ruch i podmienia adres źródłowy na swój (albo na wskazany adres
publiczny). Z zewnątrz do maszyny prowadzą wyłącznie przekierowane porty.

Reguły siedzą w osobnej tabeli rodziny `inet` — hooki NAT działają na
warstwie IP, więc tabela `bridge` od anty-spoofingu ich nie widzi. Sieci,
adresy wyjścia i porty trzymamy w zbiorach i mapach: reguły tabeli są stałe,
a konfiguracja maszyny to dodanie lub usunięcie elementów. Nie trzeba przez
to nigdy szukać ani kasować pojedynczych reguł po uchwytach.

Porty przypisane maszynie zapisujemy w pliku stanu. Przy usuwaniu maszyny
agent zna tylko jej numer, a przy restarcie hosta mostek i tabela znikają
razem z jądrem — plik stanu pozwala posprzątać jedno i odtworzyć drugie.
"""

from __future__ import annotations

import ipaddress
import json
import logging
import subprocess
from pathlib import Path

from .config import Settings
from .schemas import NetworkInterfaceSpec
from .shell import CommandError, run

log = logging.getLogger("virthub.nat")

SSH_PORT = 22


def port_forwards(spec: NetworkInterfaceSpec) -> dict[int, int]:
    """Port zewnętrzny → port w maszynie.

    Pierwszy port bloku prowadzi na SSH, reszta 1:1. Mapowanie 1:1 zamiast
    „10101 → 80" jest celowe: klient widzi w panelu jeden zakres i uruchamia
    usługę na porcie, który zna, bez tabelki tłumaczeń.
    """
    nat = spec.nat
    if spec.version != 4 or nat is None or nat.port_from is None:
        return {}

    forwards = {nat.port_from: SSH_PORT}
    for port in range(nat.port_from + 1, nat.port_to + 1):
        forwards[port] = port
    return forwards


class NatManager:
    FAMILY = "inet"

    def __init__(self, settings: Settings):
        self.settings = settings
        self.table = f"{settings.nft_table}_nat"
        self.bridge = settings.nat_bridge
        self.state_dir: Path = settings.nat_state_dir

    # --- publiczne API ------------------------------------------------------

    def prepare(self, interfaces: list[NetworkInterfaceSpec]) -> None:
        """Mostek z bramami pul i przekazywanie pakietów — przed startem maszyny,
        bo libvirt i Incus odmawiają podpięcia do nieistniejącego mostka."""
        nat = [i for i in interfaces if i.mode == "nat"]
        if not nat or self.settings.is_mock:
            return

        self._ensure_bridge()
        for gateway in self.render_bridge_addresses(nat):
            run(["ip", "addr", "replace", gateway, "dev", self.bridge])

        self._write_sysctl("/proc/sys/net/ipv4/ip_forward")
        if any(i.version == 6 for i in nat):
            # Uwaga dla hostów konfigurowanych przez SLAAC: włączone
            # przekazywanie wyłącza przyjmowanie ogłoszeń routera, chyba że
            # interfejs wyjściowy ma accept_ra=2 (opisane w docs/wdrozenie.md).
            self._write_sysctl("/proc/sys/net/ipv6/conf/all/forwarding")

    def configure(self, server_id: int, interfaces: list[NetworkInterfaceSpec]) -> None:
        """Podmienia NAT maszyny: sieci, adres wyjścia i przekierowane porty."""
        nat = [i for i in interfaces if i.mode == "nat"]
        previous = self._load_state(server_id)

        if not nat:
            if previous is not None:
                self.teardown(server_id)
            return

        forwards = {}
        for spec in nat:
            forwards.update({port: (spec.address, target) for port, target in port_forwards(spec).items()})

        if self.settings.is_mock:
            log.info("[mock] pominięto NAT dla maszyny %s (%s portów)", server_id, len(forwards))
            self._save_state(server_id, nat)
            return

        self.prepare(nat)
        self.ensure_base_table()

        stale = set(previous_ports(previous)) - set(forwards)
        # Klucz może być zajęty przez wpis po poprzednim właścicielu adresu —
        # usuwamy go w tej samej transakcji, w której dodajemy nowy.
        existing = self._existing_ports()
        to_delete = sorted((stale | set(forwards)) & existing)

        self._apply(self.render_machine(nat, forwards, to_delete))
        self._save_state(server_id, nat)
        log.info("NAT maszyny %s: %s przekierowanych portów", server_id, len(forwards))

    def teardown(self, server_id: int) -> None:
        """Usuwa przekierowania maszyny — port wróci do puli razem z adresem
        i nie może dalej prowadzić do cudzej maszyny."""
        previous = self._load_state(server_id)
        if previous is None:
            return

        if not self.settings.is_mock:
            ports = sorted(set(previous_ports(previous)) & self._existing_ports())
            if ports:
                self._apply(self.render_delete(ports))

        self._state_file(server_id).unlink(missing_ok=True)

    def restore(self) -> int:
        """Po restarcie hosta odtwarza mostek, tabelę i przekierowania ze stanu.

        Zwraca liczbę maszyn, dla których NAT został odtworzony. Błąd jednej
        maszyny nie blokuje pozostałych — agent musi wstać, żeby dało się to
        naprawić z panelu.
        """
        if self.settings.is_mock or not self.state_dir.exists():
            return 0

        restored = 0
        for path in sorted(self.state_dir.glob("*.json")):
            try:
                server_id = int(path.stem)
                interfaces = self._load_state(server_id) or []
                # Tabela po restarcie jest pusta — stan poprzedni nie ma już
                # czego usuwać, więc konfigurujemy od zera.
                path.unlink(missing_ok=True)
                self.configure(server_id, interfaces)
                restored += 1
            except Exception:
                log.exception("Nie udało się odtworzyć NAT z %s", path)
        return restored

    def ensure_base_table(self) -> None:
        if self.settings.is_mock:
            return

        self._apply(self.render_base())

        # `add rule` nie jest idempotentne — reguły dokładamy tylko raz.
        listing = run(["nft", "list", "table", self.FAMILY, self.table])
        if "@fwd4" not in listing:
            self._apply(self.render_base_rules())

    # --- generowanie reguł (czyste funkcje, testowalne bez nft) --------------

    def render_base(self) -> str:
        t = f"{self.FAMILY} {self.table}"
        return (
            f"add table {t}\n"
            f"add set {t} nets4 {{ type ipv4_addr ; flags interval ; }}\n"
            f"add set {t} nets6 {{ type ipv6_addr ; flags interval ; }}\n"
            f"add map {t} snat4 {{ type ipv4_addr : ipv4_addr ; flags interval ; }}\n"
            f"add map {t} snat6 {{ type ipv6_addr : ipv6_addr ; flags interval ; }}\n"
            f"add map {t} fwd4 {{ type inet_service : ipv4_addr . inet_service ; }}\n"
            f"add chain {t} prerouting {{ type nat hook prerouting priority dstnat ; policy accept ; }}\n"
            f"add chain {t} postrouting {{ type nat hook postrouting priority srcnat ; policy accept ; }}\n"
        )

    def render_base_rules(self) -> str:
        t = f"{self.FAMILY} {self.table}"
        rules = [
            # Przekierowanie działa na każdy adres lokalny węzła — węzeł może
            # mieć kilka adresów publicznych, a klient ma trafić na maszynę
            # niezależnie od tego, który z nich zna.
            f"add rule {t} prerouting meta nfproto ipv4 fib daddr type local dnat ip to tcp dport map @fwd4",
            f"add rule {t} prerouting meta nfproto ipv4 fib daddr type local dnat ip to udp dport map @fwd4",
            # Ruch między maszynami tej samej sieci prywatnej idzie bez NAT-u.
            # Mapa adresów wyjścia przed maskaradą: jeśli sieć ma przypisany
            # adres publiczny, dostaje go; w przeciwnym razie adres interfejsu.
            f"add rule {t} postrouting ip saddr @nets4 ip daddr != @nets4 snat ip to ip saddr map @snat4",
            f"add rule {t} postrouting ip saddr @nets4 ip daddr != @nets4 masquerade",
            f"add rule {t} postrouting ip6 saddr @nets6 ip6 daddr != @nets6 snat ip6 to ip6 saddr map @snat6",
            f"add rule {t} postrouting ip6 saddr @nets6 ip6 daddr != @nets6 masquerade",
        ]
        return "\n".join(rules) + "\n"

    def render_bridge_addresses(self, interfaces: list[NetworkInterfaceSpec]) -> list[str]:
        """Adresy węzła na mostku NAT — brama każdej sieci z maską tej sieci."""
        addresses: list[str] = []
        for spec in interfaces:
            if spec.nat is None or not spec.gateway:
                continue
            entry = f"{spec.gateway}/{spec.nat.ip_network.prefixlen}"
            if entry not in addresses:
                addresses.append(entry)
        return addresses

    def render_machine(
        self,
        interfaces: list[NetworkInterfaceSpec],
        forwards: dict[int, tuple[str, int]],
        delete_ports: list[int],
    ) -> str:
        t = f"{self.FAMILY} {self.table}"
        lines: list[str] = []

        if delete_ports:
            lines.append(self._delete_line(delete_ports).rstrip("\n"))

        for spec in interfaces:
            nat = spec.nat
            if nat is None:
                continue
            suffix = "4" if spec.version == 4 else "6"
            lines.append(f"add element {t} nets{suffix} {{ {nat.network} }}")
            if nat.snat_address:
                snat_version = ipaddress.ip_address(nat.snat_address).version
                if snat_version == spec.version:
                    lines.append(
                        f"add element {t} snat{suffix} {{ {nat.network} : {nat.snat_address} }}"
                    )

        if forwards:
            elements = ", ".join(
                f"{port} : {address} . {target}" for port, (address, target) in sorted(forwards.items())
            )
            lines.append(f"add element {t} fwd4 {{ {elements} }}")

        return "\n".join(lines) + "\n"

    def render_delete(self, ports: list[int]) -> str:
        return self._delete_line(ports)

    def _delete_line(self, ports: list[int]) -> str:
        keys = ", ".join(str(p) for p in sorted(ports))
        return f"delete element {self.FAMILY} {self.table} fwd4 {{ {keys} }}\n"

    # --- stan ---------------------------------------------------------------

    def _state_file(self, server_id: int) -> Path:
        return self.state_dir / f"{server_id}.json"

    def _save_state(self, server_id: int, interfaces: list[NetworkInterfaceSpec]) -> None:
        self.state_dir.mkdir(parents=True, exist_ok=True)
        self._state_file(server_id).write_text(
            json.dumps([i.model_dump() for i in interfaces], indent=2),
            encoding="utf-8",
        )

    def _load_state(self, server_id: int) -> list[NetworkInterfaceSpec] | None:
        path = self._state_file(server_id)
        if not path.exists():
            return None
        try:
            raw = json.loads(path.read_text(encoding="utf-8"))
            return [NetworkInterfaceSpec(**item) for item in raw]
        except (ValueError, TypeError):
            log.warning("Uszkodzony plik stanu NAT %s — pomijam", path)
            return None

    # --- wykonanie ----------------------------------------------------------

    def _ensure_bridge(self) -> None:
        try:
            run(["ip", "link", "show", "dev", self.bridge])
        except CommandError:
            run(["ip", "link", "add", "name", self.bridge, "type", "bridge"])
            log.info("Utworzono mostek NAT %s", self.bridge)
        run(["ip", "link", "set", "dev", self.bridge, "up"])

    def _existing_ports(self) -> set[int]:
        try:
            listing = json.loads(run(["nft", "-j", "list", "map", self.FAMILY, self.table, "fwd4"]))
        except (CommandError, ValueError):
            return set()

        ports: set[int] = set()
        for entry in listing.get("nftables", []):
            for element in (entry.get("map") or {}).get("elem", []) or []:
                key = element[0] if isinstance(element, list) else element
                if isinstance(key, int):
                    ports.add(key)
        return ports

    @staticmethod
    def _write_sysctl(path: str) -> None:
        try:
            Path(path).write_text("1\n", encoding="ascii")
        except OSError as exc:
            raise CommandError(["sysctl", path], 1, str(exc)) from exc

    def _apply(self, script: str) -> None:
        """Skrypt przez stdin `nft -f -` — jedna transakcja."""
        proc = subprocess.run(
            ["nft", "-f", "-"],
            input=script,
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        if proc.returncode != 0:
            raise CommandError(["nft", "-f", "-"], proc.returncode, proc.stderr)


def previous_ports(previous: list[NetworkInterfaceSpec] | None) -> list[int]:
    ports: list[int] = []
    for spec in previous or []:
        ports.extend(port_forwards(spec))
    return ports
