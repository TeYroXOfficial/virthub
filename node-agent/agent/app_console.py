"""Konsola aplikacji na żywo przez WebSocket (jak konsola w Wings).

Po połączeniu agent wysyła zaległe linie (log instalacji albo koniec logu
kontenera), a potem strumieniuje nowe wyjście w miarę, jak się pojawia —
bez odpytywania przez panel. Przeżywa restarty: gdy kontener staje, konsola
czeka, aż znów wystartuje, i podejmuje strumień od ostatniej linii.

Protokół (przekaźnik panelu przepuszcza ramki bez zmian):
* agent → przeglądarka, ramka binarna — wyjście konsoli (UTF-8, końce linii \\r\\n,
  gotowe dla xterm.js),
* agent → przeglądarka, ramka tekstowa — JSON: {"type": "status", ...} co ~2 s
  (stan, CPU, RAM, dysk, sieć) albo {"type": "error", "message": ...},
* przeglądarka → agent, ramka tekstowa — {"type": "command", "command": "say hej"}.

Przyciski zasilania idą dalej zwykłym żądaniem do panelu: tam jest
autoryzacja i wysłanie aktualnej konfiguracji przed startem.
"""

from __future__ import annotations

import asyncio
import json
import logging
import threading
from typing import Any

from starlette.websockets import WebSocket

from .apps import AppError, AppManager, _parse_ts
from .console import _close, _race

log = logging.getLogger("virthub.app-console")

BACKLOG = 150
STATUS_EVERY = 2.0
MAX_COMMAND = 1000


def _line(text: str) -> bytes:
    return (text.rstrip("\r\n") + "\r\n").encode("utf-8", errors="replace")


class AppConsole:
    def __init__(self, websocket: WebSocket, manager: AppManager, uuid: str):
        self.ws = websocket
        self.apps = manager
        self.uuid = uuid
        self.cursor: float | None = None  # znacznik czasu ostatniej wysłanej linii kontenera
        self._stream: Any = None
        self._lock = threading.Lock()

    async def run(self) -> None:
        try:
            await _race(self._output(), self._input(), self._status())
        finally:
            self._close_stream()
            await _close(self.ws)

    # --- wyjście ------------------------------------------------------------------

    async def _output(self) -> None:
        backlog = await asyncio.to_thread(self.apps.logs, self.uuid, None, BACKLOG)
        if backlog["lines"]:
            await self.ws.send_bytes(b"".join(_line(l) for l in backlog["lines"]))
        self.cursor = backlog.get("cursor")
        install_offset: int | None = None
        if backlog["source"] == "install":
            path = self.apps.install_log_file(self.uuid)
            install_offset = path.stat().st_size if path.exists() else 0

        while True:
            state = await asyncio.to_thread(self._state)
            if state == "installing":
                install_offset = await self._tail_install(install_offset)
            elif state == "running":
                install_offset = None
                await self._follow()
            else:
                install_offset = None if state != "missing" else install_offset
                await asyncio.sleep(1)

    def _state(self) -> str:
        if self.uuid in self.apps._installing:
            return "installing"
        container = self.apps._container(self.uuid)
        if container is None:
            return "missing"
        container.reload()
        return "running" if container.status in ("running", "restarting") else "offline"

    async def _tail_install(self, offset: int | None) -> int:
        """Log instalatora rośnie w pliku — dosyłamy przyrost co pół sekundy."""
        path = self.apps.install_log_file(self.uuid)
        if offset is None:
            offset = 0
        await asyncio.sleep(0.5)
        try:
            size = path.stat().st_size
        except FileNotFoundError:
            return 0
        if size < offset:  # nowa instalacja nadpisała plik
            offset = 0
        if size > offset:
            with path.open("rb") as fh:
                fh.seek(offset)
                data = fh.read(size - offset)
            offset = size
            await self.ws.send_bytes(data.replace(b"\r\n", b"\n").replace(b"\n", b"\r\n"))
        return offset

    async def _follow(self) -> None:
        """Strumień logów działającego kontenera; kończy się, gdy kontener stanie."""
        loop = asyncio.get_running_loop()
        queue: asyncio.Queue[bytes | None] = asyncio.Queue()

        def reader() -> None:
            try:
                container = self.apps._container(self.uuid)
                if container is None:
                    return
                kwargs: dict[str, Any] = {"stream": True, "follow": True, "timestamps": True}
                if self.cursor:
                    kwargs["since"] = self.cursor
                else:
                    kwargs["tail"] = 0
                stream = container.logs(**kwargs)
                with self._lock:
                    self._stream = stream
                for chunk in stream:
                    loop.call_soon_threadsafe(queue.put_nowait, chunk)
            except Exception as exc:  # zamknięty strumień, zniknięty kontener
                log.debug("Strumień logów %s zakończony: %s", self.uuid, exc)
            finally:
                with self._lock:
                    self._stream = None
                loop.call_soon_threadsafe(queue.put_nowait, None)

        thread = threading.Thread(target=reader, name=f"app-logs-{self.uuid[:8]}", daemon=True)
        thread.start()
        buffer = b""
        try:
            while (chunk := await queue.get()) is not None:
                buffer += chunk
                *lines, buffer = buffer.split(b"\n")
                out = self._render(lines)
                if out:
                    await self.ws.send_bytes(out)
        finally:
            self._close_stream()
        if buffer:
            out = self._render([buffer])
            if out:
                await self.ws.send_bytes(out)
        await asyncio.sleep(0.5)

    def _render(self, lines: list[bytes]) -> bytes:
        out = []
        for raw in lines:
            line = raw.decode("utf-8", errors="replace")
            stamp, _, text = line.partition(" ")
            ts = _parse_ts(stamp)
            if ts is None:
                out.append(_line(line))
                continue
            if self.cursor and ts <= self.cursor:
                continue  # docker `since` ma sekundową granulację — pomijamy powtórki
            self.cursor = ts
            out.append(_line(text))
        return b"".join(out)

    def _close_stream(self) -> None:
        with self._lock:
            stream, self._stream = self._stream, None
        if stream is not None:
            try:
                stream.close()
            except Exception:
                pass

    # --- wejście i stan -----------------------------------------------------------------

    async def _input(self) -> None:
        while True:
            message = await self.ws.receive()
            if message["type"] == "websocket.disconnect":
                return
            text = message.get("text")
            if not text:
                continue
            try:
                data = json.loads(text)
            except ValueError:
                continue
            if not isinstance(data, dict) or data.get("type") != "command":
                continue
            command = str(data.get("command") or "").replace("\r", "").replace("\n", " ").strip()
            if not command or len(command) > MAX_COMMAND:
                continue
            try:
                await asyncio.to_thread(self.apps.command, self.uuid, command)
            except AppError as exc:
                await self.ws.send_text(json.dumps({"type": "error", "message": str(exc)}))

    async def _status(self) -> None:
        while True:
            try:
                status = await asyncio.to_thread(self.apps.status, self.uuid)
            except AppError as exc:
                status = {"state": "missing", "error": str(exc)}
            await self.ws.send_text(json.dumps({"type": "status", **status}))
            await asyncio.sleep(STATUS_EVERY)


async def bridge_app(websocket: WebSocket, manager: AppManager, uuid: str) -> None:
    await AppConsole(websocket, manager, uuid).run()
