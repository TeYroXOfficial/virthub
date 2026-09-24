"""Wspólna konfiguracja testów agenta.

Testy działają w trybie mock, więc przechodzą na dowolnej maszynie — także na
Windows i w CI, gdzie nie ma ani KVM, ani libvirt.
"""

from __future__ import annotations

import os
import sys
import tempfile
from pathlib import Path

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

TEST_TOKEN = "test-token-nie-uzywac-w-produkcji"

_tmp = tempfile.mkdtemp(prefix="virthub-tests-")

os.environ.update(
    VH_AGENT_TOKEN=TEST_TOKEN,
    VH_AGENT_DRIVER="mock",
    VH_IMAGE_DIR=str(Path(_tmp) / "images"),
    VH_TEMPLATE_DIR=str(Path(_tmp) / "templates"),
    VH_SEED_DIR=str(Path(_tmp) / "seeds"),
    VH_ISO_DIR=str(Path(_tmp) / "isos"),
    VH_STATE_DB=str(Path(_tmp) / "state.sqlite3"),
    VH_CONTROL_PLANE_URL="",
    VH_CALLBACK_SECRET="",
)


@pytest.fixture(scope="session")
def settings():
    from agent.config import get_settings

    settings = get_settings()
    settings.ensure_directories()
    return settings


@pytest.fixture()
def client(settings):
    """Klient HTTP z automatycznym podpisywaniem żądań."""
    from fastapi.testclient import TestClient

    from agent.main import app
    from agent.security import sign

    import json as _json
    import time as _time

    class SignedClient(TestClient):
        """Podpisuje żądania tak, jak robi to control plane.

        Nagłówki podane jawnie przez test mają pierwszeństwo — inaczej nie dałoby
        się przetestować odrzucenia złego podpisu.
        """

        def request(self, method, url, **kwargs):  # type: ignore[override]
            # TestClient przekazuje nieużyte argumenty jako None, nie pomija ich.
            headers = dict(kwargs.get("headers") or {})

            body = b""
            if kwargs.get("json") is not None:
                body = _json.dumps(kwargs.pop("json")).encode()
                kwargs["content"] = body
                headers.setdefault("Content-Type", "application/json")
            elif kwargs.get("content"):
                body = kwargs["content"]

            if "X-VH-Signature" not in headers:
                timestamp = headers.get("X-VH-Timestamp") or str(int(_time.time()))
                path = url if url.startswith("/") else f"/{url}"
                headers["X-VH-Timestamp"] = timestamp
                headers["X-VH-Signature"] = sign(TEST_TOKEN, timestamp, method, path, body)

            kwargs["headers"] = headers
            return super().request(method, url, **kwargs)

    with SignedClient(app) as test_client:
        yield test_client


@pytest.fixture()
def template(settings):
    """Bazowy obraz szablonu — w trybie mock wystarczy plik o właściwej nazwie."""
    path = settings.template_dir / "ubuntu-24.04.qcow2"
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text("mock template image\n", encoding="utf-8")
    return path.name
