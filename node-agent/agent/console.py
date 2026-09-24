"""Konsola maszyny przez WebSocket.

Dwa rodzaje, zależnie od wirtualizacji:

* maszyna KVM — ekran graficzny. Agent przepuszcza surowy strumień RFB między
  WebSocketem a gniazdem VNC QEMU (nasłuchującym tylko na 127.0.0.1). Protokół
  VNC, łącznie z hasłem, obsługuje przeglądarka (noVNC) — agent jest rurą.
* kontener LXC — terminal. Agent uruchamia `incus exec -t … login` na
  pseudoterminalu i przepuszcza bajty do xterm.js.

Protokół terminala: ramki binarne to dane (w obie strony), ramki tekstowe od
przeglądarki to sterowanie w JSON, np. {"type": "resize", "cols": 120, "rows": 40}.

Połączenie jest podpisane tak samo jak każde inne żądanie do agenta
(nagłówki X-VH-*, metoda GET, pusta treść). Przeglądarka nigdy nie rozmawia z
agentem bezpośrednio — robi to przekaźnik konsoli na serwerze panelu.
"""

from __future__ import annotations

import asyncio
import fcntl
import json
import logging
import os
import pty
import signal
import struct
import subprocess
import sys
import termios
from dataclasses import dataclass, field

from starlette.websockets import WebSocket, WebSocketDisconnect

log = logging.getLogger("virthub.console")

# Blok odczytu z VNC/pty. 64 KiB mieści pełną aktualizację fragmentu ekranu
# bez rozbijania jej na dziesiątki ramek WebSocket.
CHUNK = 65536


@dataclass(frozen=True)
class VncTarget:
    """Gniazdo VNC maszyny na hoście."""

    host: str
    port: int
    kind: str = "vnc"


@dataclass(frozen=True)
class TerminalTarget:
    """Polecenie uruchamiane na pseudoterminalu."""

    argv: list[str]
    env: dict[str, str] = field(default_factory=dict)
    kind: str = "terminal"


ConsoleTarget = VncTarget | TerminalTarget


async def bridge(websocket: WebSocket, target: ConsoleTarget) -> None:
    if isinstance(target, VncTarget):
        await bridge_vnc(websocket, target)
    else:
        await bridge_terminal(websocket, target)


# --- VNC ----------------------------------------------------------------------

async def bridge_vnc(websocket: WebSocket, target: VncTarget) -> None:
    try:
        reader, writer = await asyncio.open_connection(target.host, target.port)
    except OSError as exc:
        await websocket.close(code=1011, reason=f"Brak połączenia z VNC maszyny: {exc.strerror}")
        return

    async def vnc_to_ws() -> None:
        while data := await reader.read(CHUNK):
            await websocket.send_bytes(data)

    async def ws_to_vnc() -> None:
        while True:
            message = await websocket.receive()
            if message["type"] == "websocket.disconnect":
                return
            data = message.get("bytes")
            if data is None and message.get("text") is not None:
                data = message["text"].encode()
            if data:
                writer.write(data)
                await writer.drain()

    try:
        await _race(vnc_to_ws(), ws_to_vnc())
    finally:
        writer.close()
        await _close(websocket)


# --- terminal -------------------------------------------------------------------

def _set_winsize(fd: int, cols: int, rows: int) -> None:
    cols = max(10, min(int(cols), 500))
    rows = max(5, min(int(rows), 200))
    fcntl.ioctl(fd, termios.TIOCSWINSZ, struct.pack("HHHH", rows, cols, 0, 0))


# Nowa sesja z pseudoterminalem jako terminalem sterującym — dzięki temu zmiana
# rozmiaru okna dociera do programu jako SIGWINCH, a Ctrl+C działa jak w
# zwykłym terminalu. Robi to krótki wrapper, a nie preexec_fn: uvloop (pętla
# uvicorna w produkcji) wykonuje preexec_fn przed setsid() i TIOCSCTTY by padło.
_CTTY_WRAPPER = (
    "import fcntl, os, sys, termios\n"
    "os.setsid()\n"
    "fcntl.ioctl(0, termios.TIOCSCTTY, 0)\n"
    "os.execvp(sys.argv[1], sys.argv[1:])\n"
)


async def bridge_terminal(websocket: WebSocket, target: TerminalTarget) -> None:
    master, slave = pty.openpty()
    _set_winsize(master, 80, 24)

    env = {**os.environ, "TERM": "xterm-256color", **target.env}
    try:
        proc = await asyncio.create_subprocess_exec(
            sys.executable, "-c", _CTTY_WRAPPER, *target.argv,
            stdin=slave, stdout=slave, stderr=slave,
            env=env,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        os.close(master)
        os.close(slave)
        await websocket.close(code=1011, reason=f"Nie udało się uruchomić konsoli: {exc}"[:120])
        return
    finally:
        # Deskryptor podrzędny ma już proces potomny — nasza kopia tylko
        # blokowałaby EOF po jego zakończeniu.
        try:
            os.close(slave)
        except OSError:
            pass

    loop = asyncio.get_running_loop()
    output: asyncio.Queue[bytes | None] = asyncio.Queue()

    def on_readable() -> None:
        try:
            data = os.read(master, CHUNK)
        except OSError:
            data = b""
        if not data:
            loop.remove_reader(master)
            output.put_nowait(None)
        else:
            output.put_nowait(data)

    loop.add_reader(master, on_readable)

    async def pty_to_ws() -> None:
        while (data := await output.get()) is not None:
            await websocket.send_bytes(data)

    async def ws_to_pty() -> None:
        while True:
            message = await websocket.receive()
            if message["type"] == "websocket.disconnect":
                return
            if message.get("bytes"):
                os.write(master, message["bytes"])
            elif message.get("text"):
                _handle_control(master, message["text"])

    try:
        await _race(pty_to_ws(), ws_to_pty(), proc.wait())
    finally:
        loop.remove_reader(master)
        if proc.returncode is None:
            try:
                os.killpg(proc.pid, signal.SIGHUP)
            except (ProcessLookupError, PermissionError):
                proc.kill()
            try:
                await asyncio.wait_for(proc.wait(), timeout=5)
            except asyncio.TimeoutError:
                try:
                    os.killpg(proc.pid, signal.SIGKILL)
                except (ProcessLookupError, PermissionError):
                    proc.kill()
        os.close(master)
        await _close(websocket)


def _handle_control(master: int, text: str) -> None:
    try:
        message = json.loads(text)
    except ValueError:
        return
    if isinstance(message, dict) and message.get("type") == "resize":
        try:
            _set_winsize(master, message.get("cols", 80), message.get("rows", 24))
        except (TypeError, ValueError, OSError):
            pass


# --- pomocnicze ---------------------------------------------------------------

async def _race(*coroutines) -> None:
    """Czeka, aż skończy się którakolwiek strona, i zamyka resztę."""
    tasks = [asyncio.ensure_future(c) for c in coroutines]
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
        for task in done:
            exc = task.exception()
            if exc is not None and not isinstance(exc, (WebSocketDisconnect, ConnectionError)):
                log.warning("Konsola zakończona błędem: %s", exc)
    finally:
        for task in tasks:
            task.cancel()
        await asyncio.gather(*tasks, return_exceptions=True)


async def _close(websocket: WebSocket) -> None:
    try:
        await websocket.close()
    except (RuntimeError, WebSocketDisconnect):
        pass  # już zamknięty przez drugą stronę
