# F396 — Signed revocation recovery triage that never skips PostgreSQL (NO SERVING)

F392 provides a signed cursor for discovering independent revocation intents, F393 an immutable ACK **claim**, F395 a capped retry lease, and F391 a restricted PostgreSQL18 session-status reader. These proofs are distinct. A dishonest or mistaken ACK publisher must never be able to tell the Portal to stop checking PostgreSQL, release HTTP authorization, or silently delete unprocessed work.

F396 adds a **dormant, pure read-only** policy `SignedRevocationRecoveryTriageCandidate` under Laravel's recovery infrastructure namespace, without dependency injection/container registration, public routes, SQL writes or remote requests.

The triage takes an F392 signed cursor page, signed F395 retry lease, optional signed F393 ACK-claim and expected per-request nonces, all from **trusted server-side worker context**. It verifies all Ed25519 signatures, organization, instance, intent ID, session SHA256 digest, lease ID hash, exact sequence and TTL through the existing strict PHP verifier classes before querying the restricted F391 status interface.

- A valid ACK **never skips** the restricted PostgreSQL read: when SQL reports `not_revoked` but ACK exists, it returns `ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES`.
- Even when SQL reports `revoked` and an ACK exists, the status is `SQL_REVOKED_WITH_ACK_CLAIM_STILL_NO_SERVING`, not proof of off-host fencing or HTTP authorization.
- Signed empty cursor pages mean `NO_NEXT_INTENT_NOT_QUEUE_COMPLETE`, *not* a globally finished queue.
- Unknown/missing SQL row, SQL errors, malformed signatures/nonces, absent lease, expired lease or five exhausted attempts return distinct fail-closed diagnostic codes. There are no automatic retries or release actions in F396.
- Every result has HTTP/business/session/recovery/deployment/PostgreSQL proof/queue complete/skip-reconciliation flags **FALSE**.

## CI evidence

The GitHub-hosted Node24 fixture executes the actual F376/F386/F392/F393/F395 Worker modules against ephemeral real SQLite and independently signs the resulting cursor, lease and ACK claim. Deliberately it emits an authenticated ACK claim **without any PostgreSQL read**, demonstrating the critical separation of trust. It also generates expired and exhausted lease receipts. Separate PHP 8.3/8.4 processes verify the actual Worker-signed receipts using sodium, a negative-only synthetic restricted SQL status adapter and injected active/revoked/missing/inconsistent/SQL-offline states. It proves that the ACK cannot bypass SQL, revoked status never authorizes serving, missing/exhausted work escalates, and empty pages cannot claim queue completion. Legacy F376 regression is included.

This is a laboratory policy only, not a deployed, scheduled or persistent worker. The PHP status interface uses a deliberately synthetic adapter in F396; the actual PG18 role and status read were independently tested in F391 and F394. No TLS client, externally administered Cloudflare/D1, rollback-proof custody, durable lease-checkpoint/ACK handoff, tenant IAM, HA, DR, positive resource fence or formal AppSec/DBA approval exists. F396 neither merges nor deploys to Leaseweb/Cloudflare, touches real customer data or increases cost. Issue #64 remains OPEN/NO-GO.
