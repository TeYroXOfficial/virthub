"""Podpisane żądania agenta do panelu (poza kolejką wyników zadań).

Ten sam podpis co przy wynikach zadań: HMAC-SHA256 sekretem węzła
(`VH_CALLBACK_SECRET`) z `{czas}\\n{METODA}\\n{ścieżka}\\n{sha256(treść)}`.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
from typing import Any

import httpx

from .config import Settings


class PanelUnavailable(RuntimeError):
    pass


def signed_post(settings: Settings, path: str, payload: dict[str, Any], timeout: float = 10) -> httpx.Response:
    if not settings.control_plane_url or not settings.callback_secret:
        raise PanelUnavailable("brak VH_CONTROL_PLANE_URL albo VH_CALLBACK_SECRET")
    body = json.dumps(payload).encode()
    timestamp = str(int(time.time()))
    canonical = f"{timestamp}\nPOST\n{path}\n{hashlib.sha256(body).hexdigest()}"
    signature = hmac.new(settings.callback_secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()
    try:
        return httpx.post(
            f"{settings.control_plane_url}{path}",
            content=body,
            headers={"Content-Type": "application/json", "Accept": "application/json",
                     "X-VH-Timestamp": timestamp, "X-VH-Signature": signature},
            timeout=timeout,
        )
    except httpx.HTTPError as exc:
        raise PanelUnavailable(str(exc)) from exc
