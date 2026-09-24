"""Lokalna kolejka zadań agenta.

Powód istnienia: provisioning trwa dziesiątki sekund, a łączność z control
plane potrafi paść w środku. Gdyby agent trzymał stan zadania tylko w pamięci
albo zależał od odpowiedzi HTTP, taki VPS zostawałby na zawsze w stanie
„building". Tutaj zadanie ląduje w SQLite na hoście, wykonuje się lokalnie i
raportuje wynik — także po restarcie agenta czy powrocie sieci.

Control plane może pobrać stan zadania sam (GET /jobs/{id}), a niezależnie od
tego agent próbuje odesłać wynik callbackiem.
"""

from __future__ import annotations

import json
import logging
import queue
import sqlite3
import threading
import time
import uuid as uuidlib
from pathlib import Path
from typing import Any, Callable

from .config import Settings
from .schemas import JobState, JobStatus

log = logging.getLogger("virthub.jobs")

# Zadanie wykonywane właśnie w wątku roboczym — drivery zgłaszają przez
# progress() etap pracy, a panel pokazuje go klientowi na żywo.
_current = threading.local()


def progress(stage: str, percent: int | None = None) -> None:
    """Zapisuje etap bieżącego zadania (np. „image", 30). Poza zadaniem nic nie robi."""
    queue_ = getattr(_current, "queue", None)
    job_id = getattr(_current, "job_id", None)
    if queue_ is None or job_id is None:
        return
    try:
        queue_.set_progress(job_id, stage, percent)
    except sqlite3.Error:
        log.warning("Nie udało się zapisać etapu %s zadania %s", stage, job_id)

SCHEMA = """
CREATE TABLE IF NOT EXISTS jobs (
    job_id      TEXT PRIMARY KEY,
    action      TEXT NOT NULL,
    uuid        TEXT,
    server_id   INTEGER,
    payload     TEXT NOT NULL,
    status      TEXT NOT NULL,
    result      TEXT,
    error       TEXT,
    created_at  REAL NOT NULL,
    finished_at REAL,
    reported    INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS jobs_status_idx ON jobs (status);
CREATE INDEX IF NOT EXISTS jobs_unreported_idx ON jobs (reported, status);
"""


class JobQueue:
    def __init__(
        self,
        settings: Settings,
        handlers: dict[str, Callable[[dict[str, Any]], dict[str, Any]]],
    ):
        self.settings = settings
        self.handlers = handlers
        self._pending: queue.Queue[str] = queue.Queue()
        self._worker: threading.Thread | None = None
        self._stop = threading.Event()
        self._lock = threading.Lock()
        self._init_db()

    # --- baza ---------------------------------------------------------------

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(self.settings.state_db, timeout=15)
        conn.row_factory = sqlite3.Row
        # WAL: agent zapisuje z wątku roboczego, a API czyta z wątku HTTP.
        conn.execute("PRAGMA journal_mode=WAL")
        return conn

    def _init_db(self) -> None:
        self.settings.state_db.parent.mkdir(parents=True, exist_ok=True)
        with self._connect() as conn:
            conn.executescript(SCHEMA)
            # Kolumny etapu doszły później — starsze bazy dostają je tutaj.
            columns = {row["name"] for row in conn.execute("PRAGMA table_info(jobs)")}
            if "stage" not in columns:
                conn.execute("ALTER TABLE jobs ADD COLUMN stage TEXT")
            if "progress" not in columns:
                conn.execute("ALTER TABLE jobs ADD COLUMN progress INTEGER")

    # --- cykl życia workera -------------------------------------------------

    def start(self) -> None:
        if self._worker and self._worker.is_alive():
            return
        # Kasujemy sygnał zatrzymania — bez tego kolejka po jednym stop/start
        # (przeładowanie uvicorna, restart w tym samym procesie) startowałaby
        # z wątkiem, który natychmiast kończy pętlę, i zadania wisiałyby w „queued".
        self._stop.clear()
        self._recover_interrupted()
        self._worker = threading.Thread(target=self._run, name="virthub-jobs", daemon=True)
        self._worker.start()
        log.info("Wątek roboczy kolejki zadań wystartował")

    def stop(self) -> None:
        self._stop.set()
        self._pending.put("__stop__")
        if self._worker:
            self._worker.join(timeout=10)
            self._worker = None

    def _recover_interrupted(self) -> None:
        """Zadania przerwane restartem agenta wracają do kolejki.

        Operacje muszą być idempotentne w takim stopniu, żeby ponowienie nie
        zepsuło stanu — dlatego np. create_vm sprząta po sobie przy błędzie.
        """
        with self._connect() as conn:
            rows = conn.execute(
                "SELECT job_id FROM jobs WHERE status IN (?, ?)",
                (JobStatus.RUNNING.value, JobStatus.QUEUED.value),
            ).fetchall()
            conn.execute(
                "UPDATE jobs SET status = ? WHERE status = ?",
                (JobStatus.QUEUED.value, JobStatus.RUNNING.value),
            )
        for row in rows:
            self._pending.put(row["job_id"])
        if rows:
            log.warning("Wznowiono %s zadań przerwanych restartem agenta", len(rows))

    # --- API kolejki --------------------------------------------------------

    def enqueue(
        self,
        action: str,
        payload: dict[str, Any],
        *,
        uuid: str | None = None,
        server_id: int | None = None,
    ) -> str:
        if action not in self.handlers:
            raise ValueError(f"Nieznana akcja: {action}")

        job_id = str(uuidlib.uuid4())
        with self._connect() as conn:
            conn.execute(
                "INSERT INTO jobs (job_id, action, uuid, server_id, payload, status, created_at)"
                " VALUES (?, ?, ?, ?, ?, ?, ?)",
                (
                    job_id,
                    action,
                    uuid,
                    server_id,
                    json.dumps(payload, default=str),
                    JobStatus.QUEUED.value,
                    time.time(),
                ),
            )
        self._pending.put(job_id)
        log.info("Zakolejkowano zadanie %s (%s)", job_id, action)
        return job_id

    def get(self, job_id: str) -> JobState | None:
        with self._connect() as conn:
            row = conn.execute("SELECT * FROM jobs WHERE job_id = ?", (job_id,)).fetchone()
        return self._to_state(row) if row else None

    def recent(self, limit: int = 50) -> list[JobState]:
        with self._connect() as conn:
            rows = conn.execute(
                "SELECT * FROM jobs ORDER BY created_at DESC LIMIT ?", (limit,)
            ).fetchall()
        return [self._to_state(row) for row in rows]

    def unreported(self) -> list[JobState]:
        """Zakończone zadania, których wyniku nie udało się jeszcze odesłać."""
        with self._connect() as conn:
            rows = conn.execute(
                "SELECT * FROM jobs WHERE reported = 0 AND status IN (?, ?)"
                " ORDER BY finished_at ASC LIMIT 100",
                (JobStatus.DONE.value, JobStatus.FAILED.value),
            ).fetchall()
        return [self._to_state(row) for row in rows]

    def set_progress(self, job_id: str, stage: str, percent: int | None) -> None:
        with self._connect() as conn:
            conn.execute(
                "UPDATE jobs SET stage = ?, progress = ? WHERE job_id = ?",
                (stage, None if percent is None else max(0, min(100, int(percent))), job_id),
            )

    def mark_reported(self, job_id: str) -> None:
        with self._connect() as conn:
            conn.execute("UPDATE jobs SET reported = 1 WHERE job_id = ?", (job_id,))

    # --- wykonanie ----------------------------------------------------------

    def _run(self) -> None:
        while not self._stop.is_set():
            job_id = self._pending.get()
            if job_id == "__stop__":
                break
            try:
                self._execute(job_id)
            except Exception:
                log.exception("Nieobsłużony błąd przy wykonaniu zadania %s", job_id)

    def _execute(self, job_id: str) -> None:
        with self._connect() as conn:
            row = conn.execute("SELECT * FROM jobs WHERE job_id = ?", (job_id,)).fetchone()
            if row is None:
                log.error("Zadanie %s zniknęło z bazy", job_id)
                return
            conn.execute(
                "UPDATE jobs SET status = ? WHERE job_id = ?",
                (JobStatus.RUNNING.value, job_id),
            )

        action = row["action"]
        payload = json.loads(row["payload"])
        started = time.time()

        _current.queue, _current.job_id = self, job_id
        try:
            result = self.handlers[action](payload)
            status, error = JobStatus.DONE, None
        except Exception as exc:
            log.exception("Zadanie %s (%s) nie powiodło się", job_id, action)
            result, status, error = None, JobStatus.FAILED, str(exc)
        finally:
            _current.queue = _current.job_id = None

        with self._connect() as conn:
            conn.execute(
                "UPDATE jobs SET status = ?, result = ?, error = ?, finished_at = ?"
                " WHERE job_id = ?",
                (
                    status.value,
                    json.dumps(result, default=str) if result is not None else None,
                    error,
                    time.time(),
                    job_id,
                ),
            )

        log.info(
            "Zadanie %s (%s) zakończone: %s w %.1fs",
            job_id, action, status.value, time.time() - started,
        )

    @staticmethod
    def _to_state(row: sqlite3.Row) -> JobState:
        return JobState(
            job_id=row["job_id"],
            action=row["action"],
            uuid=row["uuid"],
            server_id=row["server_id"],
            status=JobStatus(row["status"]),
            result=json.loads(row["result"]) if row["result"] else None,
            error=row["error"],
            created_at=row["created_at"],
            finished_at=row["finished_at"],
            stage=row["stage"] if "stage" in row.keys() else None,
            progress=row["progress"] if "progress" in row.keys() else None,
        )
