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

from fastapi import Depends, FastAPI, HTTPException, status
from fastapi.responses import JSONResponse

from .config import ConfigError, get_settings
from .driver import DriverError, VmNotFound, build_driver
from .jobs import JobQueue
from .reporter import CallbackReporter
from .schemas import (
    CreateVmRequest,
    HostHealth,
    ImagePrefetchRequest,
    JobAccepted,
    JobState,
    NetworkConfigRequest,
    PowerRequest,
    RebuildVmRequest,
    ResizeVmRequest,
    SnapshotRequest,
    VmStats,
)
from .security import require_control_plane

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)-7s %(name)s: %(message)s",
)
log = logging.getLogger("virthub.agent")

settings = get_settings()
settings.ensure_directories()
driver = build_driver(settings)


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
        "delete": lambda p: driver.delete(p["uuid"]),
        "snapshot": lambda p: driver.snapshot(p["uuid"], p["body"]["name"]),
        "restore": lambda p: driver.restore(p["uuid"], p["body"]["name"]),
        "configure_network": lambda p: driver.configure_network(
            p["uuid"], NetworkConfigRequest(**p["body"])
        ),
        "prefetch_image": lambda p: driver.prefetch_image(
            ImagePrefetchRequest(**p).alias
        ),
    }


jobs = JobQueue(settings, _handlers())
reporter = CallbackReporter(settings, jobs)


@asynccontextmanager
async def lifespan(app: FastAPI):
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
    return driver.health()


@app.get(
    "/vm/{uuid}/stats",
    response_model=VmStats,
    dependencies=[Depends(require_control_plane)],
    tags=["vm"],
)
async def vm_stats(uuid: str) -> VmStats:
    return driver.stats(uuid)


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
