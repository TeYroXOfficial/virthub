"""Etapy zadań: driver zgłasza, co robi, a panel pokazuje to klientowi."""

from __future__ import annotations

import dataclasses
import sqlite3
import threading

from agent.jobs import JobQueue, progress
from test_provisioning import create_vm, wait_for_job


def test_reinstalacja_zglasza_etapy(client, template):
    created = create_vm(client, template, server_id=3301)
    assert created["stage"] == "boot" and created["progress"] == 85

    job = client.post(f"/vm/{created['result']['uuid']}/rebuild", json={"template": template})
    state = wait_for_job(client, job.json()["job_id"])
    assert state["status"] == "done"
    assert state["stage"] == "boot"
    assert state["progress"] == 85


def test_etap_widac_w_trakcie_zadania(settings, tmp_path):
    release = threading.Event()
    seen = {}

    def handler(_payload):
        progress("image", 30)
        release.wait(5)
        return {}

    q = JobQueue(dataclasses.replace(settings, state_db=tmp_path / "jobs.db"), {"work": handler})
    q.start()
    try:
        job_id = q.enqueue("work", {})
        for _ in range(200):
            state = q.get(job_id)
            if state.stage:
                seen["stage"], seen["progress"] = state.stage, state.progress
                break
            threading.Event().wait(0.01)
        release.set()
    finally:
        q.stop()

    assert seen == {"stage": "image", "progress": 30}


def test_progress_poza_zadaniem_nic_nie_robi():
    progress("image", 10)  # bez wyjątku


def test_stara_baza_dostaje_kolumny_etapu(settings, tmp_path):
    db = tmp_path / "old.db"
    with sqlite3.connect(db) as conn:
        conn.execute(
            "CREATE TABLE jobs (job_id TEXT PRIMARY KEY, action TEXT NOT NULL, uuid TEXT, server_id INTEGER,"
            " payload TEXT NOT NULL, status TEXT NOT NULL, result TEXT, error TEXT, created_at REAL NOT NULL,"
            " finished_at REAL, reported INTEGER NOT NULL DEFAULT 0)"
        )
        conn.execute("INSERT INTO jobs (job_id, action, payload, status, created_at) VALUES ('a', 'x', '{}', 'done', 1)")

    q = JobQueue(dataclasses.replace(settings, state_db=db), {"x": lambda p: {}})
    assert q.get("a").stage is None
