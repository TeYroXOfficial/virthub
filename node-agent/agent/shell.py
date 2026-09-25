"""Uruchamianie poleceń systemowych hosta.

Wszystkie wywołania zewnętrznych narzędzi (qemu-img, nft, ip, genisoimage)
przechodzą przez to miejsce: jeden format błędu, jeden log, jedno miejsce do
podmiany w testach. Nigdy nie używamy shell=True — argumenty idą listą, więc
nazwa hosta czy szablonu pochodząca z API nie może wstrzyknąć polecenia.
"""

from __future__ import annotations

import logging
import shutil
import subprocess
import time

log = logging.getLogger("virthub.shell")


class CommandError(RuntimeError):
    def __init__(self, argv: list[str], returncode: int, stderr: str):
        self.argv = argv
        self.returncode = returncode
        self.stderr = stderr.strip()
        super().__init__(
            f"Polecenie `{' '.join(argv)}` zakończyło się kodem {returncode}: {self.stderr}"
        )


def which(binary: str) -> str | None:
    return shutil.which(binary)


def run_bounded(argv: list[str], *, max_bytes: int, timeout: int = 60, input: str | None = None) -> str:
    """Jak run(), ale czyta najwyżej `max_bytes` wyjścia i pilnuje czasu.

    Dla poleceń, których wynik pochodzi z wnętrza maszyny klienta (plik z
    kontenera, program uruchomiony w kontenerze): klient mógłby podstawić
    plik albo program wypisujący gigabajty, zapychający stderr albo nigdy
    niekończący wyjścia — i zablokować lub zapchać pamięć agenta.
    """
    import selectors

    log.debug("exec (limit %s B): %s", max_bytes, " ".join(argv))
    try:
        proc = subprocess.Popen(
            argv, stdin=subprocess.PIPE if input is not None else subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
    except FileNotFoundError as exc:
        raise CommandError(argv, 127, f"Nie znaleziono programu: {argv[0]}") from exc

    if input is not None:
        try:
            proc.stdin.write(input.encode())
        finally:
            proc.stdin.close()

    out, err = bytearray(), bytearray()
    deadline = time.monotonic() + timeout
    sel = selectors.DefaultSelector()
    sel.register(proc.stdout, selectors.EVENT_READ, out)
    sel.register(proc.stderr, selectors.EVENT_READ, err)
    try:
        while sel.get_map():
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                raise CommandError(argv, 124, f"Przekroczono limit czasu {timeout}s")
            for key, _ in sel.select(timeout=remaining):
                chunk = key.fileobj.read1(65536)
                if not chunk:
                    sel.unregister(key.fileobj)
                    continue
                if key.data is out:
                    out += chunk
                    if len(out) > max_bytes:
                        raise CommandError(argv, 1, f"Wynik przekracza limit {max_bytes} B — przerwano.")
                elif len(err) < 8192:  # stderr czytamy do końca, zachowujemy początek
                    err += chunk[: 8192 - len(err)]
        code = proc.wait(timeout=max(1, deadline - time.monotonic()))
    except (CommandError, subprocess.TimeoutExpired) as exc:
        proc.kill()
        proc.wait(timeout=5)
        if isinstance(exc, CommandError):
            raise
        raise CommandError(argv, 124, f"Przekroczono limit czasu {timeout}s") from exc
    finally:
        sel.close()
        proc.stdout.close()
        proc.stderr.close()

    if code != 0:
        raise CommandError(argv, code, err.decode(errors="replace"))
    return out.decode(errors="replace")


def run(argv: list[str], *, timeout: int = 300, check: bool = True, input: str | None = None) -> str:
    """Uruchamia polecenie i zwraca stdout. Rzuca CommandError przy niezerowym kodzie."""
    log.debug("exec: %s", " ".join(argv))
    try:
        proc = subprocess.run(
            argv,
            input=input,
            capture_output=True,
            text=True,
            timeout=timeout,
            check=False,
        )
    except FileNotFoundError as exc:
        raise CommandError(argv, 127, f"Nie znaleziono programu: {argv[0]}") from exc
    except subprocess.TimeoutExpired as exc:
        raise CommandError(argv, 124, f"Przekroczono limit czasu {timeout}s") from exc

    if check and proc.returncode != 0:
        raise CommandError(argv, proc.returncode, proc.stderr)

    return proc.stdout
