#!/usr/bin/env python3
"""R07-SEC-R02 only: real disposable PostgreSQL job/admin/restore probes.

Invoked by the dedicated GH Actions workflow, never by Laravel/production.
No user records, passwords in source, DNS external requests, or real backups.
"""
from __future__ import annotations

import os
import subprocess
import sys

COMPOSE_FILE = "apps/portal/labs/r07-f1/compose.yml"
if os.getenv("R07_COMPOSE") != COMPOSE_FILE:
    raise SystemExit("REFUSE: not the explicit R07 synthetic compose")
if len(sys.argv) != 2 or sys.argv[1] not in ("before", "after"):
    raise SystemExit("Usage: writer_restore_audit.py before|after")
PHASE = sys.argv[1]
COMPOSE = ["docker", "compose", "-f", COMPOSE_FILE]
PG = COMPOSE + ["exec", "-T", "pg"]
PSQL = PG + [
    "psql", "-X", "-U", "lab_owner", "-d", "portal_r07_f1",
    "-v", "ON_ERROR_STOP=1",
]


def run(args: list[str], *, allow_failure: bool = False, timeout: int = 25):
    result = subprocess.run(args, capture_output=True, text=True, timeout=timeout)
    if not allow_failure and result.returncode != 0:
        raise AssertionError(
            "Synthetic command failed: " + " ".join(args[:5])
            + " ... " + result.stderr[-900:]
        )
    return result


def sql(command: str) -> str:
    return run(PSQL + ["-Atc", command]).stdout.strip()


def assert_denied(label: str, statement: str):
    attempt = run(PSQL + [
        "-c", "SET ROLE r07_job_no_dml; " + statement
    ], allow_failure=True)
    if attempt.returncode == 0 or "permission denied" not in attempt.stderr.lower():
        raise AssertionError("Unexpected lab SQL privileges: " + label)
    print("[PASS " + label + "] Synthetic job role cannot use private DML/SELECT.", flush=True)


def edge_denied():
    result = run(COMPOSE + [
        "run", "--rm", "-e", "R07_PHASE=after", "edge"
    ], timeout=25)
    if "[PASS] R07-T14" not in result.stdout or "[PASS] R07-T15" not in result.stdout:
        raise AssertionError("Authority did not deny both session operations")


if PHASE == "before":
    role = sql(
        "SELECT CASE WHEN NOT rolcanlogin AND NOT rolbypassrls "
        "THEN 1 ELSE 0 END FROM pg_roles WHERE rolname='r07_job_no_dml'"
    )
    if role != "1":
        raise AssertionError("Synthetic job role is not NOLOGIN/non-BYPASSRLS")
    print("[PASS SEC-R02-J00] Job role NOLOGIN, no BYPASSRLS.", flush=True)

    assert_denied("SEC-R02-J01",
                  "SELECT payload FROM r07.private_resource LIMIT 1;")
    assert_denied("SEC-R02-J02",
                  "UPDATE r07.membership SET can_write=true "
                  "WHERE organization_id='org-b';")
    assert_denied("SEC-R02-J03",
                  "INSERT INTO r07.effect "
                  "(organization_id,effect_id,resource_id,actor_id,command_sha256) "
                  "VALUES ('org-b','job-b','resource-b','actor-b',"
                  "repeat('a',64));")
    if sql("SELECT count(*) FROM r07.effect WHERE organization_id='org-b'") != "0":
        raise AssertionError("Job attempt created B effect")

    # Administration is a PRIVILEGED LAB OWNER in the runner, not a customer API.
    run(PSQL + ["-c", "UPDATE r07.session SET active=false "
                "WHERE actor_id='actor-a'"])
    edge_denied()
    print("[PASS SEC-R02-A01] Session disabled by admin blocks read/effect.", flush=True)
    run(PSQL + ["-c", "UPDATE r07.session SET active=true "
                "WHERE actor_id='actor-a'"])

    run(PSQL + ["-c", "UPDATE r07.account SET active=false "
                "WHERE actor_id='actor-a'"])
    edge_denied()
    print("[PASS SEC-R02-A02] Account disabled by admin blocks read/effect.", flush=True)
    run(PSQL + ["-c", "UPDATE r07.account SET active=true "
                "WHERE actor_id='actor-a'"])

    if sql("SELECT count(*) FROM r07.effect WHERE organization_id='org-a'") != "2":
        raise AssertionError("Admin probes unexpectedly modified valid A effects")
    if sql("SELECT count(*) FROM r07.effect WHERE organization_id='org-b'") != "0":
        raise AssertionError("Admin probes unexpectedly inserted B effect")

    # Snapshot is intentionally BEFORE workflow revokes account.session_epoch.
    snapshot = run(PG + [
        "pg_dump", "-U", "lab_owner", "-Fc",
        "-f", "/tmp/r07-pre-revoke-synthetic.dump", "portal_r07_f1"
    ])
    del snapshot
    print("[PASS SEC-R02-B00] Synthetic PG18 snapshot captured before epoch cut.", flush=True)

if PHASE == "after":
    live = sql(
        "SELECT count(*) FROM r07.session s "
        "JOIN r07.account a ON a.actor_id=s.actor_id "
        "WHERE s.actor_id='actor-a' AND s.active AND a.active "
        "AND s.session_epoch=a.session_epoch "
        "AND s.expires_at>clock_timestamp()"
    )
    if live != "0":
        raise AssertionError("The main synthetic database did not revoke A")

    # Restore to a DIFFERENT disposable DB, NEVER connected to web authority.
    run(PG + ["createdb", "-U", "lab_owner", "portal_r07_restored"])
    run(PSQL + [
        "-c", "REVOKE CONNECT ON DATABASE portal_r07_restored "
              "FROM PUBLIC, r07_authority, r07_job_no_dml"
    ])
    run(PG + [
        "pg_restore", "--no-owner", "--no-acl",
        "-U", "lab_owner", "-d", "portal_r07_restored",
        "/tmp/r07-pre-revoke-synthetic.dump"
    ], timeout=40)

    restored = run(PG + [
        "psql", "-X", "-U", "lab_owner", "-d", "portal_r07_restored",
        "-v", "ON_ERROR_STOP=1", "-Atc",
        "SELECT count(*) FROM r07.session s JOIN r07.account a "
        "ON a.actor_id=s.actor_id "
        "WHERE s.actor_id='actor-a' AND s.active AND a.active "
        "AND s.session_epoch=a.session_epoch "
        "AND s.expires_at>clock_timestamp()"
    ]).stdout.strip()
    if restored != "1":
        raise AssertionError(
            "Expected to reproduce stale-session restore hazard; got " + restored
        )

    print("[PASS SEC-R02-B01] LIVE PG18 epoch cut denies actor A.", flush=True)
    print(
        "[KNOWN GAP RESTORE] A pre-revocation PostgreSQL snapshot restores "
        "a seemingly valid actor-A session (live=0; isolated restored=1). "
        "Never serve restored DB without independent anti-rollback reconciliation.",
        flush=True,
    )
    # Extra protection: clone is not pointed to by any service, has no
    # PUBLIC/Z2 CONNECT. The runner is a privileged adversarial lab admin.
    connect = sql(
        "SELECT has_database_privilege("
        "'r07_authority','portal_r07_restored','CONNECT')::int"
    )
    if connect != "0":
        raise AssertionError("Restored clone is accessible to the authority role")
    print("[PASS SEC-R02-B02] Restored clone not CONNECT-able by Z2.", flush=True)
    run(PG + ["dropdb", "-U", "lab_owner", "portal_r07_restored"])
    print("[R07-SEC-R02] Disposable restored database destroyed.", flush=True)
