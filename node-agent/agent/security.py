"""Uwierzytelnianie żądań z control plane.

Schemat: HMAC-SHA256 na kanoniczej reprezentacji żądania. Sekret jest
współdzielony (jeden per hypervisor, generowany przy rejestracji node'a).
Znacznik czasu w podpisie chroni przed odtworzeniem (replay) starego żądania.

Kanoniczny string:
    {timestamp}\n{METODA}\n{ścieżka}\n{sha256(body)}

Docelowo (Faza 5, multi-node) ten mechanizm zostaje zastąpiony mTLS —
interfejs `require_control_plane` pozostaje wtedy bez zmian.
"""

from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import Header, HTTPException, Request, status

from .config import get_settings

SIGNATURE_HEADER = "x-vh-signature"
TIMESTAMP_HEADER = "x-vh-timestamp"


def build_canonical_string(timestamp: str, method: str, path: str, body: bytes) -> str:
    body_digest = hashlib.sha256(body).hexdigest()
    return f"{timestamp}\n{method.upper()}\n{path}\n{body_digest}"


def sign(secret: str, timestamp: str, method: str, path: str, body: bytes) -> str:
    canonical = build_canonical_string(timestamp, method, path, body)
    return hmac.new(secret.encode(), canonical.encode(), hashlib.sha256).hexdigest()


async def require_control_plane(
    request: Request,
    x_vh_signature: str = Header(default=""),
    x_vh_timestamp: str = Header(default=""),
) -> None:
    """Zależność FastAPI — odrzuca żądania bez poprawnego podpisu."""
    settings = get_settings()

    if not x_vh_signature or not x_vh_timestamp:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Żądanie musi być podpisane nagłówkami X-VH-Signature i X-VH-Timestamp.",
        )

    try:
        sent_at = int(x_vh_timestamp)
    except ValueError:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="X-VH-Timestamp musi być uniksowym znacznikiem czasu.",
        )

    drift = abs(int(time.time()) - sent_at)
    if drift > settings.max_clock_skew:
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail=(
                f"Znacznik czasu odbiega o {drift}s od zegara hypervisora "
                f"(limit {settings.max_clock_skew}s). Zsynchronizuj zegary przez NTP."
            ),
        )

    body = await request.body()
    expected = sign(
        settings.agent_token,
        x_vh_timestamp,
        request.method,
        request.url.path,
        body,
    )

    if not hmac.compare_digest(expected, x_vh_signature):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Podpis żądania jest nieprawidłowy.",
        )
