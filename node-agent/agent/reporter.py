"""Odsyłanie wyników zadań do control plane.

Agent nie czeka, aż panel go zapyta. Po zakończeniu zadania próbuje odesłać
wynik; jeśli control plane jest niedostępny, zadanie zostaje w bazie ze
znacznikiem `reported = 0` i wraca w kolejnej turze. Dzięki temu przerwa w
łączności opóźnia aktualizację stanu VPS-a w panelu, ale jej nie gubi.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import threading
import time

import httpx

from .config import Settings
from .jobs import JobQueue

log = logging.getLogger("virthub.reporter")

CALLBACK_PATH = "/api/internal/agent/job-result"
INTERVAL_SECONDS = 5


class CallbackReporter:
    def __init__(self, settings: Settings, jobs: JobQueue):
        self.settings = settings
        self.jobs = jobs
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

    @property
    def enabled(self) -> bool:
        return bool(self.settings.control_plane_url and self.settings.callback_secret)

    def start(self) -> None:
        if not self.enabled:
            log.info(
                "Callback wyłączony (brak VH_CONTROL_PLANE_URL lub VH_CALLBACK_SECRET) — "
                "control plane musi odpytywać GET /jobs/{id} samodzielnie."
            )
            return
        self._thread = threading.Thread(target=self._run, name="virthub-reporter", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        if self._thread:
            self._thread.join(timeout=10)

    def _run(self) -> None:
        while not self._stop.wait(INTERVAL_SECONDS):
            try:
                self._flush()
            except Exception:
                log.exception("Błąd przy odsyłaniu wyników zadań")

    def _flush(self) -> None:
        pending = self.jobs.unreported()
        if not pending:
            return

        url = f"{self.settings.control_plane_url}{CALLBACK_PATH}"
        with httpx.Client(timeout=15) as client:
            for job in pending:
                body = json.dumps(job.model_dump(mode="json"), default=str).encode()
                timestamp = str(int(time.time()))
                canonical = (
                    f"{timestamp}\nPOST\n{CALLBACK_PATH}\n{hashlib.sha256(body).hexdigest()}"
                )
                signature = hmac.new(
                    self.settings.callback_secret.encode(), canonical.encode(), hashlib.sha256
                ).hexdigest()

                try:
                    response = client.post(
                        url,
                        content=body,
                        headers={
                            "Content-Type": "application/json",
                            "X-VH-Timestamp": timestamp,
                            "X-VH-Signature": signature,
                        },
                    )
                except httpx.HTTPError as exc:
                    log.warning("Control plane nieosiągalny (%s) — ponowię za %ss",
                                exc, INTERVAL_SECONDS)
                    return  # nie próbuj kolejnych, sieć i tak leży

                if response.status_code < 300:
                    self.jobs.mark_reported(job.job_id)
                elif response.status_code == 404:
                    # VPS zniknął z panelu (np. usunięty ręcznie) — nie ma sensu
                    # zawracać nim gitary w nieskończoność.
                    log.warning("Control plane nie zna zadania %s — oznaczam jako wysłane",
                                job.job_id)
                    self.jobs.mark_reported(job.job_id)
                else:
                    log.warning(
                        "Control plane odrzucił wynik zadania %s: HTTP %s %s",
                        job.job_id, response.status_code, response.text[:200],
                    )
                    return
