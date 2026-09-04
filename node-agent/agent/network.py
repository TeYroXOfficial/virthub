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
from .schemas import FirewallRule, NetworkInterfaceSpec
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
    def __init__(self, settings: Settings):
        self.settings = settings
        self.table = settings.nft_table

    # --- publiczne API używane przez sterownik libvirt -----------------------

    def ensure_base_table(self) -> None:
        """Tworzy tabelę i łańcuch bazowy, jeśli jeszcze nie istnieją."""
        if self.settings.is_mock:
            return
        script = f"""
table inet {self.table} {{
    chain forward {{
        type filter hook forward priority filter; policy accept;
    }}
}}
"""
        self._apply(script)

    def configure(
        self,
        server_id: int,
        interfaces: list[NetworkInterfaceSpec],
        firewall: list[FirewallRule],
    ) -> None:
        """Podmienia komplet reguł dla jednego VPS-a."""
        if self.settings.is_mock:
            log.info("[mock] pominięto konfigurację nftables dla VPS %s", server_id)
            return

        self.ensure_base_table()
        chain = f"vm_{server_id}"
        iface = interface_name(server_id)

        script = [f"table inet {self.table} {{"]
        script.append(f"  chain {chain} {{")

        # 1. Anty-spoofing: ruch wychodzący tylko z przypisanych adresów.
        v4 = [i.address for i in interfaces if i.version == 4]
        v6 = [i.address for i in interfaces if i.version == 6]
        if v4:
            script.append(f"    iifname \"{iface}\" ip saddr {{ {', '.join(v4)} }} accept")
        if v6:
            script.append(f"    iifname \"{iface}\" ip6 saddr {{ {', '.join(v6)} }} accept")
        script.append(f"    iifname \"{iface}\" drop")

        # 2. Reguły klienta dla ruchu przychodzącego do VPS-a.
        for rule in firewall:
            script.append("    " + self._render_rule(rule, iface))

        script.append("  }")
        script.append("}")

        # Podmiana atomowa: usuń stary łańcuch, wgraj nowy, podepnij pod forward.
        self._delete_chain(chain)
        self._apply("\n".join(script))
        self._link_chain(chain, iface)
        log.info("Zastosowano %s reguł firewalla dla VPS %s", len(firewall), server_id)

    def teardown(self, server_id: int) -> None:
        """Sprząta reguły po usuniętym VPS-ie — inaczej adres wróciłby do puli
        z cudzymi regułami wciąż aktywnymi."""
        if self.settings.is_mock:
            return
        self._delete_chain(f"vm_{server_id}")

    # --- wewnętrzne ---------------------------------------------------------

    def _render_rule(self, rule: FirewallRule, iface: str) -> str:
        parts = [f"oifname \"{iface}\""] if rule.direction == "in" else [f"iifname \"{iface}\""]

        if rule.source:
            family = "ip6" if ":" in rule.source else "ip"
            parts.append(f"{family} saddr {rule.source}")

        if rule.protocol != "any":
            parts.append(rule.protocol)
            if rule.protocol in {"tcp", "udp"} and rule.port_from:
                port_to = rule.port_to or rule.port_from
                if port_to == rule.port_from:
                    parts.append(f"dport {rule.port_from}")
                else:
                    parts.append(f"dport {rule.port_from}-{port_to}")

        parts.append(rule.action)
        return " ".join(parts)

    def _apply(self, script: str) -> None:
        """Wgrywa fragment rulesetu przez stdin `nft -f -`.

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

    def _delete_chain(self, chain: str) -> None:
        # Brak łańcucha nie jest błędem — konfigurujemy VPS-a pierwszy raz.
        try:
            run(["nft", "flush", "chain", "inet", self.table, chain], check=True)
            run(["nft", "delete", "chain", "inet", self.table, chain], check=True)
        except CommandError as exc:
            if "No such file or directory" not in exc.stderr:
                raise

    def _link_chain(self, chain: str, iface: str) -> None:
        run([
            "nft", "add", "rule", "inet", self.table, "forward",
            "iifname", iface, "jump", chain,
        ])
        run([
            "nft", "add", "rule", "inet", self.table, "forward",
            "oifname", iface, "jump", chain,
        ])
