"""Raportowanie postępu pobierania: procent, ile już przyszło i prędkość."""

from __future__ import annotations

import time

from .jobs import progress


def human_bytes(value: float) -> str:
    for unit in ("B", "KB", "MB", "GB"):
        if value < 1024 or unit == "GB":
            return f"{value:.0f} {unit}" if unit in ("B", "KB") else f"{value:.1f} {unit}".replace(".", ",")
        value /= 1024
    return f"{value:.1f} GB"


class TransferProgress:
    """Zapisuje postęp co najwyżej raz na `interval` sekund — baza zadań
    nie musi dostawać zapisu przy każdym megabajcie."""

    def __init__(self, stage: str = "download", total: int | None = None, interval: float = 1.0,
                 low: int = 0, high: int = 100):
        self.stage, self.total, self.interval = stage, total, interval
        self.low, self.high = low, high
        self.started = self._last = time.monotonic()
        self.done = 0

    def update(self, done: int, force: bool = False) -> None:
        self.done = done
        now = time.monotonic()
        if not force and now - self._last < self.interval:
            return
        self._last = now
        elapsed = max(now - self.started, 0.001)
        speed = done / elapsed
        if self.total:
            share = min(1.0, done / self.total)
            percent = int(self.low + (self.high - self.low) * share)
            detail = f"{human_bytes(done)} z {human_bytes(self.total)} · {human_bytes(speed)}/s"
        else:
            percent = None
            detail = f"{human_bytes(done)} · {human_bytes(speed)}/s"
        progress(self.stage, percent, detail)
