"""ISO klienta (adres z zewnątrz): ochrona sieci węzła i limit rozmiaru."""

from __future__ import annotations

import hashlib
import http.server
import socket
import threading

import pytest

from agent import isos as isos_module
from agent.isos import IsoError, IsoLibrary, check_public_url


@pytest.fixture()
def server(tmp_path):
    payload = b"ISO" * 5000
    (tmp_path / "x.iso").write_bytes(payload)

    class Handler(http.server.SimpleHTTPRequestHandler):
        def __init__(self, *a, **kw):
            super().__init__(*a, directory=str(tmp_path), **kw)

        def do_GET(self):
            if self.path == "/redirect-private":
                self.send_response(302)
                self.send_header("Location", "http://10.0.0.1/secret.iso")
                self.end_headers()
                return
            super().do_GET()

        def log_message(self, *args):
            pass

    httpd = http.server.ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    yield httpd.server_address[1], hashlib.sha256(payload).hexdigest(), len(payload)
    httpd.shutdown()


def _pretend_public(monkeypatch, port):
    """Host „public.test” rozwiązuje się na publiczny adres, ale łączymy z lokalnym serwerem testowym."""
    real = socket.getaddrinfo

    def fake(host, *args, **kwargs):
        if host == "public.test":
            return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.34", port))]
        return real(host, *args, **kwargs)

    monkeypatch.setattr(isos_module.socket, "getaddrinfo", fake)
    original = isos_module.urllib.request.OpenerDirector.open

    def open_local(self, req, *a, **kw):
        url = req.full_url if hasattr(req, "full_url") else req
        return original(self, url.replace("public.test", "127.0.0.1"), *a, **kw)

    monkeypatch.setattr(isos_module.urllib.request.OpenerDirector, "open", open_local)


@pytest.mark.parametrize("url", [
    "http://127.0.0.1/x.iso", "http://localhost/x.iso", "http://169.254.169.254/latest", "http://10.1.2.3/a.iso",
    "http://[::1]/a.iso", "ftp://example.com/a.iso", "file:///etc/passwd",
])
def test_adresy_sieci_wezla_sa_odrzucane(url):
    with pytest.raises(IsoError):
        check_public_url(url)


def test_pobranie_z_publicznego_adresu(settings, tmp_path, server, monkeypatch):
    port, sha, size = server
    _pretend_public(monkeypatch, port)
    lib = IsoLibrary(settings)
    lib.dir = tmp_path / "lib"

    result = lib.download("user-1-x.iso", f"http://public.test:{port}/x.iso", sha, untrusted=True, max_gb=1)
    assert result["size_bytes"] == size


def test_przekierowanie_do_sieci_prywatnej_odrzucone(settings, tmp_path, server, monkeypatch):
    port, _, _ = server
    _pretend_public(monkeypatch, port)
    lib = IsoLibrary(settings)
    lib.dir = tmp_path / "lib"

    with pytest.raises(IsoError, match="prywatną"):
        lib.download("user-1-x.iso", f"http://public.test:{port}/redirect-private", untrusted=True)
    assert not (lib.dir / "user-1-x.iso").exists()


def test_bezposrednio_lokalny_adres_odrzucony(settings, tmp_path, server):
    port, _, _ = server
    lib = IsoLibrary(settings)
    lib.dir = tmp_path / "lib"
    with pytest.raises(IsoError):
        lib.download("user-1-x.iso", f"http://127.0.0.1:{port}/x.iso", untrusted=True)


def test_limit_rozmiaru_klienta(settings, tmp_path, server, monkeypatch):
    port, _, _ = server
    _pretend_public(monkeypatch, port)
    monkeypatch.setattr(isos_module, "MAX_BYTES", 1000)
    lib = IsoLibrary(settings)
    lib.dir = tmp_path / "lib"
    with pytest.raises(IsoError, match="limit"):
        lib.download("user-1-x.iso", f"http://public.test:{port}/x.iso", untrusted=True, max_gb=1)
    assert list(lib.dir.iterdir()) == []
