"""Serwer baz danych MariaDB na węźle — instalacja jednym kliknięciem z panelu.

Tak jak aktualizacja: agent (bez roota) zostawia plik-zlecenie, a jednostka
systemd `virthub-mariadb.path` uruchamia jako root scripts/setup-mariadb.sh.
Skrypt instaluje MariaDB i zakłada konto administracyjne panelu dostępne
WYŁĄCZNIE z adresu panelu (nigdy z „%”). Dane konta trafiają do
credentials.json (tylko dla agenta); panel odbiera je raz i po zapisaniu
serwera baz każe agentowi plik usunąć.
"""

from __future__ import annotations

import ipaddress
import json
import time
from pathlib import Path

from .config import Settings

SETUP_UNIT = Path("/etc/systemd/system/virthub-mariadb.path")
STALL_AFTER = 90


class MariaDbSetupMissing(RuntimeError):
    pass


class PanelAddressUnknown(ValueError):
    pass


def panel_address(real_ip: str | None) -> str:
    """Adres panelu dla konta administracyjnego — z nagłówka X-Real-IP od nginx.

    Agent słucha na loopbacku za nginx, więc bez tego nagłówka widzi tylko
    127.0.0.1 i nie wie, skąd naprawdę łączy się panel. Wtedy odmawiamy —
    konto z pełnymi uprawnieniami nie może być dostępne z dowolnego adresu.
    """
    if not real_ip:
        raise PanelAddressUnknown(
            "Węzeł nie zna adresu panelu (nginx nie przekazuje X-Real-IP) — zaktualizuj węzeł i spróbuj ponownie."
        )
    try:
        ip = ipaddress.ip_address(real_ip.strip())
    except ValueError as exc:
        raise PanelAddressUnknown(f"Nieprawidłowy adres panelu: {real_ip!r}") from exc
    return "127.0.0.1" if ip.is_loopback else str(ip)


class MariaDbSetup:
    def __init__(self, settings: Settings, unit: Path = SETUP_UNIT):
        self.dir = settings.state_db.parent / "mariadb"
        self.unit = unit

    def enabled(self) -> bool:
        return self.unit.exists()

    def status(self) -> dict:
        state: dict = {"state": "idle"}
        try:
            state = json.loads((self.dir / "status.json").read_text(encoding="utf-8"))
        except (OSError, ValueError):
            pass
        request = self.dir / "request"
        if request.exists() and state.get("state") != "running":
            try:
                stalled = time.time() - request.stat().st_mtime > STALL_AFTER
            except OSError:
                stalled = False
            state = (
                {"state": "stalled", "message": "Usługa instalacji MariaDB nie odebrała zlecenia — zaktualizuj węzeł."}
                if stalled else {"state": "queued"}
            )
        if state.get("state") == "done":
            try:
                creds = json.loads((self.dir / "credentials.json").read_text(encoding="utf-8"))
                state = {**state, "credentials": {k: creds[k] for k in ("username", "password", "port", "allowed_from")}}
            except (OSError, ValueError, KeyError):
                pass
        return {**state, "available": self.enabled()}

    def request(self, panel_host: str, open_firewall: bool = False) -> dict:
        if not self.enabled():
            raise MariaDbSetupMissing(
                "Węzeł nie ma usługi instalacji MariaDB — zaktualizuj go (Administracja → Aktualizacje) i spróbuj ponownie."
            )
        ipaddress.ip_address(panel_host)  # tylko konkretny adres IP — trafia do skryptu roota
        self.dir.mkdir(parents=True, exist_ok=True)
        (self.dir / "status.json").unlink(missing_ok=True)
        (self.dir / "request").write_text(
            json.dumps({"panel_host": panel_host, "open_firewall": bool(open_firewall), "requested_at": int(time.time())}),
            encoding="utf-8",
        )
        return self.status()

    def forget_credentials(self) -> dict:
        (self.dir / "credentials.json").unlink(missing_ok=True)
        return self.status()
