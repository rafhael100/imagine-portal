# F370 — witness-first revocation and failed local commit

A functioning independent witness alone is **not enough**: updating a Portal session first and advancing the witness later leaves a dangerous crash window in which an old snapshot might still look current. F370 hardens the **disposable two-cluster PG18 CI** to rehearse a witness-first ordering:

1. Capture an old Portal backup with a valid **synthetic** session and epoch 12.
2. Advance the independent witness to epoch 13 **before** committing any local revocation.
3. Inject a PostgreSQL error after an attempted session UPDATE but before local commit. The transaction rolls back, and the source session is still active at epoch 12. The independent witness remains 13; the F355 preflight must return `BLOCKED` with HTTP authorization false.
4. Commit the session revocation and a local snapshot-epoch change to 13 in **one** source transaction. Now the source session is invalid and both values match; the F355 tool returns `LAB_CONSISTENT_STILL_NO_SERVING` (not GO).
5. Restore the earlier epoch-12 backup into an isolated database: the old session appears SQL-valid there, but F355 and signed F369 attestation from independent PG18 refuse the mismatch. The F365 quarantine then invalidates it.

This models a potential safe ordering but **does not implement** atomic cross-store delivery, fencing, a safe recovery coordinator, network outage handling or mandatory witness checks in the actual authentication adapter. In particular, **step 3 demonstrates that the existing SQL auth function could still accept the active session while the external witness is ahead**. Public serving must therefore remain disabled until every relevant request is protected by an approved independent online serving fence; the simulation alone does not enforce that.

All data/keys are synthetic; no production credentials, VPS shell, customer information, new schema migration, services, spending or deployment. Changes affect only GitHub-hosted disposable CI, and Portal issue #64 remains open/NO-GO.
