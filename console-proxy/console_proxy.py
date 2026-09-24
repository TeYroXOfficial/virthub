"""Przekaźnik konsoli VirtHub — działa na serwerze panelu.

Przeglądarka nie może połączyć się z agentem na węźle bezpośrednio: agent ma
certyfikat przypięty tylko w panelu (przeglądarka mu nie ufa), a każde
żądanie do agenta musi być podpisane sekretem, którego przeglądarka nie może
znać. Dlatego:

    przeglądarka ──wss──▶ nginx panelu ──ws──▶ ten przekaźnik ──wss (przypięty
    certyfikat, podpis HMAC)──▶ agent na węźle ──▶ VNC maszyny / terminal

Przekaźnik nie zna żadnych sekretów węzłów. Przeglądarka przynosi
jednorazowy identyfikator sesji; przekaźnik wymienia go w panelu
(POST /api/internal/console/{sesja}, uwierzytelnione wspólnym sekretem) na
gotowy adres agenta, podpisane nagłówki i przypięty certyfikat, a potem
przepuszcza ramki w obie strony bez zaglądania do nich.

Konfiguracja ze zmiennych środowiskowych (EnvironmentFile usługi):
    VH_PANEL_URL       adres panelu, np. https://panel.example.com
    VH_CONSOLE_SECRET  wspólny sekret z VIRTHUB_CONSOLE_SECRET panelu
    VH_LISTEN_HOST     domyślnie 127.0.0.1 (ruch z zewnątrz wchodzi przez nginx)
    VH_LISTEN_PORT     domyślnie 8090
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re
import ssl
import urllib.error
import urllib.request

from websockets.asyncio.client import connect
from websockets.asyncio.server import ServerConnection, serve
from websockets.exceptions import ConnectionClosed

log = logging.getLogger("virthub.console-proxy")

SESSION_PATH = re.compile(r"^/console-ws/([A-Za-z0-9]{32,128})$")


class SessionError(RuntimeError):
    pass


def redeem(panel_url: str, secret: str, session: str) -> dict:
    """Wymienia jednorazową sesję na parametry połączenia z agentem."""
    request = urllib.request.Request(
        f"{panel_url}/api/internal/console/{session}",
        method="POST",
        headers={"X-Console-Secret": secret, "Accept": "application/json"},
        data=b"",
    )
    try:
        with urllib.request.urlopen(request, timeout=10) as response:
            return json.loads(response.read())
    except urllib.error.HTTPError as exc:
        raise SessionError(f"panel odrzucił sesję ({exc.code})") from exc
    except (urllib.error.URLError, ValueError) as exc:
        raise SessionError(f"brak kontaktu z panelem: {exc}") from exc


def ssl_context(ca_pem: str | None) -> ssl.SSLContext:
    """Przypięty certyfikat węzła albo zwykła weryfikacja urzędami.

    Przy przypięciu nie sprawdzamy nazwy hosta: certyfikat samopodpisany
    wystawiony na adres węzła jest jedynym, któremu ufamy — to mocniejsza
    gwarancja niż zgodność nazwy.
    """
    if not ca_pem:
        return ssl.create_default_context()
    context = ssl.create_default_context(cadata=ca_pem)
    context.check_hostname = False
    return context


async def pipe(source, target) -> None:
    async for message in source:
        await target.send(message)


class Proxy:
    def __init__(self, panel_url: str, secret: str):
        self.panel_url = panel_url.rstrip("/")
        self.secret = secret

    async def handle(self, client: ServerConnection) -> None:
        match = SESSION_PATH.match(client.request.path.split("?", 1)[0])
        if match is None:
            await client.close(1008, "Nieprawidłowy adres konsoli.")
            return

        try:
            params = await asyncio.to_thread(redeem, self.panel_url, self.secret, match.group(1))
        except SessionError as exc:
            log.info("Odrzucono sesję konsoli: %s", exc)
            await client.close(1008, "Sesja konsoli wygasła. Otwórz konsolę ponownie z panelu.")
            return

        subprotocols = ["binary"] if client.subprotocol == "binary" else None
        try:
            async with connect(
                params["url"],
                additional_headers=params.get("headers") or {},
                ssl=ssl_context(params.get("ca_pem")) if params["url"].startswith("wss://") else None,
                subprotocols=subprotocols,
                max_size=None,
                open_timeout=15,
                ping_interval=20,
            ) as agent:
                log.info("Konsola %s maszyny %s otwarta", params.get("kind"), params.get("server_id"))
                tasks = [
                    asyncio.create_task(pipe(client, agent)),
                    asyncio.create_task(pipe(agent, client)),
                ]
                done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
                for task in pending:
                    task.cancel()
                await asyncio.gather(*pending, return_exceptions=True)
        except (OSError, ConnectionClosed, asyncio.TimeoutError) as exc:
            log.warning("Konsola maszyny %s: brak połączenia z węzłem (%s)", params.get("server_id"), exc)
            await client.close(1011, "Nie udało się połączyć z węzłem maszyny.")
            return
        except Exception as exc:  # odrzucony handshake agenta itp.
            log.warning("Konsola maszyny %s: agent odmówił (%s)", params.get("server_id"), exc)
            await client.close(1011, "Węzeł odrzucił połączenie konsoli.")
            return

        await client.close()


def select_subprotocol(connection: ServerConnection, subprotocols) -> str | None:
    """noVNC prosi o „binary", xterm.js o nic — oba przypadki są poprawne.

    Sama lista subprotocols=["binary"] w serve() odrzuciłaby terminal
    (klient bez podprotokołu dostaje 400).
    """
    return "binary" if "binary" in subprotocols else None


async def main() -> None:
    logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)-7s %(name)s: %(message)s")

    panel_url = os.environ.get("VH_PANEL_URL", "").strip()
    secret = os.environ.get("VH_CONSOLE_SECRET", "").strip()
    if not panel_url or not secret:
        raise SystemExit("Ustaw VH_PANEL_URL i VH_CONSOLE_SECRET w /etc/virthub/console-proxy.env")

    proxy = Proxy(panel_url, secret)
    host = os.environ.get("VH_LISTEN_HOST", "127.0.0.1")
    port = int(os.environ.get("VH_LISTEN_PORT", "8090"))

    async with serve(
        proxy.handle, host, port,
        select_subprotocol=select_subprotocol,
        max_size=None,
        ping_interval=20,
    ) as server:
        log.info("Przekaźnik konsoli nasłuchuje na %s:%s", host, port)
        await server.serve_forever()


if __name__ == "__main__":
    asyncio.run(main())
