"""Budowa szablonów Windows: pliki VirtFusion, losowe hasło, ISO z cache, Packer."""

from __future__ import annotations

import http.server
import io
import threading
import zipfile

import pytest

from agent import builder as builder_mod
from agent.builder import BuildError, TemplateBuilder, build_password
from agent.schemas import TemplateBuildRequest

VF_PASSWORD = "SVDMV#tcV2MWrr#WUZMv"

FAKE_PACKER = r"""#!/usr/bin/env python3
import sys, pathlib
args = sys.argv[1:]
if args[0] == "init":
    sys.exit(0)
vars = dict(a.split("=", 1) for a in args if "=" in a and not a.startswith("-"))
pathlib.Path(sys.argv[0]).with_name("last-args").write_text("\n".join(args))
if vars.get("edition") == "2019":
    print("==> qemu.windows: Error: build failed on purpose")
    sys.exit(1)
print("==> qemu.windows: Starting VM, booting from CD-ROM")
print("==> qemu.windows: Waiting for WinRM to become available...")
print("==> qemu.windows: Connected to WinRM! password=" + vars["password"])
out = pathlib.Path(vars["output_dir"]); out.mkdir(parents=True, exist_ok=True)
(out / vars["vm_name"]).write_bytes(b"QFI\xfb windows")
"""


def vf_zip() -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        root = "virtfusion-public-packer-abc/config/"
        zf.writestr(root + "windows-shared/scripts/compact.bat", "copy A:\\cloudbase-init.conf C:\\")
        zf.writestr(root + "windows-shared/patches/cloudinit/windows.py", "# patch")
        for edition in ("2019", "2022"):
            zf.writestr(root + f"windows-server-{edition}-standard/files/Autounattend.xml",
                        f"<AdministratorPassword>\n<Value>{VF_PASSWORD}</Value></AdministratorPassword>"
                        f"<Password><Value>{VF_PASSWORD}</Value></Password>")
            zf.writestr(root + f"windows-server-{edition}-standard/files/unattend.xml", f"<Value>{VF_PASSWORD}</Value>")
    return buf.getvalue()


@pytest.fixture()
def sources(tmp_path):
    srv = tmp_path / "srv"
    srv.mkdir()
    (srv / "vf.zip").write_bytes(vf_zip())
    (srv / "win.iso").write_bytes(b"ISO" * 1000)
    (srv / "virtio.iso").write_bytes(b"VIO" * 1000)
    handler = lambda *a, **kw: http.server.SimpleHTTPRequestHandler(*a, directory=str(srv), **kw)
    server = http.server.ThreadingHTTPServer(("127.0.0.1", 0), handler)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    yield f"http://127.0.0.1:{server.server_address[1]}"
    server.shutdown()


@pytest.fixture()
def tb(settings, tmp_path):
    b = TemplateBuilder(settings)
    b.root = tmp_path / "packer"
    b.library.dir = tmp_path / "templates"
    packer = b.root / "bin" / "packer"
    packer.parent.mkdir(parents=True)
    packer.write_text(FAKE_PACKER)
    packer.chmod(0o755)
    (b.root / "bin" / "VERSION").write_text(builder_mod.PACKER_VERSION)
    return b


def request(base: str, edition: str = "2022") -> TemplateBuildRequest:
    return TemplateBuildRequest.model_construct(
        name=f"windows-server-{edition}.qcow2", edition=edition, evaluation=False,
        iso_url=f"{base}/win.iso", iso_sha256=None, virtio_url=f"{base}/virtio.iso",
        files_url=f"{base}/vf.zip", disk_gb=20, cpus=2, memory_mb=4096, kms_key=None,
    )


def test_budowa_konczy_sie_szablonem_i_losowym_haslem(tb, sources):
    result = tb.build(request(sources))

    assert result["cached"] is False
    assert (tb.library.dir / "windows-server-2022.qcow2").read_bytes().startswith(b"QFI")
    args = (tb.root / "bin" / "last-args").read_text()
    assert "edition=2022" in args and "vnc" not in args
    password = next(a.split("=", 1)[1] for a in args.splitlines() if a.startswith("password="))
    assert password != VF_PASSWORD and len(password) >= 20
    assert not (tb.root / "work" / "windows-server-2022").exists(), "katalog roboczy sprzątnięty"
    assert len(list((tb.root / "cache" / "isos").iterdir())) == 2, "ISO zostają w cache"


def test_istniejacy_szablon_nie_jest_budowany(tb, sources):
    tb.library.dir.mkdir(parents=True)
    (tb.library.dir / "windows-server-2022.qcow2").write_bytes(b"baza maszyn")
    assert tb.build(request(sources))["cached"] is True
    assert not (tb.root / "bin" / "last-args").exists()


def test_blad_packera_ma_czytelny_powod_i_bez_hasla(tb, sources):
    with pytest.raises(BuildError, match="build failed on purpose"):
        tb.build(request(sources, "2019"))
    assert not (tb.library.dir / "windows-server-2019.qcow2").exists()


def test_brak_konfiguracji_wydania(tb, sources):
    with pytest.raises(BuildError, match="windows-server-2025-standard"):
        tb.build(request(sources, "2025"))


def test_haslo_podmienione_w_plikach(tmp_path):
    files = tmp_path / "files"
    (files / "edition").mkdir(parents=True)
    (files / "shared").mkdir()
    (files / "edition" / "Autounattend.xml").write_text(f"<AdministratorPassword><Value>{VF_PASSWORD}</Value></AdministratorPassword>")
    (files / "shared" / "x.ps1").write_text(f"$p='{VF_PASSWORD}'")
    TemplateBuilder._replace_password(files, "Nowe#Haslo1")
    assert VF_PASSWORD not in (files / "shared" / "x.ps1").read_text()
    assert "Nowe#Haslo1" in (files / "edition" / "Autounattend.xml").read_text()


def test_haslo_budowy_spelnia_wymagania_windows():
    p = build_password()
    assert any(c.isupper() for c in p) and any(c.islower() for c in p) and any(c.isdigit() for c in p) and "#" in p


def test_api_budowy_waliduje_wejscie(client):
    bad = client.post("/templates/build", json={"name": "w.qcow2", "edition": "2030", "iso_url": "https://x/y.iso"})
    assert bad.status_code == 422
    ok = client.post("/templates/build", json={"name": "windows-server-2022.qcow2", "edition": "2022", "iso_url": "https://example.invalid/w.iso"})
    assert ok.status_code == 202
