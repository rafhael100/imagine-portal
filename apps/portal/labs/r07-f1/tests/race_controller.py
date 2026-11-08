#!/usr/bin/env python3
"""Host-runner orchestration of *real PG18* revocation/write races.

Do not run on a VPS/production DB. Uses ONLY the disposable Docker Compose
portal_r07_f1 project with synthetic actor-A data and no exposed PG port.
"""
import os
import subprocess
import sys
import time

COMPOSE = os.environ.get("R07_COMPOSE")
if COMPOSE != "apps/portal/labs/r07-f1/compose.yml":
    raise SystemExit("Refusing non-lab Compose file")

BASE = ["docker", "compose", "-f", COMPOSE]
PG = BASE + ["exec", "-T", "pg", "psql", "-X", "-U", "lab_owner",
             "-d", "portal_r07_f1", "-v", "ON_ERROR_STOP=1"]


def invoke(command, *, timeout=20, allow_failure=False):
    result = subprocess.run(command, text=True, stdout=subprocess.PIPE,
                            stderr=subprocess.PIPE, timeout=timeout, check=False)
    if not allow_failure and result.returncode != 0:
        raise AssertionError(
            "Lab step failed: " + " ".join(command[:6]) + " ... "
            + result.stderr[-1300:])
    return result


def query(sql):
    return invoke(PG + ["-Atc", sql], timeout=6).stdout.strip()


def wait_sql(name, condition):
    deadline = time.monotonic() + 12
    while time.monotonic() < deadline:
        if "writer" in name and "writer" in globals() and writer.poll() is not None:
            out, err = writer.communicate()
            raise AssertionError("Writer exited before holding a barrier: " + err[-900:] + out[-900:])
        if query(condition) == "1":
            return
        time.sleep(0.12)
    raise AssertionError("Timed out waiting for " + name)


def edge(phase, *, background=False):
    command = BASE + ["run", "--rm", "-e", "R07_PHASE=" + phase, "edge"]
    if background:
        return subprocess.Popen(command, stdout=subprocess.PIPE,
                                stderr=subprocess.PIPE, text=True)
    result = invoke(command, timeout=20)
    if "[PASS]" not in result.stdout:
        raise AssertionError("Edge phase did not report assertions: " + phase)
    return result


# 1: Writer holds a shared advisory lock; a concurrent revoke must WAIT.
writer = edge("race_writer", background=True)
try:
    wait_sql("the writer holding its transaction",
             "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END "
             "FROM pg_stat_activity WHERE usename='r07_authority' "
             "AND state='active' AND query LIKE '%SELECT pg_sleep(4.0)%'")
    blocked = invoke(
        PG + ["-c", "SET lock_timeout='350ms'; "
              "UPDATE r07.membership SET can_write=false "
              "WHERE actor_id='actor-a' AND organization_id='org-a';"],
        timeout=6, allow_failure=True,
    )
    if blocked.returncode == 0 or "lock timeout" not in blocked.stderr.lower():
        raise AssertionError("Revocation did not demonstrably wait for writer's barrier")
    out, err = writer.communicate(timeout=12)
    if writer.returncode != 0 or "[PASS] R07-T19" not in out:
        raise AssertionError("Earlier writer did not commit before revocation: " + err[-1200:])
    print("[PASS R07-RACE-01] Revoke blocked by in-flight writer; pre-cut effect committed.", flush=True)
finally:
    if writer.poll() is None:
        writer.kill()
        writer.communicate()

count = query("SELECT count(*) FROM r07.effect WHERE effect_id='race-before-revoke'")
if count != "1":
    raise AssertionError("Expected exactly one pre-cut effect")

# After commit revocation becomes effective. Reusing A session cannot write.
invoke(PG + ["-c", "UPDATE r07.membership SET can_write=false "
             "WHERE actor_id='actor-a' AND organization_id='org-a'"])
edge("race_after")
if query("SELECT count(*) FROM r07.effect WHERE effect_id='race-post-cut'") != "0":
    raise AssertionError("Post-cut effect appeared")
print("[PASS R07-RACE-02] After revocation, subsequent effect denied by Z2.", flush=True)

# 2: Revoke owns the exclusive lock first; writer must wait and re-read grants.
invoke(PG + ["-c", "UPDATE r07.membership SET can_write=true "
             "WHERE actor_id='actor-a' AND organization_id='org-a'"])
revoker_command = (
    "BEGIN; "
    "UPDATE r07.membership SET can_write=false "
    "WHERE actor_id='actor-a' AND organization_id='org-a'; "
    "SELECT pg_sleep(2.5); "
    "COMMIT;"
)
revoker = subprocess.Popen(PG + ["-c", revoker_command],
                           stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                           text=True)
try:
    wait_sql("the revoker holding its transaction",
             "SELECT CASE WHEN count(*) > 0 THEN 1 ELSE 0 END "
             "FROM pg_stat_activity WHERE usename='lab_owner' "
             "AND pid <> pg_backend_pid() AND state='active' "
             "AND query LIKE '%SELECT pg_sleep(2.5)%'")
    edge("race_revoker_first")
    out, err = revoker.communicate(timeout=10)
    if revoker.returncode != 0:
        raise AssertionError("Revoker failed: " + err[-1300:])
    if query("SELECT count(*) FROM r07.effect WHERE effect_id='race-after-revoke'") != "0":
        raise AssertionError("Revoker-first unexpectedly allowed a new effect")
    print("[PASS R07-RACE-03] Revoker committed first; concurrent writer denied.", flush=True)
finally:
    if revoker.poll() is None:
        revoker.kill()
        revoker.communicate()

invoke(PG + ["-c", "UPDATE r07.membership SET can_write=true "
             "WHERE actor_id='actor-a' AND organization_id='org-a'"])
if query("SELECT count(*) FROM r07.effect WHERE organization_id='org-a'") != "2":
    raise AssertionError("Expected exactly two independent permitted A effects")
if query("SELECT count(*) FROM r07.effect WHERE organization_id='org-b'") != "0":
    raise AssertionError("Cross-tenant effect was inserted")
print("[PASS R07-RACE-04] DB confirms two allowed A effects, zero effects B.", flush=True)
