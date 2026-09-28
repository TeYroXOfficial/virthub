"""Aplikacje (odpowiednik Wings): pliki, pliki konfiguracyjne i pełny cykl na Dockerze.

Testy integracyjne uruchamiają prawdziwe kontenery z obrazami Pterodactyla —
bez Dockera na stacji testowej są pomijane.
"""

from __future__ import annotations

import base64
import dataclasses
import io
import json
import os
import tarfile
import time
import uuid as uuidlib
import zipfile

import pytest

from agent.apps import AppError, AppFiles, AppManager, apply_config_file
from agent.schemas import AppConfigFile, AppSpec


# --- pliki -------------------------------------------------------------------------

@pytest.fixture()
def files(tmp_path):
    root = tmp_path / "app"
    root.mkdir()
    return AppFiles(root)


def test_zapis_odczyt_lista_i_katalogi(files):
    files.write("config/server.properties", b"motd=hello\n")
    files.mkdir("plugins")

    assert files.read("config/server.properties") == b"motd=hello\n"
    names = [(e["name"], e["directory"]) for e in files.list("")]
    assert names == [("config", True), ("plugins", True)], "katalogi pierwsze"

    files.rename("config/server.properties", "server.properties")
    files.delete("config")
    assert [e["name"] for e in files.list("")] == ["plugins", "server.properties"]


@pytest.mark.parametrize("path", ["../etc/passwd", "a/../../x", "..", "plugins/../../x"])
def test_sciezka_nie_wychodzi_poza_katalog(files, path):
    with pytest.raises(AppError):
        files.read(path)
    with pytest.raises(AppError):
        files.write(path, b"x")


def test_dowiazanie_symboliczne_nie_wyprowadza_poza_katalog(files, tmp_path):
    secret = tmp_path / "secret.txt"
    secret.write_text("token")
    os.symlink(secret, files.root / "link")
    os.symlink(tmp_path, files.root / "dirlink")

    with pytest.raises(AppError):
        files.read("link")
    with pytest.raises(AppError):
        files.read("dirlink/secret.txt")
    with pytest.raises(AppError):
        files.write("link", b"nadpisane")
    with pytest.raises(AppError):
        files.write("dirlink/nowy.txt", b"x")
    assert secret.read_text() == "token"
    assert not (tmp_path / "nowy.txt").exists()

    # Samo dowiązanie da się usunąć — bez ruszania celu.
    files.delete("link")
    files.delete("dirlink")
    assert secret.exists()
    assert files.list("") == []


def test_rozpakowanie_zip_i_tar_bez_ucieczki(files, tmp_path):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("plugins/a.jar", b"jar")
        z.writestr("../../evil.txt", b"x")
    files.write("mods.zip", buf.getvalue())
    with pytest.raises(AppError):
        files.extract("mods.zip")
    assert not (tmp_path / "evil.txt").exists()

    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("plugins/a.jar", b"jar")
        z.writestr("world/level.dat", b"lvl")
    files.write("ok.zip", buf.getvalue())
    assert files.extract("ok.zip") == 2
    assert files.read("plugins/a.jar") == b"jar"

    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode="w:gz") as t:
        data = b"hello"
        info = tarfile.TarInfo("bot/index.js")
        info.size = len(data)
        t.addfile(info, io.BytesIO(data))
        link = tarfile.TarInfo("bot/passwd")
        link.type = tarfile.SYMTYPE
        link.linkname = "/etc/passwd"
        t.addfile(link)
    files.write("bot.tar.gz", buf.getvalue())
    files.extract("bot.tar.gz")
    assert files.read("bot/index.js") == b"hello"
    assert "passwd" not in [e["name"] for e in files.list("bot")], "dowiązań z archiwum nie odtwarzamy"


def test_limit_rozpakowania(files):
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as z:
        z.writestr("big.bin", b"0" * 5000)
    files.write("big.zip", buf.getvalue())
    with pytest.raises(AppError, match="limicie dysku"):
        files.extract("big.zip", limit_bytes=1000)


# --- pliki konfiguracyjne ----------------------------------------------------------------

def test_properties_ustawia_port_i_zachowuje_reszte(files):
    files.write("server.properties", b"motd=Serwer\nserver-port=25565\n")
    apply_config_file(files, AppConfigFile(file="server.properties", parser="properties",
                                           replace={"server-port": "30001", "query.port": "30001"}))
    text = files.read("server.properties").decode()
    assert "motd=Serwer" in text
    assert "server-port=30001" in text
    assert "query.port=30001" in text, "brakujący klucz jest dopisywany"
    assert "25565" not in text


def test_brakujacy_plik_powstaje_a_json_ustawia_zagniezdzone_klucze(files):
    apply_config_file(files, AppConfigFile(file="config/bot.json", parser="json",
                                           replace={"server.port": "30002", "debug": "false"}))
    assert json.loads(files.read("config/bot.json")) == {"server": {"port": 30002}, "debug": False}


# --- pełny cykl na Dockerze ---------------------------------------------------------

def _docker_or_skip():
    try:
        import docker

        client = docker.from_env(timeout=30)
        client.ping()
        client.images.get("ghcr.io/pterodactyl/yolks:nodejs_20")
        client.images.get("ghcr.io/pterodactyl/installers:alpine")
        return client
    except Exception as exc:  # brak Dockera albo obrazów (CI bez sieci)
        pytest.skip(f"Docker z obrazami Pterodactyla niedostępny: {exc}")


INSTALL_SCRIPT = """#!/bin/ash
set -e
cd /mnt/server
echo "Instaluję bota {{BOT_NAME}} ($BOT_NAME)"
cat > index.js <<'JS'
const readline = require('readline');
console.log(`Bot ${process.env.BOT_NAME} gotowy na porcie ${process.env.SERVER_PORT}`);
readline.createInterface({ input: process.stdin }).on('line', (l) => console.log('komenda: ' + l));
process.on('SIGINT', () => { console.log('zamykam'); process.exit(0); });
setInterval(() => {}, 1000);
JS
echo gotowe
"""


@pytest.fixture()
def manager(settings, tmp_path):
    client = _docker_or_skip()
    mgr = AppManager(dataclasses.replace(settings, apps_dir=tmp_path / "apps", apps_network="virthub_apps_test"), client)
    yield mgr
    for c in client.containers.list(all=True, filters={"label": "virthub.app"}):
        c.remove(force=True)


def _spec(**overrides) -> AppSpec:
    data = dict(
        uuid=str(uuidlib.uuid4()),
        image="ghcr.io/pterodactyl/yolks:nodejs_20",
        startup="node index.js",
        stop="^C",
        environment={"BOT_NAME": "Testowy"},
        memory_mb=256,
        cpu_percent=50,
        disk_mb=100,
        allocations=[{"port": 30123}],
        config_files=[{"file": "config.properties", "parser": "properties", "replace": {"port": "30123"}}],
        install={"image": "ghcr.io/pterodactyl/installers:alpine", "entrypoint": "ash", "script": INSTALL_SCRIPT},
    )
    data.update(overrides)
    return AppSpec(**data)


def _wait_for(fn, timeout=20):
    deadline = time.time() + timeout
    while time.time() < deadline:
        value = fn()
        if value:
            return value
        time.sleep(0.3)
    return fn()


def test_pelny_cykl_aplikacji(manager, monkeypatch):
    # Bez sieci obrazy są już lokalnie — pull może się nie udać, instalacja idzie dalej.
    spec = _spec()

    result = manager.install(spec)
    assert result["installed"] is True
    install_log = manager.logs(spec.uuid)  # przed startem — log instalacji
    assert any("gotowe" in line for line in install_log["lines"]) or install_log["source"] == "console"

    files = manager.files(spec.uuid)
    names = [e["name"] for e in files.list("")]
    assert "index.js" in names
    # Pliki po instalacji należą do użytkownika agenta, nie do roota z instalatora.
    assert os.stat(files.root / "index.js").st_uid == os.getuid()

    status = manager.power(spec.uuid, "start")
    assert status["state"] == "running"
    container = manager.client.containers.get("vh-app-" + spec.uuid)
    host = container.attrs["HostConfig"]
    assert host["Memory"] == 256 * 1024 * 1024
    assert host["NanoCpus"] == 500_000_000
    assert host["PidsLimit"] == 1024
    assert "no-new-privileges" in host["SecurityOpt"]
    assert host["PortBindings"]["30123/tcp"][0]["HostPort"] == "30123"
    assert container.attrs["Config"]["User"] == f"{os.getuid()}:{os.getgid()}"
    assert host["Dns"] == ["1.1.1.1", "1.0.0.1"], "DNS jak w Wings — resolvery hosta bywają nieosiągalne"
    assert b"port=30123" in files.read("config.properties"), "config.files zastosowane przed startem"

    ready = _wait_for(lambda: [l for l in manager.logs(spec.uuid)["lines"] if "gotowy na porcie 30123" in l])
    assert ready, manager.logs(spec.uuid)

    cursor = manager.logs(spec.uuid)["cursor"]
    manager.command(spec.uuid, "say hej")
    echoed = _wait_for(lambda: [l for l in manager.logs(spec.uuid, since=cursor)["lines"] if "komenda: say hej" in l])
    assert echoed, "polecenie z konsoli trafia na stdin aplikacji"

    stats = manager.status(spec.uuid)
    assert stats["state"] == "running"
    assert stats["memory_bytes"] > 0

    status = manager.power(spec.uuid, "stop")
    assert status["state"] == "offline"
    assert status["exit_code"] == 0, "^C = SIGINT, aplikacja zamyka się sama, bez kill"

    manager.delete(spec.uuid)
    assert not manager.data_dir(spec.uuid).exists()
    assert manager.status(spec.uuid)["state"] == "missing"


def test_zmiana_obrazu_tworzy_nowy_kontener_przy_starcie(manager):
    spec = _spec()
    manager.install(spec)
    first = manager.client.containers.get("vh-app-" + spec.uuid).id

    changed = spec.model_copy(update={"environment": {"BOT_NAME": "Nowy"}})
    assert manager.update(changed)["restart_required"] is True
    manager.power(spec.uuid, "start")
    second = manager.client.containers.get("vh-app-" + spec.uuid)
    assert second.id != first
    assert "BOT_NAME=Nowy" in second.attrs["Config"]["Env"]
    manager.power(spec.uuid, "kill")
    manager.delete(spec.uuid)


def test_przekroczony_dysk_blokuje_start(manager):
    spec = _spec(disk_mb=1)
    manager.install(spec)
    (manager.data_dir(spec.uuid) / "big.bin").write_bytes(b"0" * (2 * 1024 * 1024))
    with pytest.raises(AppError, match="limitu"):
        manager.power(spec.uuid, "start")
    manager.delete(spec.uuid)


def test_nieudany_skrypt_konczy_instalacje_bledem(manager):
    spec = _spec(install={"image": "ghcr.io/pterodactyl/installers:alpine", "entrypoint": "ash",
                          "script": "echo psuje sie\nexit 3\n"})
    with pytest.raises(AppError, match="kodem 3"):
        manager.install(spec)
    assert any("psuje sie" in l for l in manager.logs(spec.uuid)["lines"])
    manager.delete(spec.uuid)


def test_api_aplikacji(client, manager, monkeypatch):
    """Endpointy agenta: instalacja przez kolejkę, zasilanie, pliki, konsola."""
    from agent import main
    from test_provisioning import wait_for_job

    monkeypatch.setattr(main, "apps", manager)
    spec = _spec()

    response = client.post("/apps", json=spec.model_dump())
    assert response.status_code == 202, response.text
    job = wait_for_job(client, response.json()["job_id"], timeout=120)
    assert job["status"] == "done", job.get("error")

    listing = client.post(f"/apps/{spec.uuid}/files/list", json={"path": ""}).json()
    assert "index.js" in [e["name"] for e in listing["entries"]]

    content = base64.b64encode(b"console.log('nowy')").decode()
    assert client.post(f"/apps/{spec.uuid}/files/write", json={"path": "extra/a.js", "content_base64": content}).status_code == 200
    read = client.post(f"/apps/{spec.uuid}/files/read", json={"path": "extra/a.js"}).json()
    assert base64.b64decode(read["content_base64"]) == b"console.log('nowy')"
    assert client.post(f"/apps/{spec.uuid}/files/read", json={"path": "../../etc/passwd"}).status_code == 409

    assert client.post(f"/apps/{spec.uuid}/power", json={"action": "start"}).json()["state"] == "running"
    assert client.post(f"/apps/{spec.uuid}/command", json={"command": "ping"}).json() == {"sent": True}
    assert client.post(f"/apps/{spec.uuid}/command", json={"command": "a\nb"}).status_code == 422
    assert client.post(f"/apps/{spec.uuid}/power", json={"action": "kill"}).json()["state"] == "offline"
    assert client.delete(f"/apps/{spec.uuid}").json()["deleted"] is True
    assert client.get(f"/apps/{spec.uuid}/status").json()["state"] == "missing"
