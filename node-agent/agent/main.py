"""Aplikacja FastAPI agenta hypervisora.

Operacje modyfikujące stan są asynchroniczne: endpoint zwraca 202 z
identyfikatorem zadania, a właściwa praca dzieje się w lokalnej kolejce.
Odczyty (stats, health) są synchroniczne — muszą być tanie, bo control plane
odpytuje je cyklicznie.
"""

from __future__ import annotations

import logging
from contextlib import asynccontextmanager
from typing import Any

from fastapi import Depends, FastAPI, HTTPException, Request, WebSocket, status
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse
from pydantic import BaseModel, Field

try:
    from docker import errors as docker_errors  # type: ignore[import-not-found]
except ImportError:  # pragma: no cover — węzeł bez aplikacji
    class docker_errors:  # type: ignore[no-redef]
        class DockerException(Exception):
            pass

from .app_console import bridge_app
from .app_content import AppContentRequest
from .app_guard import AppGuard, findings_payload
from .panel import signed_post
from .apps import AppError, AppManager, AppNotFound
from .config import ConfigError, get_settings
from .console import bridge as console_bridge
from .driver import DriverError, VmNotFound, build_driver
from .isos import IsoError
from .templates import TemplateError, TemplateLibrary
from .builder import TemplateBuilder
from .jobs import JobQueue
from .reporter import CallbackReporter
from .host_security import HardenerMissing, HostSecurity
from .monitor import HostMonitor
from .sftp import SftpService
from .schemas import (
    GuestOs,
    DatastoreStatusRequest,
    DrSnapshotRequest,
    ImportVmRequest,
    LiveMigrationRequest,
    RecoverVmRequest,
    CreateVmRequest,
    HostHealth,
    ImagePrefetchRequest,
    IsoDownloadRequest,
    TemplateDownloadRequest,
    TemplateBuildRequest,
    IsoMountRequest,
    ElevateRequest,
    HostdevRequest,
    PrivateNicsRequest,
    DisksRequest,
    HardwareRequest,
    HostnameRequest,
    RescueRequest,
    SeedRequest,
    BackupRequest,
    BackupRestoreRequest,
    BackupDeleteRequest,
    PasswordResetRequest,
    JobAccepted,
    JobState,
    NetworkConfigRequest,
    PowerRequest,
    RebuildVmRequest,
    ResizeVmRequest,
    CpuLimitRequest,
    MacRequest,
    NestedPolicyRequest,
    SnapshotRequest,
    VmStats,
    AppCommandRequest,
    AppDeleteRequest,
    AppLogsRequest,
    AppPathRequest,
    AppPowerRequest,
    AppRenameRequest,
    AppSpec,
    AppWriteRequest,
)
from .security import check_signature, require_control_plane
from .updates import Updates, UpdaterMissing
from .mariadb import MariaDbSetup, MariaDbSetupMissing, PanelAddressUnknown, panel_address

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(name)s: %(message)s",
)
log = logging.getLogger("virthub.agent")

settings = get_settings()
settings.ensure_directories()
driver = build_driver(settings)
updates = Updates(settings)
mariadb_setup = MariaDbSetup(settings)


def _run_hook(p: dict[str, Any]) -> dict[str, Any]:
    from .hooks import run_hook

    result = run_hook(p["name"], p["script"], p.get("env") or {}, timeout=int(p.get("timeout") or 900))
    if not result["ok"]:
        raise DriverError(f"Skrypt {p['name']} zakończył się kodem {result['exit_code']}: {result['output'][-2000:]}")
    return result


def _handlers() -> dict[str, Any]:
    """Mapowanie nazwy akcji z kolejki na wywołanie sterownika.

    Ładunek jest przechowywany w bazie jako JSON, więc modele odtwarzamy tutaj —
    zadanie wznowione po restarcie agenta przechodzi tę samą walidację co świeże.
    """
    return {
        "create_vm": lambda p: driver.create_vm(CreateVmRequest(**p)),
        "power": lambda p: driver.power(p["uuid"], PowerRequest(**p["body"]).action),
        "rebuild": lambda p: driver.rebuild(p["uuid"], RebuildVmRequest(**p["body"])),
        "resize": lambda p: driver.resize(p["uuid"], ResizeVmRequest(**p["body"])),
        "cpu_limit": lambda p: driver.set_cpu_limit(
            p["uuid"], CpuLimitRequest(**p["body"]).cpu_limit_percent
        ),
        "set_mac": lambda p: driver.set_mac(p["uuid"], MacRequest(**p["body"]).mac),
        "delete": lambda p: _delete_vm(p["uuid"]),
        "snapshot": lambda p: driver.snapshot(p["uuid"], p["body"]["name"]),
        "restore": lambda p: driver.restore(p["uuid"], p["body"]["name"]),
        "configure_network": lambda p: driver.configure_network(
            p["uuid"], NetworkConfigRequest(**p["body"])
        ),
        "prefetch_image": lambda p: driver.prefetch_image(
            ImagePrefetchRequest(**p).alias
        ),
        "download_iso": lambda p: _download_iso(IsoDownloadRequest(**p)),
        "mount_iso": lambda p: driver.mount_iso(p["uuid"], IsoMountRequest(**p["body"])),
        "elevate": lambda p: driver.elevate(p["uuid"], ElevateRequest(**p["body"]).action),
        "hostdevs": lambda p: driver.set_hostdevs(p["uuid"], HostdevRequest(**p["body"])),
        "private_nics": lambda p: driver.set_private_nics(p["uuid"], PrivateNicsRequest(**p["body"])),
        "disks": lambda p: driver.set_disks(p["uuid"], DisksRequest(**p["body"])),
        "rescue": lambda p: driver.rescue(p["uuid"], RescueRequest(**p["body"])),
        "seed": lambda p: driver.set_seed(p["uuid"], SeedRequest(**p["body"])),
        "hostname": lambda p: driver.set_hostname(p["uuid"], HostnameRequest(**p["body"])),
        "hardware": lambda p: driver.set_hardware(p["uuid"], HardwareRequest(**p["body"])),
        "backup": lambda p: driver.backup(p["uuid"], BackupRequest(**p["body"])),
        "backup_restore": lambda p: driver.restore_backup(p["uuid"], BackupRestoreRequest(**p["body"])),
        "backup_delete": lambda p: driver.delete_backups(BackupDeleteRequest(**p)),
        "reset_password": lambda p: _reset_password(p["uuid"], PasswordResetRequest(**p["body"])),
        "recover_vm": lambda p: driver.recover_vm(RecoverVmRequest(**p)),
        "export_vm": lambda p: driver.export_vm(p["uuid"]),
        "import_vm": lambda p: driver.import_vm(ImportVmRequest(**p)),
        "prepare_incoming": lambda p: driver.prepare_incoming(CreateVmRequest(**p)),
        "migrate_live": lambda p: driver.migrate_live(p["uuid"], LiveMigrationRequest(**p["body"])),
        "cleanup_incoming": lambda p: driver.cleanup_incoming(int(p["server_id"])),
        "run_hook": lambda p: _run_hook(p),
        "dr_snapshot": lambda p: driver.dr_snapshot(DrSnapshotRequest(**p)),
    }


def _delete_vm(uuid: str) -> dict[str, Any]:
    """Usunięcie jest idempotentne: maszyny, której już nie ma (skasowanej ręcznie
    albo w poprzedniej, przerwanej próbie), nie da się usunąć drugi raz — i nie
    trzeba. Panel dostaje sukces i może zwolnić adresy."""
    try:
        return driver.delete(uuid)
    except VmNotFound:
        log.warning("Usuwana maszyna %s nie istnieje na węźle — uznaję za usuniętą", uuid)
        return {"uuid": uuid, "deleted": True, "already_absent": True}


def _reset_password(uuid: str, req: PasswordResetRequest) -> dict[str, Any]:
    # Konto inne niż root tylko w maszynach Windows (KVM) — kontenery zostają przy roocie.
    if req.username == "root":
        return driver.reset_password(uuid, req.password)
    return driver.reset_password(uuid, req.password, req.username)


def _download_iso(req: IsoDownloadRequest) -> dict[str, Any]:
    try:
        return driver.isos.download(req.name, req.url, req.sha256, untrusted=req.untrusted, max_gb=req.max_gb)
    except IsoError as exc:
        raise DriverError(str(exc)) from exc


def _download_template(req: TemplateDownloadRequest) -> dict[str, Any]:
    if settings.virtualization == "lxc":
        raise DriverError("Ten węzeł uruchamia kontenery — szablony KVM go nie dotyczą.")
    try:
        return TemplateLibrary(settings).download(req.name, req.url, req.sha256, req.sha512)
    except TemplateError as exc:
        raise DriverError(str(exc)) from exc


jobs = JobQueue(settings, _handlers())
reporter = CallbackReporter(settings, jobs)

# Aplikacje mają własną kolejkę: instalacja serwera gry potrafi trwać kilka
# minut i nie może blokować operacji na maszynach.
apps = AppManager(settings)
app_jobs = JobQueue(settings, {
    "app_install": lambda p: apps.install(AppSpec(**p["spec"]), reinstall=bool(p.get("reinstall"))),
    "app_content": lambda p: apps.content(p["uuid"], AppContentRequest(**p["request"])),
}, name="virthub-app-jobs")
# Pobieranie (minuty) i budowa (godziny) szablonów KVM — osobna kolejka, żeby
# nie blokowały tworzenia i zasilania maszyn.
def _build_template(req: TemplateBuildRequest) -> dict[str, Any]:
    if settings.virtualization == "lxc":
        raise DriverError("Ten węzeł uruchamia kontenery — szablony KVM go nie dotyczą.")
    try:
        return TemplateBuilder(settings).build(req)
    except TemplateError as exc:
        raise DriverError(str(exc)) from exc


build_jobs = JobQueue(settings, {
    "build_template": lambda p: _build_template(TemplateBuildRequest(**p)),
    "download_template": lambda p: _download_template(TemplateDownloadRequest(**p)),
}, name="virthub-template-jobs")
sftp = SftpService(settings, apps, settings.sftp_port, settings.sftp_listen)
host_security = HostSecurity(kvm_host=settings.driver == "libvirt", allow_nested=settings.allow_nested)
monitor = HostMonitor(app_resolver=apps.container_map if settings.apps_dir.is_dir() else None)


def _report_abuse(uuid: str, findings: list, action: str) -> None:
    """Znaleziska ochrony aplikacji → panel (zawiesza aplikację i pokazuje powód)."""
    response = signed_post(settings, "/api/internal/agent/app-abuse", {
        "uuid": uuid, "action": action, "findings": findings_payload(findings),
    })
    if response.status_code >= 400:
        log.warning("Panel odrzucił zgłoszenie nadużycia %s: HTTP %s", uuid, response.status_code)


guard = AppGuard(apps, _report_abuse, mode=settings.apps_guard, interval=settings.apps_guard_interval)
apps.guard = guard


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Mostek NAT i tabela nftables giną przy restarcie hosta — odtwarzamy je,
    # zanim kolejka ruszy, żeby start maszyny nie trafił na brak mostka.
    try:
        # Zapora, anty-spoofing i ochrona węzła — przed NAT, bo same go odtwarzają.
        restored = driver.network.restore()
        if restored:
            log.info("Odtworzono reguły sieci dla %s maszyn", restored)
    except Exception:
        log.exception("Nie udało się odtworzyć reguł sieci maszyn")
    storage = getattr(driver, "storage", None)
    if hasattr(storage, "restore_mounts") and not settings.is_mock:
        try:
            mounted = storage.restore_mounts()
            if mounted:
                log.info("Przygotowano zdalne magazyny dla %s dysków", mounted)
        except Exception:
            log.exception("Nie udało się przygotować zdalnych magazynów")
    if hasattr(driver, "restore_private_bridges") and not settings.is_mock:
        try:
            bridges = driver.restore_private_bridges()
            if bridges:
                log.info("Odtworzono %s mostków sieci prywatnych", bridges)
        except Exception:
            log.exception("Nie udało się odtworzyć mostków sieci prywatnych")
    if hasattr(driver, "harden_existing"):
        try:
            hardened = driver.harden_existing()
            if hardened:
                log.info("Utwardzono %s istniejących kontenerów", hardened)
        except Exception:
            log.exception("Nie udało się utwardzić istniejących kontenerów")
    try:
        restored = driver.network.nat.restore()
        if restored:
            log.info("Odtworzono NAT dla %s maszyn", restored)
    except Exception:
        log.exception("Nie udało się odtworzyć konfiguracji NAT")

    jobs.start()
    app_jobs.start()
    build_jobs.start()
    reporter.start()
    # SFTP tylko tam, gdzie są aplikacje — węzeł bez Dockera nie otwiera portu.
    if settings.sftp_port and settings.apps_dir.is_dir():
        try:
            await sftp.start()
        except Exception:
            log.exception("Nie udało się uruchomić SFTP aplikacji")
    if settings.apps_dir.is_dir():
        guard.start()
    log.info(
        "Agent gotowy (driver=%s, bridge=%s, obrazy=%s)",
        settings.driver, settings.bridge, settings.image_dir,
    )
    yield
    guard.stop()
    sftp.stop()
    reporter.stop()
    build_jobs.stop()
    app_jobs.stop()
    jobs.stop()


app = FastAPI(
    title="VirtHub Node Agent",
    version="0.1.0",
    summary="Agent hypervisora — wykonuje zadania control plane przez libvirt.",
    lifespan=lifespan,
)


# --- obsługa błędów: control plane dostaje czytelny powód, nie stack trace ---

@app.exception_handler(VmNotFound)
async def _vm_not_found(_, exc: VmNotFound):
    return JSONResponse(status_code=404, content={"detail": str(exc)})


@app.exception_handler(AppNotFound)
async def _app_not_found(_, exc: AppNotFound):
    return JSONResponse(status_code=404, content={"detail": str(exc)})


@app.exception_handler(AppError)
async def _app_error(_, exc: AppError):
    return JSONResponse(status_code=409, content={"detail": str(exc)})


@app.exception_handler(DriverError)
async def _driver_error(_, exc: DriverError):
    return JSONResponse(status_code=409, content={"detail": str(exc)})


@app.exception_handler(docker_errors.DockerException)
async def _docker_error(_, exc: Exception):
    # Błąd Dockera (brak obrazu, odrzucony profil, brak miejsca) — panel ma
    # pokazać przyczynę, a nie gołe „Internal Server Error”.
    detail = getattr(exc, "explanation", None) or str(exc)
    log.warning("Błąd Dockera: %s", detail)
    return JSONResponse(status_code=409, content={"detail": f"Docker: {detail}"[:1000]})


@app.exception_handler(ConfigError)
async def _config_error(_, exc: ConfigError):
    return JSONResponse(status_code=500, content={"detail": str(exc)})


# --- liveness (bez podpisu — używane przez systemd/monitoring hosta) ---------

@app.get("/ping", tags=["system"])
async def ping() -> dict[str, str]:
    return {"status": "ok", "driver": settings.driver}


# --- heartbeat i telemetria -------------------------------------------------

@app.get(
    "/health",
    response_model=HostHealth,
    dependencies=[Depends(require_control_plane)],
    tags=["system"],
)
async def health() -> HostHealth:
    apps_state = await run_in_threadpool(apps.health)
    security_state = await run_in_threadpool(host_security.report)
    return driver.health().model_copy(update={
        "host_security": security_state,
        "apps": apps_state,
        "build": updates.build(),
        "remote_update": updates.enabled(),
        "firewall_stateful": driver.network.stateful(),
    })


@app.get("/system/security", dependencies=[Depends(require_control_plane)], tags=["system"])
async def host_security_report() -> dict[str, Any]:
    """Świeży audyt ochrony hosta (panel: Infrastruktura → Bezpieczeństwo → „Sprawdź teraz”)."""
    return await run_in_threadpool(host_security.report, True)


@app.post("/system/datastores", dependencies=[Depends(require_control_plane)], tags=["system"])
async def datastores_status(req: DatastoreStatusRequest) -> dict[str, Any]:
    """Pojemność i dostępność magazynów danych przypiętych do węzła."""
    storage = getattr(driver, "storage", None)
    if storage is None or not hasattr(storage, "status"):
        raise HTTPException(status_code=409, detail="Ten węzeł nie obsługuje magazynów danych.")
    return {"datastores": await run_in_threadpool(storage.status, req.datastores)}


@app.get("/system/pci", dependencies=[Depends(require_control_plane)], tags=["system"])
async def pci_devices() -> dict[str, Any]:
    """Urządzenia PCI hosta do przekazania maszynom (Infrastruktura → hypervisor → Urządzenia PCI)."""
    return await run_in_threadpool(driver.pci_devices)


@app.put("/system/nested", dependencies=[Depends(require_control_plane)], tags=["system"])
async def nested_policy(req: NestedPolicyRequest) -> dict[str, Any]:
    """Polityka zagnieżdżonej wirtualizacji: auto (tylko na załatanym jądrze) albo allow.
    Stosuje ją usługa roota virthub-harden w ciągu kilku sekund."""
    try:
        return await run_in_threadpool(host_security.set_nested_policy, req.policy)
    except HardenerMissing as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc


@app.get("/system/monitor", dependencies=[Depends(require_control_plane)], tags=["system"])
async def host_monitor() -> dict[str, Any]:
    """Szczegółowe statystyki hosta i usług dla strony Monitorowanie w panelu."""
    return await run_in_threadpool(monitor.report)


# --- aktualizacja węzła -----------------------------------------------------

@app.get("/system/update", dependencies=[Depends(require_control_plane)], tags=["system"])
async def update_status() -> dict[str, Any]:
    return updates.status()


@app.post(
    "/system/update",
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["system"],
)
async def request_update() -> dict[str, Any]:
    """Zleca aktualizację; wykonuje ją uprzywilejowana usługa systemd."""
    try:
        return updates.request()
    except UpdaterMissing as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc


# --- serwer baz MariaDB dla aplikacji ------------------------------------------

class MariaDbRequest(BaseModel):
    open_firewall: bool = False


@app.get("/system/mariadb", dependencies=[Depends(require_control_plane)], tags=["system"])
async def mariadb_status() -> dict[str, Any]:
    return mariadb_setup.status()


@app.post(
    "/system/mariadb",
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["system"],
)
async def mariadb_install(req: MariaDbRequest, request: Request) -> dict[str, Any]:
    """Zleca instalację MariaDB; wykonuje ją usługa roota virthub-mariadb.
    Konto administracyjne dostaje dostęp tylko z adresu, z którego przyszło to żądanie."""
    try:
        host = panel_address(request.headers.get("x-real-ip"))
        return mariadb_setup.request(host, req.open_firewall)
    except (MariaDbSetupMissing, PanelAddressUnknown) as exc:
        raise HTTPException(status_code=409, detail=str(exc)) from exc


@app.delete("/system/mariadb/credentials", dependencies=[Depends(require_control_plane)], tags=["system"])
async def mariadb_forget() -> dict[str, Any]:
    """Panel zapisał dane konta — usuwamy je z dysku węzła."""
    return mariadb_setup.forget_credentials()


@app.get(
    "/vm/{uuid}/stats",
    response_model=VmStats,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def vm_stats(uuid: str) -> VmStats:
    return driver.stats(uuid)


@app.get(
    "/vm/{uuid}/os",
    response_model=GuestOs,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def vm_guest_os(uuid: str) -> GuestOs:
    # Zapytanie do qemu-guest-agent potrafi chwilę potrwać — poza pętlą zdarzeń.
    return await run_in_threadpool(driver.guest_os, uuid)


# --- cykl życia maszyny -----------------------------------------------------

@app.post(
    "/vm",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def create_vm(req: CreateVmRequest) -> JobAccepted:
    job_id = jobs.enqueue("create_vm", req.model_dump(), server_id=req.server_id)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/power",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def power(uuid: str, req: PowerRequest) -> JobAccepted:
    job_id = jobs.enqueue("power", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/rebuild",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def rebuild(uuid: str, req: RebuildVmRequest) -> JobAccepted:
    job_id = jobs.enqueue("rebuild", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/cpu-limit",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def cpu_limit(uuid: str, req: CpuLimitRequest) -> JobAccepted:
    job_id = jobs.enqueue("cpu_limit", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/mac",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_mac(uuid: str, req: MacRequest) -> JobAccepted:
    """MAC karty sieciowej z panelu (wirtualny MAC adresu IP u dostawcy)."""
    job_id = jobs.enqueue("set_mac", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/resize",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def resize(uuid: str, req: ResizeVmRequest) -> JobAccepted:
    # exclude_unset: pominięty limit CPU (starszy panel) ma zostać bez zmian,
    # a nie zamienić się w „bez limitu" po odtworzeniu ładunku z kolejki.
    job_id = jobs.enqueue("resize", {"uuid": uuid, "body": req.model_dump(exclude_unset=True)}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.delete(
    "/vm/{uuid}",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def delete_vm(uuid: str) -> JobAccepted:
    job_id = jobs.enqueue("delete", {"uuid": uuid}, uuid=uuid)
    return JobAccepted(job_id=job_id)


# --- snapshoty --------------------------------------------------------------

@app.post(
    "/vm/{uuid}/snapshot",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["snapshots"],
)
async def snapshot(uuid: str, req: SnapshotRequest) -> JobAccepted:
    job_id = jobs.enqueue("snapshot", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/snapshot/restore",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["snapshots"],
)
async def restore(uuid: str, req: SnapshotRequest) -> JobAccepted:
    job_id = jobs.enqueue("restore", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


# --- sieć -------------------------------------------------------------------

@app.put(
    "/vm/{uuid}/network",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["network"],
)
async def configure_network(uuid: str, req: NetworkConfigRequest) -> JobAccepted:
    job_id = jobs.enqueue(
        # exclude_unset: brak bandwidth_mbps (starszy panel) ma zostać „bez zmian”, a nie „bez limitu”.
        "configure_network", {"uuid": uuid, "body": req.model_dump(exclude_unset=True)}, uuid=uuid
    )
    return JobAccepted(job_id=job_id)


# --- szablony kontenerów ----------------------------------------------------

@app.post(
    "/images/prefetch",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["images"],
)
async def prefetch_image(req: ImagePrefetchRequest) -> JobAccepted:
    """Pobranie szablonu trwa minuty — idzie przez kolejkę, jak każda
    długa operacja, a panel dopytuje o wynik."""
    job_id = jobs.enqueue("prefetch_image", req.model_dump())
    return JobAccepted(job_id=job_id)


@app.post(
    "/templates/download",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["images"],
)
async def download_template(req: TemplateDownloadRequest) -> JobAccepted:
    """Szablon KVM z katalogu panelu — pobranie kilkuset MB idzie przez kolejkę."""
    job_id = build_jobs.enqueue("download_template", req.model_dump())
    return JobAccepted(job_id=job_id)


@app.post(
    "/templates/build",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["images"],
)
async def build_template(req: TemplateBuildRequest) -> JobAccepted:
    """Szablon Windows budowany Packerem na tym węźle — kolejka budowy."""
    job_id = build_jobs.enqueue("build_template", req.model_dump())
    return JobAccepted(job_id=job_id)


# --- konsola ----------------------------------------------------------------

@app.websocket("/vm/{uuid}/console")
async def console(websocket: WebSocket, uuid: str) -> None:
    """Konsola maszyny: RFB dla KVM, terminal dla kontenera.

    Podpis jak przy każdym żądaniu (GET, pusta treść). Odrzucenie przed
    accept() kończy się odpowiedzią 403 na etapie handshake'u — nieuprawniony
    klient nie dostaje nawet otwartego gniazda.
    """
    error = check_signature(
        websocket.headers.get("x-vh-signature", ""),
        websocket.headers.get("x-vh-timestamp", ""),
        "GET",
        websocket.url.path,
        b"",
    )
    if error is not None:
        log.warning("Odrzucono połączenie konsoli: %s", error)
        await websocket.close(code=1008)
        return

    try:
        target = await run_in_threadpool(driver.console_target, uuid)
    except DriverError as exc:
        log.info("Konsola maszyny %s niedostępna: %s", uuid, exc)
        await websocket.close(code=1008, reason=str(exc)[:120])
        return

    # noVNC prosi o podprotokół „binary" — bez jego potwierdzenia przeglądarka
    # zrywa połączenie. Przekaźnik panelu przekazuje prośbę dalej.
    requested = websocket.scope.get("subprotocols") or []
    await websocket.accept(subprotocol="binary" if "binary" in requested else None)
    log.info("Otwarto konsolę (%s) maszyny %s", target.kind, uuid)
    await console_bridge(websocket, target)


# --- obrazy ISO -------------------------------------------------------------

@app.get("/images/iso", dependencies=[Depends(require_control_plane)], tags=["images"])
async def list_isos() -> dict[str, Any]:
    return {"data": driver.isos.list()}


@app.post(
    "/images/iso",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["images"],
)
async def download_iso(req: IsoDownloadRequest) -> JobAccepted:
    """Pobranie obrazu trwa minuty — przez kolejkę, jak szablony."""
    return JobAccepted(job_id=jobs.enqueue("download_iso", req.model_dump()))


@app.delete("/images/iso/{name}", dependencies=[Depends(require_control_plane)], tags=["images"])
async def delete_iso(name: str) -> dict[str, Any]:
    try:
        return driver.isos.delete(name)
    except IsoError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc


@app.post(
    "/vm/{uuid}/iso",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def mount_iso(uuid: str, req: IsoMountRequest) -> JobAccepted:
    job_id = jobs.enqueue("mount_iso", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/backups",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def create_backup(uuid: str, req: BackupRequest) -> JobAccepted:
    """Kopia dysku na cel zewnętrzny (lokalny katalog, S3, SFTP)."""
    job_id = jobs.enqueue("backup", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/backups/restore",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def restore_backup(uuid: str, req: BackupRestoreRequest) -> JobAccepted:
    job_id = jobs.enqueue("backup_restore", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/recover",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def recover_vm(req: RecoverVmRequest) -> JobAccepted:
    """Disaster recovery: maszyna z kopii na celu zewnętrznym na tym (zastępczym) węźle."""
    job_id = jobs.enqueue("recover_vm", req.model_dump(), server_id=req.server_id)
    return JobAccepted(job_id=job_id)


# --- migracje między węzłami ---------------------------------------------------


@app.post("/vm/{uuid}/export", response_model=JobAccepted, status_code=status.HTTP_202_ACCEPTED,
          dependencies=[Depends(require_control_plane)], tags=["migration"])
async def export_vm(uuid: str) -> JobAccepted:
    """Migracja na zimno, źródło: zatrzymanie, spłaszczenie dysku i wystawienie pod tokenem."""
    return JobAccepted(job_id=jobs.enqueue("export_vm", {"uuid": uuid}, uuid=uuid))


@app.get("/transfer/{token}", tags=["migration"])
async def transfer_file(token: str):
    """Dysk dla węzła docelowego. Bez podpisu panelu — dostęp daje sam token
    (32 losowe bajty, ważny kilka godzin, unieważniany po migracji)."""
    from fastapi.responses import FileResponse

    from .migration import TransferStore

    path = TransferStore(settings).resolve(token) if len(token) <= 64 else None
    if path is None:
        raise HTTPException(status_code=404, detail="Nieznany albo wygasły transfer.")
    return FileResponse(path, media_type="application/octet-stream")


@app.delete("/transfer/{token}", dependencies=[Depends(require_control_plane)], tags=["migration"])
async def revoke_transfer(token: str) -> dict[str, Any]:
    from .migration import TransferStore

    return {"revoked": TransferStore(settings).revoke(token)}


@app.post("/vm/import", response_model=JobAccepted, status_code=status.HTTP_202_ACCEPTED,
          dependencies=[Depends(require_control_plane)], tags=["migration"])
async def import_vm(req: ImportVmRequest) -> JobAccepted:
    """Migracja na zimno, cel: pobranie dysku ze źródła i założenie maszyny."""
    return JobAccepted(job_id=jobs.enqueue("import_vm", req.model_dump(), server_id=req.server_id))


@app.post("/vm/prepare-migration", response_model=JobAccepted, status_code=status.HTTP_202_ACCEPTED,
          dependencies=[Depends(require_control_plane)], tags=["migration"])
async def prepare_migration(req: CreateVmRequest) -> JobAccepted:
    """Migracja na żywo, cel: sieć, nośnik cloud-init i pusty dysk przed przyjęciem domeny."""
    return JobAccepted(job_id=jobs.enqueue("prepare_incoming", req.model_dump(), server_id=req.server_id))


@app.post("/vm/{uuid}/migrate", response_model=JobAccepted, status_code=status.HTTP_202_ACCEPTED,
          dependencies=[Depends(require_control_plane)], tags=["migration"])
async def migrate_live(uuid: str, req: LiveMigrationRequest) -> JobAccepted:
    """Migracja na żywo, źródło: libvirt z kopiowaniem dysku do węzła docelowego."""
    return JobAccepted(job_id=jobs.enqueue("migrate_live", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid))


@app.post("/vm/{uuid}/migrate/abort", dependencies=[Depends(require_control_plane)], tags=["migration"])
async def abort_migration(uuid: str) -> dict[str, Any]:
    """Przerwanie trwającej migracji na żywo — od razu, poza kolejką (ta jest zajęta migracją)."""
    try:
        return await run_in_threadpool(driver.abort_migration, uuid)
    except VmNotFound:
        raise HTTPException(status_code=404, detail="Nie ma takiej maszyny.")
    except DriverError as exc:
        raise HTTPException(status_code=409, detail=str(exc))


class HookRequest(BaseModel):
    name: str = Field(pattern=r"^[a-z0-9][a-z0-9_-]{0,40}$")
    script: str = Field(min_length=1, max_length=65536)
    env: dict[str, str] = Field(default_factory=dict)
    timeout: int = Field(default=900, ge=10, le=7200)


@app.post("/system/hook", response_model=JobAccepted, status_code=status.HTTP_202_ACCEPTED,
          dependencies=[Depends(require_control_plane)], tags=["system"])
async def system_hook(req: HookRequest) -> JobAccepted:
    """Skrypt administratora na węźle (np. przed i po odtworzeniu DR) — w kolejce, po wcześniejszych zadaniach."""
    return JobAccepted(job_id=jobs.enqueue("run_hook", req.model_dump()))


@app.post("/migration/cleanup/{server_id}", response_model=JobAccepted, status_code=status.HTTP_202_ACCEPTED,
          dependencies=[Depends(require_control_plane)], tags=["migration"])
async def cleanup_incoming(server_id: int) -> JobAccepted:
    """Sprzątanie celu po nieudanej, anulowanej albo cofniętej migracji."""
    return JobAccepted(job_id=jobs.enqueue("cleanup_incoming", {"server_id": server_id}, server_id=server_id))


@app.post(
    "/system/dr-snapshot",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["system"],
)
async def dr_snapshot(req: DrSnapshotRequest) -> JobAccepted:
    """Zrzut konfiguracji węzła (domeny, sieć, magazyny bez kluczy) na cel kopii."""
    job_id = jobs.enqueue("dr_snapshot", req.model_dump())
    return JobAccepted(job_id=job_id)


@app.post(
    "/backups/delete",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["system"],
)
async def delete_backups(req: BackupDeleteRequest) -> JobAccepted:
    """Przycinanie starych kopii (retencja planu w panelu)."""
    job_id = jobs.enqueue("backup_delete", req.model_dump())
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/hostdevs",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_hostdevs(uuid: str, req: HostdevRequest) -> JobAccepted:
    job_id = jobs.enqueue("hostdevs", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/rescue",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def rescue(uuid: str, req: RescueRequest) -> JobAccepted:
    """Tryb ratunkowy: start z systemu ratunkowego (dysk maszyny jako vdi) albo powrót."""
    job_id = jobs.enqueue("rescue", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/seed",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_seed(uuid: str, req: SeedRequest) -> JobAccepted:
    job_id = jobs.enqueue("seed", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/hostname",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_hostname(uuid: str, req: HostnameRequest) -> JobAccepted:
    job_id = jobs.enqueue("hostname", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/disks",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_disks(uuid: str, req: DisksRequest) -> JobAccepted:
    """Dodatkowe dyski (pełna lista docelowa) i powiększenie dysku głównego — na żywo."""
    job_id = jobs.enqueue("disks", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/hardware",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_hardware(uuid: str, req: HardwareRequest) -> JobAccepted:
    """Magistrala i I/O dysków, UEFI/BIOS, vTPM, model CPU i cpuset — po wyłączeniu i włączeniu."""
    job_id = jobs.enqueue("hardware", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put(
    "/vm/{uuid}/nics",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def set_private_nics(uuid: str, req: PrivateNicsRequest) -> JobAccepted:
    """Karty w sieciach prywatnych: pełna lista docelowa (dołączanie/odłączanie na żywo)."""
    job_id = jobs.enqueue("private_nics", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/elevate",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def elevate(uuid: str, req: ElevateRequest) -> JobAccepted:
    """Elevate: nakładka na dysk (start), zapis zmian (commit) albo powrót do stanu sprzed startu (discard)."""
    job_id = jobs.enqueue("elevate", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/vm/{uuid}/password",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def reset_password(uuid: str, req: PasswordResetRequest) -> JobAccepted:
    job_id = jobs.enqueue("reset_password", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


# --- zadania ----------------------------------------------------------------

@app.get(
    "/jobs/{job_id}",
    response_model=JobState,
    dependencies=[Depends(require_control_plane)],
    tags=["jobs"],
)
async def job_state(job_id: str) -> JobState:
    state = jobs.get(job_id)
    if state is None:
        raise HTTPException(status_code=404, detail=f"Nie znaleziono zadania {job_id}.")
    return state


@app.get(
    "/jobs",
    response_model=list[JobState],
    dependencies=[Depends(require_control_plane)],
    tags=["jobs"],
)
async def job_list(limit: int = 50) -> list[JobState]:
    return jobs.recent(min(limit, 200))


# --- aplikacje (serwery gier, boty) ------------------------------------------------

@app.get("/apps", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def apps_health() -> dict[str, Any]:
    return await run_in_threadpool(apps.health)


@app.post(
    "/apps",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["apps"],
)
async def app_install(spec: AppSpec) -> JobAccepted:
    """Instalacja (skrypt eggu + kontener) — przez kolejkę aplikacji."""
    job_id = app_jobs.enqueue("app_install", {"spec": spec.model_dump()}, uuid=spec.uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/apps/{uuid}/reinstall",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["apps"],
)
async def app_reinstall(uuid: str, spec: AppSpec) -> JobAccepted:
    if spec.uuid != uuid:
        raise HTTPException(status_code=422, detail="UUID w ścieżce i w specyfikacji się różnią.")
    job_id = app_jobs.enqueue("app_install", {"spec": spec.model_dump(), "reinstall": True}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.post(
    "/apps/{uuid}/content",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["apps"],
)
async def app_content(uuid: str, req: AppContentRequest) -> JobAccepted:
    """Modpack, loader, plugin albo mod — lista kroków rozwiązana przez panel."""
    if not apps.data_dir(uuid).is_dir():
        raise HTTPException(status_code=404, detail=f"Aplikacja {uuid} nie istnieje na tym węźle.")
    job_id = app_jobs.enqueue("app_content", {"uuid": uuid, "request": req.model_dump()}, uuid=uuid)
    return JobAccepted(job_id=job_id)


@app.put("/apps/{uuid}", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_update(uuid: str, spec: AppSpec) -> dict[str, Any]:
    if spec.uuid != uuid:
        raise HTTPException(status_code=422, detail="UUID w ścieżce i w specyfikacji się różnią.")
    return await run_in_threadpool(apps.update, spec)


@app.delete("/apps/{uuid}", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_delete(uuid: str) -> dict[str, Any]:
    return await run_in_threadpool(apps.delete, uuid)


@app.get("/apps/{uuid}/status", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_status(uuid: str) -> dict[str, Any]:
    return await run_in_threadpool(apps.status, uuid)


@app.post("/apps/{uuid}/power", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_power(uuid: str, req: AppPowerRequest) -> dict[str, Any]:
    return await run_in_threadpool(apps.power, uuid, req.action)


@app.post("/apps/{uuid}/command", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_command(uuid: str, req: AppCommandRequest) -> dict[str, Any]:
    await run_in_threadpool(apps.command, uuid, req.command)
    return {"sent": True}


@app.websocket("/apps/{uuid}/console")
async def app_console(websocket: WebSocket, uuid: str) -> None:
    """Konsola aplikacji na żywo — wyjście strumieniem, polecenia i statystyki.

    Podpis jak przy konsoli maszyny (GET, pusta treść); łączy się tylko
    przekaźnik panelu z jednorazową sesją.
    """
    error = check_signature(
        websocket.headers.get("x-vh-signature", ""),
        websocket.headers.get("x-vh-timestamp", ""),
        "GET",
        websocket.url.path,
        b"",
    )
    if error is not None:
        log.warning("Odrzucono połączenie konsoli aplikacji: %s", error)
        await websocket.close(code=1008)
        return
    if not apps.data_dir(uuid).is_dir() and uuid not in apps._installing:
        await websocket.close(code=1008, reason="Aplikacja nie istnieje na tym węźle.")
        return

    requested = websocket.scope.get("subprotocols") or []
    await websocket.accept(subprotocol="binary" if "binary" in requested else None)
    log.info("Otwarto konsolę aplikacji %s", uuid)
    await bridge_app(websocket, apps, uuid)


@app.post("/apps/{uuid}/logs", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_logs(uuid: str, req: AppLogsRequest) -> dict[str, Any]:
    return await run_in_threadpool(apps.logs, uuid, req.since, req.tail)


# Ścieżki plików idą w ciele żądania, a nie w zapytaniu — ciało jest objęte podpisem.

@app.post("/apps/{uuid}/files/list", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_list(uuid: str, req: AppPathRequest) -> dict[str, Any]:
    return {"path": req.path, "entries": await run_in_threadpool(apps.files(uuid).list, req.path)}


@app.post("/apps/{uuid}/files/read", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_read(uuid: str, req: AppPathRequest) -> dict[str, Any]:
    import base64

    data = await run_in_threadpool(apps.read_file, uuid, req.path)
    return {"path": req.path, "size": len(data), "content_base64": base64.b64encode(data).decode()}


@app.post("/apps/{uuid}/files/write", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_write(uuid: str, req: AppWriteRequest) -> dict[str, Any]:
    return await run_in_threadpool(apps.write_file, uuid, req.path, req.content_base64, req.append)


@app.post("/apps/{uuid}/files/mkdir", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_mkdir(uuid: str, req: AppPathRequest) -> dict[str, Any]:
    await run_in_threadpool(apps.files(uuid).mkdir, req.path)
    return {"path": req.path}


@app.post("/apps/{uuid}/files/delete", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_delete(uuid: str, req: AppDeleteRequest) -> dict[str, Any]:
    files = apps.files(uuid)
    for path in req.paths:
        await run_in_threadpool(files.delete, path)
    return {"deleted": len(req.paths)}


@app.post("/apps/{uuid}/files/rename", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_rename(uuid: str, req: AppRenameRequest) -> dict[str, Any]:
    await run_in_threadpool(apps.files(uuid).rename, req.source, req.target)
    return {"source": req.source, "target": req.target}


@app.post("/apps/{uuid}/files/decompress", dependencies=[Depends(require_control_plane)], tags=["apps"])
async def app_files_decompress(uuid: str, req: AppPathRequest) -> dict[str, Any]:
    return await run_in_threadpool(apps.decompress, uuid, req.path)
