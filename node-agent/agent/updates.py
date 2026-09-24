"""Zdalna aktualizacja węzła zlecana z panelu.

Agent działa bez uprawnień roota (ProtectSystem=strict) i nie może sam
podmienić swojego kodu ani zrestartować usługi — i dobrze. Zamiast tego
zostawia plik-zlecenie w katalogu stanu, a jednostka systemd
`virthub-agent-update.path` (instalowana przez scripts/apply-update.sh)
uruchamia jako root `virthub-agent-update.service`, czyli
scripts/update-node.sh.

Panel może tylko *wyzwolić* aktualizację. Skąd pobrać kod (repozytorium,
gałąź), decyduje lokalny /etc/virthub-agent/update.env — przejęty panel nie
wskaże węzłowi obcego kodu do uruchomienia jako root.
"""

from __future__ import annotations

import json
import time
from pathlib import Path

from .config import Settings

UPDATER_UNIT = Path("/etc/systemd/system/virthub-agent-update.path")
# Po ilu sekundach nieodebrane zlecenie uznajemy za zawieszone.
STALL_AFTER = 90


class Updates:
    def __init__(self, settings: Settings, unit: Path = UPDATER_UNIT):
        self.dir = settings.state_db.parent / "update"
        self.version_file = Path(__file__).resolve().parents[1] / "VERSION"
        self.unit = unit

    def build(self) -> str | None:
        """Commit, z którego pochodzi kod agenta (zapisany przez aktualizację)."""
        try:
            value = self.version_file.read_text(encoding="utf-8").strip().split()[0]
            return value or None
        except (OSError, IndexError):
            return None

    def enabled(self) -> bool:
        return self.unit.exists()

    def status(self) -> dict:
        state: dict = {"state": "idle"}
        try:
            state = json.loads((self.dir / "status.json").read_text(encoding="utf-8"))
        except (OSError, ValueError):
            pass
        request = self.dir / "request"
        if request.exists() and state.get("state") not in {"running"}:
            # Usługa zdejmuje zlecenie w chwili startu. Jeśli leży dłużej,
            # systemd go nie odebrał — np. jednostka .path jest wyłączona.
            try:
                stalled = time.time() - request.stat().st_mtime > STALL_AFTER
            except OSError:
                stalled = False
            if stalled:
                state = {
                    **state,
                    "state": "stalled",
                    "message": "Usługa aktualizacji nie odebrała zlecenia. Zaktualizuj węzeł raz ręcznie "
                    "(update-node.sh) — naprawi usługę.",
                }
            else:
                state = {**state, "state": "queued"}
        return {**state, "build": self.build(), "remote_update": self.enabled()}

    def request(self) -> dict:
        if not self.enabled():
            raise UpdaterMissing(
                "Zdalna aktualizacja nie jest włączona na tym węźle. Zaktualizuj go raz "
                "ręcznie poleceniem update-node.sh — potem aktualizacje z panelu zadziałają."
            )
        self.dir.mkdir(parents=True, exist_ok=True)
        (self.dir / "request").write_text(json.dumps({"requested_at": int(time.time())}), encoding="utf-8")
        return self.status()


class UpdaterMissing(RuntimeError):
    pass
