#!/usr/bin/env python3
"""SEC-R03 negative PostgreSQL 18 dump/restore + lab-only external serving gate.

This runner is deliberately synthetic and refuses any non-lab compose or path.
A trusted runner controls fence state outside the DB dump. It is NOT a durable
distributed monotonic authority; full-host restore and bypass of Z2 remain open.
"""
from __future__ import annotations
import json
import os
from pathlib import Path
import subprocess

BASE = "apps/portal/labs/r07-f1/compose.yml"
OVERRIDE = "apps/portal/labs/r07-f1/r03/compose.yml"
if os.environ.get("R07_COMPOSE") != BASE:
    raise SystemExit("REFUSE non-lab base compose")
if os.environ.get("R07_R03_OVERRIDE") != OVERRIDE:
    raise SystemExit("REFUSE non-lab override")
runner = Path(os.environ["RUNNER_TEMP"]).resolve()
state_dir = Path(os.environ["R07_R03_FENCE_DIR"]).resolve()
if state_dir == runner or not state_dir.is_relative_to(runner):
    raise SystemExit("REFUSE non-ephemeral fence path")
state_dir.mkdir(parents=True, exist_ok=True)
state_file = state_dir / "state.json"
C = ["docker", "compose", "-f", BASE, "-f", OVERRIDE]
PG = C + ["exec", "-T", "pg"]
BASE_PSQL = PG + ["psql", "-X", "-U", "lab_owner", "-v", "ON_ERROR_STOP=1"]

def run(cmd, timeout=75):
    p = subprocess.run(cmd, text=True, capture_output=True, timeout=timeout)
    if p.returncode:
        raise AssertionError("Synthetic command failed (" + str(p.returncode)
                             + "): " + " ".join(cmd[:5]) + " " + p.stderr[-800:])
    return p.stdout.strip()

def sql(db: str, command: str) -> str:
    if db not in ("portal_r07_f1", "portal_r07_restored"):
        raise AssertionError("Non-lab database")
    return run(BASE_PSQL + ["-d", db, "-Atc", command])

def count_valid(db: str) -> str:
    return sql(db, """SELECT count(*) FROM r07.session s JOIN r07.account a
      ON a.actor_id=s.actor_id WHERE s.actor_id='actor-a'
      AND s.active AND a.active AND s.session_epoch=a.session_epoch
      AND s.expires_at > clock_timestamp()""")

def count_grants(db: str) -> str:
    return sql(db, """SELECT count(*) FROM r07.membership
      WHERE actor_id='actor-a' AND organization_id='org-a' AND can_read AND can_write""")

def state(status: str, generation: int = 2, reconciled: bool = False):
    payload = {"db": "portal_r07_restored", "state": status,
               "generation": generation, "reconciled": reconciled}
    tmp = state_dir / "state.tmp"
    tmp.write_text(json.dumps(payload, sort_keys=True))
    tmp.replace(state_file)

def probe(expected: int):
    output = run(C + ["run", "--rm", "--no-deps", "-e",
                      f"R03_EXPECT={expected}", "edge"], timeout=45)
    if "[PASS R03-HTTP-READ]" not in output or "[PASS R03-HTTP-WRITE]" not in output:
        raise AssertionError("Incomplete Z1 HTTP probe")
    print(output, flush=True)

state("closed")
run(C + ["up", "-d", "--build", "pg", "fence", "restored"], timeout=180)
probe(503)
print("[PASS R03-N01] serving OFF before restore; both HTTP operations denied", flush=True)
if count_valid("portal_r07_f1") != "1" or count_grants("portal_r07_f1") != "1":
    raise AssertionError("Expected active old session and grant before snapshot")
run(PG + ["pg_dump", "-U", "lab_owner", "-Fc", "-f",
          "/tmp/r03-pre-revocation.dump", "portal_r07_f1"])
sql("portal_r07_f1", "UPDATE r07.account SET session_epoch=2 WHERE actor_id='actor-a'")
sql("portal_r07_f1", """UPDATE r07.membership SET can_read=false,can_write=false
    WHERE actor_id='actor-a' AND organization_id='org-a'""")
if count_valid("portal_r07_f1") != "0" or count_grants("portal_r07_f1") != "0":
    raise AssertionError("Live revocation failed")
state("closed", generation=2)

run(PG + ["createdb", "-U", "lab_owner", "portal_r07_restored"])
# Never grant Z2 CONNECT to the snapshot until reconciliation has been checked.
sql("portal_r07_f1", "REVOKE CONNECT ON DATABASE portal_r07_restored FROM PUBLIC")
run(PG + ["pg_restore", "--no-owner", "-U", "lab_owner",
          "-d", "portal_r07_restored", "/tmp/r03-pre-revocation.dump"], timeout=90)
if count_valid("portal_r07_restored") != "1" or count_grants("portal_r07_restored") != "1":
    raise AssertionError("Restore did not reproduce stale snapshot state")
probe(503)
print("[PASS R03-N02] stale session and grants restored in PG; HTTP fenced", flush=True)
# A forged/accidental open flag with OLD generation is not enough.
state("open", generation=1, reconciled=True)
probe(503)
print("[PASS R03-N02B] outdated fence generation denied", flush=True)
state_file.write_text("{broken json")
probe(503)
print("[PASS R03-N09] corrupt external fence state fails closed", flush=True)
state_file.unlink()
probe(503)
print("[PASS R03-N03] missing external fence state fails closed", flush=True)
state("closed")
# Narrow, trusted operator reconciliation. An actual recovery service still
# needs to prove provenance and current revocation history for EVERY principal.
sql("portal_r07_restored", """UPDATE r07.account SET session_epoch=
    GREATEST(session_epoch,2) WHERE actor_id='actor-a'""")
sql("portal_r07_restored", "UPDATE r07.session SET active=false WHERE actor_id='actor-a'")
sql("portal_r07_restored", """UPDATE r07.membership
    SET can_read=false,can_write=false
    WHERE actor_id='actor-a' AND organization_id='org-a'""")
if count_valid("portal_r07_restored") != "0" or count_grants("portal_r07_restored") != "0":
    raise AssertionError("Reconciliation incomplete")
if sql("portal_r07_restored",
       "SELECT count(*) FROM r07.effect WHERE organization_id='org-b'") != "0":
    raise AssertionError("Cross-tenant B effect found")
# This GRANT is exclusively for the synthetic, freshly reconciled clone.
sql("portal_r07_f1", "GRANT CONNECT ON DATABASE portal_r07_restored TO r07_authority")
state("open", generation=2, reconciled=True)
probe(401)
if sql("portal_r07_restored",
       "SELECT count(*) FROM r07.effect WHERE organization_id='org-b'") != "0":
    raise AssertionError("B effect after HTTP request")
print("[PASS R03-N04/N05/N11] reopened lab clone rejects old session; old grants absent; B untouched", flush=True)
state_file.unlink()
probe(503)
print("[PASS R03-N03B] losing fence after reopening immediately denies HTTP", flush=True)
print("[NOT RUN] N06 intents, N07 outbox, N08 pooling, N10 concurrent restore/revoke: no operational fixtures", flush=True)
print("[R03] All implemented synthetic assertions PASS. Restore anti-rollback is NOT production-homologated.", flush=True)
