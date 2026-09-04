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


def run(argv: list[str], *, timeout: int = 300, check: bool = True) -> str:
    """Uruchamia polecenie i zwraca stdout. Rzuca CommandError przy niezerowym kodzie."""
    log.debug("exec: %s", " ".join(argv))
    try:
        proc = subprocess.run(
            argv,
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
