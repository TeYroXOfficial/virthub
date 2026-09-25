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

from fastapi import Depends, FastAPI, HTTPException, WebSocket, status
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import JSONResponse

from .config import ConfigError, get_settings
from .console import bridge as console_bridge
from .driver import DriverError, VmNotFound, build_driver
from .isos import IsoError
from .jobs import JobQueue
from .reporter import CallbackReporter
from .schemas import (
    GuestOs,
    CreateVmRequest,
    HostHealth,
    ImagePrefetchRequest,
    IsoDownloadRequest,
    IsoMountRequest,
    PasswordResetRequest,
    JobAccepted,
    JobState,
    NetworkConfigRequest,
    PowerRequest,
    RebuildVmRequest,
    ResizeVmRequest,
    SnapshotRequest,
    VmStats,
)
from .security import check_signature, require_control_plane
from .updates import Updates, UpdaterMissing

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(name)s: %(message)s",
)
log = logging.getLogger("virthub.agent")

settings = get_settings()
settings.ensure_directories()
driver = build_driver(settings)
updates = Updates(settings)


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
        "reset_password": lambda p: driver.reset_password(
            p["uuid"], PasswordResetRequest(**p["body"]).password
        ),
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


def _download_iso(req: IsoDownloadRequest) -> dict[str, Any]:
    try:
        return driver.isos.download(req.name, req.url, req.sha256)
    except IsoError as exc:
        raise DriverError(str(exc)) from exc


jobs = JobQueue(settings, _handlers())
reporter = CallbackReporter(settings, jobs)


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
    reporter.start()
    log.info(
        "Agent gotowy (driver=%s, bridge=%s, obrazy=%s)",
        settings.driver, settings.bridge, settings.image_dir,
    )
    yield
    reporter.stop()
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


@app.exception_handler(DriverError)
async def _driver_error(_, exc: DriverError):
    return JSONResponse(status_code=409, content={"detail": str(exc)})


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
    return driver.health().model_copy(update={
        "build": updates.build(),
        "remote_update": updates.enabled(),
        "firewall_stateful": driver.network.stateful(),
    })


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


@app.post(
    "/vm/{uuid}/resize",
    response_model=JobAccepted,
    status_code=status.HTTP_202_ACCEPTED,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def resize(uuid: str, req: ResizeVmRequest) -> JobAccepted:
    job_id = jobs.enqueue("resize", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid)
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
        "configure_network", {"uuid": uuid, "body": req.model_dump()}, uuid=uuid
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
