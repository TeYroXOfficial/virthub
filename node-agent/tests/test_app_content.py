"""Instalacja treści aplikacji: pobieranie z weryfikacją, archiwa, limit dysku, SSRF."""

from __future__ import annotations

import dataclasses
import hashlib
import io
import threading
import zipfile
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import pytest

from agent.app_content import AppContentRequest, ContentError, check_url
from agent.apps import AppError, AppManager
from agent.schemas import AppSpec

UUID = "7d1e4a1c-0000-4000-8000-00000000c0de"


def _zip(entries: dict[str, bytes]) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        for name, data in entries.items():
            z.writestr(name, data)
    return buf.getvalue()


FILES = {
    "/mods/a.jar": b"A" * 5000,
    "/mods/b.jar": b"B" * 7000,
    "/pack.mrpack": _zip({
        "modrinth.index.json": b"{}",
        "overrides/config/a.toml": b"a=1",
        "server-overrides/server.properties": b"motd=pack",
        "client-overrides/options.txt": b"klient",
    }),
    "/serverpack.zip": _zip({
        "Pack 1.0/mods/c.jar": b"C",
        "Pack 1.0/config/c.toml": b"c=1",
        "Pack 1.0/start.bat": b"@echo",
    }),
    "/evil.zip": _zip({"../../etc/zlo": b"x"}),
    "/big.bin": b"Z" * (3 * 1024 * 1024),
}


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        if self.path == "/redirect-private":
            self.send_response(302)
            self.send_header("Location", "http://10.0.0.1/secret")
            self.end_headers()
            return
        data = FILES.get(self.path)
        if data is None:
            self.send_response(404)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass


@pytest.fixture(scope="module")
def server():
    httpd = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    threading.Thread(target=httpd.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{httpd.server_port}"
    httpd.shutdown()


class NoContainers:
    """Docker bez kontenerów — aplikacja jeszcze nie była uruchomiona."""

    class containers:  # noqa: N801
        @staticmethod
        def get(name):
            import docker.errors

            raise docker.errors.NotFound(name)


@pytest.fixture()
def manager(settings, tmp_path):
    mgr = AppManager(dataclasses.replace(settings, apps_dir=tmp_path / "apps", apps_content_allow_private=True), client=NoContainers())
    mgr.data_dir(UUID).mkdir(parents=True)
    mgr.save_spec(AppSpec(uuid=UUID, image="ghcr.io/pterodactyl/yolks:java_21", startup="bash start.sh", memory_mb=1024, disk_mb=2))
    return mgr


def sha1(data: bytes) -> str:
    return hashlib.sha1(data).hexdigest()


def run(manager, steps, **kw):
    return manager.content(UUID, AppContentRequest(steps=steps, **kw))


def test_pobieranie_z_weryfikacja_i_log(manager, server):
    result = run(manager, [
        {"op": "download", "url": f"{server}/mods/a.jar", "path": "mods/a.jar", "sha1": sha1(FILES["/mods/a.jar"]), "size": 5000},
        {"op": "download", "url": f"{server}/mods/b.jar", "path": "mods/b.jar",
         "sha512": hashlib.sha512(FILES["/mods/b.jar"]).hexdigest(), "sha256": hashlib.sha256(FILES["/mods/b.jar"]).hexdigest()},
        {"op": "write", "path": "start.sh", "content": "#!/bin/bash\necho start\n", "executable": True},
    ], exclusive=True, label="Modpack Test 1.0")
    root = manager.data_dir(UUID)
    assert (root / "mods/a.jar").read_bytes() == FILES["/mods/a.jar"]
    assert (root / "mods/b.jar").stat().st_size == 7000
    assert (root / "start.sh").stat().st_mode & 0o111
    assert result["files"] == 2
    log = manager.install_log_file(UUID).read_text()
    assert "Modpack Test 1.0" in log and "Gotowe: 2 plików" in log
    assert not list(root.glob("mods/.*.vhpart"))


def test_zla_suma_nie_zostawia_pliku(manager, server):
    (manager.data_dir(UUID) / "mods").mkdir()
    (manager.data_dir(UUID) / "mods/a.jar").write_bytes(b"stary")
    with pytest.raises(ContentError, match="SHA-1"):
        run(manager, [{"op": "download", "url": f"{server}/mods/a.jar", "path": "mods/a.jar", "sha1": "0" * 40}])
    assert (manager.data_dir(UUID) / "mods/a.jar").read_bytes() == b"stary"
    assert not list(manager.data_dir(UUID).glob("mods/.*.vhpart"))


def test_rozpakowanie_mrpack_i_server_packa(manager, server):
    run(manager, [
        {"op": "extract", "url": f"{server}/pack.mrpack", "sha1": sha1(FILES["/pack.mrpack"]),
         "prefixes": {"overrides/": "", "server-overrides/": ""}},
        {"op": "extract", "url": f"{server}/serverpack.zip", "strip_root": True, "skip": ["start.bat"]},
    ])
    root = manager.data_dir(UUID)
    assert (root / "config/a.toml").read_text() == "a=1"
    assert (root / "server.properties").read_text() == "motd=pack"
    assert not (root / "options.txt").exists() and not (root / "client-overrides").exists()
    assert not (root / "modrinth.index.json").exists()
    assert (root / "mods/c.jar").read_bytes() == b"C" and (root / "config/c.toml").exists()
    assert not (root / "start.bat").exists()


def test_archiwum_nie_wyjdzie_poza_katalog(manager, server):
    with pytest.raises(AppError):
        run(manager, [{"op": "extract", "url": f"{server}/evil.zip"}])
    assert not (manager.data_dir(UUID).parent / "etc").exists()


def test_limit_dysku(manager, server):
    with pytest.raises(ContentError, match="limicie dysku"):
        run(manager, [{"op": "download", "url": f"{server}/big.bin", "path": "big.bin"}])
    assert not (manager.data_dir(UUID) / "big.bin").exists()


def test_usuwanie_starych_modow(manager, server):
    root = manager.data_dir(UUID)
    (root / "mods").mkdir()
    (root / "mods/old.jar").write_bytes(b"x")
    (root / "world").mkdir()
    (root / "forge-1.12.2-14.23.5.2859.jar").write_bytes(b"x")
    (root / "forge-installer.jar.log").write_bytes(b"x")
    run(manager, [{"op": "delete", "paths": ["mods", "config", "nie/istnieje", "forge-*.jar", "brak/*.jar"]}])
    assert not (root / "mods").exists() and (root / "world").exists()
    assert not (root / "forge-1.12.2-14.23.5.2859.jar").exists() and (root / "forge-installer.jar.log").exists()


def test_adresy_prywatne_i_http_sa_odrzucane(manager, server):
    with pytest.raises(ContentError, match="https"):
        check_url("http://cdn.modrinth.com/x.jar")
    with pytest.raises(ContentError, match="prywatn"):
        check_url("https://127.0.0.1/x")
    with pytest.raises(ContentError, match="prywatn"):
        check_url("https://169.254.169.254/latest/meta-data")
    # przekierowanie z dozwolonego serwera w sieć prywatną też jest sprawdzane
    manager.settings = dataclasses.replace(manager.settings, apps_content_allow_private=False)
    with pytest.raises(ContentError):
        run(manager, [{"op": "download", "url": f"{server}/redirect-private", "path": "x"}])


def test_instalacja_blokuje_aplikacje_na_czas_trwania(manager, server):
    seen = {}
    original = manager.files

    def spy(uuid):
        seen["installing"] = manager.status.__self__._installing.copy()
        return original(uuid)

    manager.files = spy
    run(manager, [{"op": "write", "path": "a.txt", "content": "x"}], exclusive=True)
    assert UUID in seen["installing"]
    assert UUID not in manager._installing


def test_sprawdzony_host_nie_pyta_dns_przy_kazdym_pliku(monkeypatch):
    import agent.app_content as content

    calls = []

    def fake_getaddrinfo(host, *args, **kwargs):
        calls.append(host)
        return [(0, 0, 0, "", ("104.18.22.35", 443))]

    monkeypatch.setattr(content.socket, "getaddrinfo", fake_getaddrinfo)
    monkeypatch.setattr(content, "_checked_hosts", {})
    for i in range(50):
        check_url(f"https://cdn.modrinth.com/data/{i}.jar")
    check_url("https://edge.forgecdn.net/x.jar")
    assert calls == ["cdn.modrinth.com", "edge.forgecdn.net"]
