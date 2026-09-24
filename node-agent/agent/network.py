"""Sieć VPS-a: nazwy interfejsów, anty-spoofing i firewall per maszyna.

Reguły nie są nigdy pisane ręcznie na hoście — agent generuje cały łańcuch dla
danego VPS-a z reguł zapisanych w control plane i podmienia go atomowo. Dzięki
temu baza jest źródłem prawdy: po odtworzeniu hypervisora z backupu reguły
wracają takie same.

Najważniejsza reguła nie jest konfigurowalna przez klienta: pakiet wychodzący z
interfejsu VPS-a musi mieć adres źródłowy przypisany do tego VPS-a. Bez tego
jeden klient może podszyć się pod adres drugiego albo pod bramę.
"""

from __future__ import annotations

import logging
import subprocess

from .config import Settings
from .nat import NatManager
from .schemas import FirewallPolicy, FirewallRule, NetworkInterfaceSpec
from .shell import CommandError, run

log = logging.getLogger("virthub.network")


def interface_name(server_id: int) -> str:
    """Deterministyczna nazwa tap-interfejsu.

    Nie zdajemy się na automatyczne vnet0/vnet1 od libvirt — po restarcie hosta
    numeracja może się przesunąć i reguły firewalla trafiłyby w cudzą maszynę.
    Limit nazwy interfejsu w jądrze to 15 znaków, stąd skrótowy prefiks.
    """
    return f"vh{server_id}"


def mac_address(server_id: int) -> str:
    """MAC z puli lokalnie administrowanej (52:54:00 = QEMU), stały dla VPS-a."""
    return f"52:54:00:{(server_id >> 16) & 0xFF:02x}:{(server_id >> 8) & 0xFF:02x}:{server_id & 0xFF:02x}"


class NetworkManager:
    """Reguły nftables dla maszyn i kontenerów.

    Rodzina `bridge`, a nie `inet`: ruch maszyny idzie przez mostek (port vhN ↔
    karta fizyczna) i nigdy nie trafia do haków IP — reguły w tabeli `inet`
    po prostu by go nie widziały, chyba że ktoś załaduje br_netfilter. Hak
    `forward` rodziny bridge widzi dokładnie ramki przechodzące między portami
    mostka, niezależnie od tego, czy po drugiej stronie jest QEMU, czy veth
    kontenera.

    Łańcuchy maszyn są podpięte przez mapę werdyktów interfejs → łańcuch.
    Dzięki temu podmiana reguł to wyczyszczenie i ponowne wypełnienie
    łańcucha w jednej transakcji, bez kasowania go spod istniejących skoków.

    Poza `forward` ta sama mapa jest podpięta pod `input` i `output` mostka.
    Maszyna za NAT-em rozmawia ze światem przez węzeł (brama na mostku NAT),
    więc jej ruch nie przechodzi między portami, tylko wpada do hosta —
    bez tych hooków anty-spoofing i firewall klienta by go nie widziały.
    """

    FAMILY = "bridge"
    PORTS_MAP = "vm_ports"

    # hook → kierunek dopasowania interfejsu maszyny
    HOOKS = {"forward": ("iifname", "oifname"), "input": ("iifname",), "output": ("oifname",)}

    def __init__(self, settings: Settings):
        self.settings = settings
        self.table = settings.nft_table
        self.nat = NatManager(settings)
        self._stateful: bool | None = None

    def bridge_for(self, interfaces: list[NetworkInterfaceSpec]) -> str:
        """Mostek, do którego trzeba podpiąć maszynę.

        Wszystkie adresy maszyny są jednego rodzaju (panel tego pilnuje), bo
        maszyna ma jedną kartę sieciową: albo na mostku z kartą fizyczną,
        albo na mostku NAT.
        """
        if any(i.mode == "nat" for i in interfaces):
            return self.settings.nat_bridge
        return self.settings.bridge

    def prepare(self, interfaces: list[NetworkInterfaceSpec]) -> str:
        """Przygotowuje mostek pod maszynę i zwraca jego nazwę."""
        self.nat.prepare(interfaces)
        return self.bridge_for(interfaces)

    # --- publiczne API ------------------------------------------------------

    def ensure_base_table(self) -> None:
        """Tabela, mapa portów i łańcuch bazowy — tworzone raz, idempotentnie."""
        if self.settings.is_mock:
            return

        self._apply(self.render_base())

        # `add rule` nie jest idempotentne, więc reguły kierujące ruch do mapy
        # dokładamy tylko tam, gdzie jeszcze ich nie ma.
        for hook, matches in self.HOOKS.items():
            listing = run(["nft", "list", "chain", self.FAMILY, self.table, hook])
            if f"@{self.PORTS_MAP}" not in listing:
                self._apply("".join(
                    f"add rule {self.FAMILY} {self.table} {hook} {match} vmap @{self.PORTS_MAP}\n"
                    for match in matches
                ))

    def configure(
        self,
        server_id: int,
        interfaces: list[NetworkInterfaceSpec],
        firewall: list[FirewallRule],
        policy: FirewallPolicy | None = None,
    ) -> None:
        """Podmienia komplet reguł jednej maszyny w jednej transakcji."""
        if self.settings.is_mock:
            log.info("[mock] pominięto konfigurację nftables dla maszyny %s", server_id)
            self.nat.configure(server_id, interfaces)
            return

        self.ensure_base_table()
        self._apply(self.render_machine(
            server_id, interfaces, firewall, policy, stateful=self.stateful(),
        ))
        self.nat.configure(server_id, interfaces)
        log.info("Zastosowano %s reguł firewalla dla maszyny %s", len(firewall), server_id)

    def stateful(self) -> bool:
        """Czy zapora może śledzić połączenia w rodzinie bridge.

        Wymaga modułu nf_conntrack_bridge (instalator węzła go ładuje). Bez
        niego reguła z `ct state` odrzuciłaby całą transakcję — łącznie z
        anty-spoofingiem — więc sprawdzamy to raz, na próbnej tabeli.
        """
        if self._stateful is None:
            if self.settings.is_mock:
                self._stateful = True
            else:
                probe = f"{self.table}_probe"
                try:
                    self._apply(
                        f"add table bridge {probe}\n"
                        f"add chain bridge {probe} c\n"
                        f"add rule bridge {probe} c ct state established accept\n"
                    )
                    self._stateful = True
                except CommandError:
                    self._stateful = False
                    log.warning(
                        "Brak śledzenia połączeń w rodzinie bridge (moduł nf_conntrack_bridge) — "
                        "zapora działa w trybie bezstanowym."
                    )
                try:
                    self._apply(f"delete table bridge {probe}\n")
                except CommandError:
                    pass
        return self._stateful

    def teardown(self, server_id: int) -> None:
        """Sprząta reguły po usuniętej maszynie — inaczej adres wróciłby do puli
        z cudzymi regułami wciąż aktywnymi."""
        self.nat.teardown(server_id)

        if self.settings.is_mock:
            return

        chain = f"vm_{server_id}"
        iface = interface_name(server_id)
        # Każdy krok osobno: brak elementu czy łańcucha nie jest błędem —
        # maszyna mogła nigdy nie dostać reguł (nieudany provisioning).
        for script in (
            f'delete element {self.FAMILY} {self.table} {self.PORTS_MAP} {{ "{iface}" }}\n',
            f"flush chain {self.FAMILY} {self.table} {chain}\n",
            f"delete chain {self.FAMILY} {self.table} {chain}\n",
        ):
            try:
                self._apply(script)
            except CommandError as exc:
                if "No such file or directory" not in exc.stderr:
                    raise

    # --- generowanie reguł (czyste funkcje, testowalne bez nft) --------------

    def render_base(self) -> str:
        t = f"{self.FAMILY} {self.table}"
        chains = "".join(
            f"add chain {t} {hook} {{ type filter hook {hook} priority filter ; policy accept ; }}\n"
            for hook in self.HOOKS
        )
        return (
            f"add table {t}\n"
            f"add map {t} {self.PORTS_MAP} {{ type ifname : verdict ; }}\n"
            f"{chains}"
        )

    def render_machine(
        self,
        server_id: int,
        interfaces: list[NetworkInterfaceSpec],
        firewall: list[FirewallRule],
        policy: FirewallPolicy | None = None,
        stateful: bool = True,
    ) -> str:
        t = f"{self.FAMILY} {self.table}"
        chain = f"vm_{server_id}"
        iface = interface_name(server_id)
        out = f'iifname "{iface}"'
        inbound = f'oifname "{iface}"'

        v4 = [i.address for i in interfaces if i.version == 4]
        v6 = [i.address for i in interfaces if i.version == 6]

        rules = [
            # Z maszyny wychodzi wyłącznie IPv4, IPv6 i ARP. Reszta (znaczniki
            # VLAN, protokoły warstwy 2) to albo pomyłka, albo próba ataku na
            # sieć dostawcy.
            f"{out} ether type != {{ ip, ip6, arp }} drop",
        ]

        # Anty-spoofing: ruch i ARP wyłącznie z adresów przypisanych maszynie.
        # Zapisane jako „odrzuć, jeśli nie z puli" zamiast „przyjmij, jeśli z
        # puli" — dzięki temu reguły klienta dla ruchu wychodzącego niżej w
        # łańcuchu nadal są sprawdzane, a nie przeskakiwane.
        if v4:
            pool4 = ", ".join(v4)
            rules += [
                f"{out} arp saddr ip != {{ {pool4} }} drop",
                f"{out} ip saddr != {{ {pool4} }} drop",
            ]
        else:
            rules.append(f"{out} ether type {{ ip, arp }} drop")

        # IPv6: adresy link-local i nieokreślony (::) są konieczne dla Neighbor
        # Discovery i DAD — bez nich IPv6 w maszynie w ogóle nie wstanie.
        pool6 = ", ".join(["fe80::/10", "::", *v6])
        rules.append(f"{out} ip6 saddr != {{ {pool6} }} drop")

        # Zapora klienta. Bez polityki (starszy panel) reguły działają jak
        # dawniej — bez domyślnej blokady.
        if policy is None or policy.enabled:
            restrictive = policy is not None and "drop" in (policy.inbound, policy.outbound)
            if restrictive:
                rules += self._render_baseline(inbound, out, stateful)
            rules += [self._render_rule(rule, iface) for rule in firewall]
            if policy is not None and policy.inbound == "drop":
                rules.append(f"{inbound} meta protocol {{ ip, ip6 }} drop")
            if policy is not None and policy.outbound == "drop":
                rules.append(f"{out} meta protocol {{ ip, ip6 }} drop")

        lines = [
            f"add chain {t} {chain}",
            f"flush chain {t} {chain}",
            *[f"add rule {t} {chain} {rule}" for rule in rules],
            # Podpięcie łańcucha po wypełnieniu — w tej samej transakcji, więc
            # nie ma chwili, w której maszyna chodzi bez reguł.
            f'add element {t} {self.PORTS_MAP} {{ "{iface}" : jump {chain} }}',
        ]
        return "\n".join(lines) + "\n"

    ND_TYPES = "nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit, nd-router-advert, nd-redirect"

    def _render_baseline(self, inbound: str, out: str, stateful: bool) -> list[str]:
        """Ruch, bez którego domyślna blokada zepsułaby maszynę.

        Odpowiedzi na połączenia nawiązane przez samą maszynę (i do niej) oraz
        Neighbor Discovery IPv6 — bez ND maszyna traci IPv6, jak bez ARP IPv4.
        ARP nie jest IP, więc blokada `meta protocol { ip, ip6 }` go nie dotyka.
        """
        rules = [
            f"{inbound} icmpv6 type {{ {self.ND_TYPES} }} accept",
            f"{out} icmpv6 type {{ {self.ND_TYPES} }} accept",
        ]
        if stateful:
            rules += [
                f"{inbound} ct state established,related accept",
                f"{out} ct state established,related accept",
                f"{inbound} ct state invalid drop",
            ]
        else:
            # Tryb bezstanowy (brak nf_conntrack_bridge): przepuszczamy TCP,
            # które nie otwiera nowego połączenia, i odpowiedzi DNS/NTP.
            rules += [
                f"{inbound} meta l4proto tcp tcp flags & (syn | ack) != syn accept",
                f"{out} meta l4proto tcp tcp flags & (syn | ack) != syn accept",
                f"{inbound} meta l4proto udp udp sport {{ 53, 123 }} accept",
            ]
        return rules

    def _render_rule(self, rule: FirewallRule, iface: str) -> str:
        parts = [f'oifname "{iface}"'] if rule.direction == "in" else [f'iifname "{iface}"']

        if rule.source:
            family = "ip6" if ":" in rule.source else "ip"
            # Dla ruchu przychodzącego druga strona jest nadawcą, dla
            # wychodzącego — odbiorcą.
            field = "saddr" if rule.direction == "in" else "daddr"
            parts.append(f"{family} {field} {rule.source}")

        if rule.protocol in ("tcp", "udp"):
            parts.append(f"meta l4proto {rule.protocol}")
            if rule.port_from:
                port_to = rule.port_to or rule.port_from
                ports = str(rule.port_from) if port_to == rule.port_from else f"{rule.port_from}-{port_to}"
                parts.append(f"{rule.protocol} dport {ports}")
        elif rule.protocol == "icmp":
            parts.append("meta l4proto { icmp, ipv6-icmp }")

        parts.append(rule.action)
        return " ".join(parts)

    # --- wykonanie ----------------------------------------------------------

    def _apply(self, script: str) -> None:
        """Wgrywa skrypt przez stdin `nft -f -` — jedna transakcja: albo wchodzą
        wszystkie reguły, albo żadna.

        Nie przez plik tymczasowy: ruleset zawiera adresy klientów, a plik w /tmp
        na hoście współdzielonym to niepotrzebna ekspozycja.
        """
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
