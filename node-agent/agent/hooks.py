"""Skrypty administratora uruchamiane na węźle (np. przed i po odtworzeniu DR).

Skrypt przychodzi z panelu (personel z uprawnieniami do węzłów — i tak ma pełną
kontrolę nad hostem). Uruchamiamy go w osobnym katalogu tymczasowym, z limitem
czasu i ograniczonym wyjściem; zmienne VH_* opisują zdarzenie.
"""

from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import Any

from .shell import CommandError, run_bounded

MAX_OUTPUT = 64 * 1024


def run_hook(name: str, script: str, env: dict[str, str] | None = None, timeout: int = 900, runner: Any = None) -> dict[str, Any]:
    runner = runner or run_bounded
    with tempfile.TemporaryDirectory(prefix="vh-hook-") as workdir:
        path = Path(workdir) / f"{name}.sh"
        path.write_text(script if script.startswith("#!") else "#!/bin/bash\nset -euo pipefail\n" + script, encoding="utf-8")
        os.chmod(path, 0o700)
        variables = {k: str(v) for k, v in (env or {}).items() if k.startswith("VH_")}
        # env przez /usr/bin/env — run_bounded nie przyjmuje środowiska, a tak zmienne nie trafiają do argumentów skryptu.
        argv = ["/usr/bin/env", *[f"{k}={v}" for k, v in variables.items()], "/bin/bash", str(path)]
        try:
            output = runner(argv, max_bytes=MAX_OUTPUT, timeout=timeout)
        except CommandError as exc:
            return {"name": name, "ok": False, "exit_code": exc.returncode, "output": (exc.stderr or "")[-MAX_OUTPUT:]}
    return {"name": name, "ok": True, "exit_code": 0, "output": output[-MAX_OUTPUT:]}
