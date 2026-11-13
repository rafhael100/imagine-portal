# F391 — Tenant-scoped restricted PostgreSQL 18 digest status after external intent

F390 can reconcile an independently signed intent by SHA256 of the session reference, without storing the original bearer. However the existing `portal_browser_revoke(digest,proof)` is idempotent and returns TRUE even when there was **no matching session**. F391 fixes the **diagnostic ambiguity** through a separate read-only SQL candidate.

## Review-only SQL extension

`database/security/portal_browser_revoke_status_f391_candidate.sql` is **not included in any Laravel migration or VPS deployment manifest**. The explicit GitHub-hosted disposable PostgreSQL 18 test installs it only after verifying that the browser identity security roles and FORCE RLS table already exist.

The `public.portal_browser_revoke_status_candidate(digest,organization_uuid,proof_hmac)` SQL SECURITY DEFINER function:

- Is owned by the existing restricted NOLOGIN `portal_browser_owner`, with `row_security=on`, schema-fixed search path, and EXECUTE granted **only** to the existing `portal_browser_runtime` role. No public grants or direct runtime table reads.
- Requires a canonical 64-character lowercase SHA256, an exact server-controlled organization UUID, and a HMAC proof signed over `portal-browser-v1|revoke-status|<uuid>|<digest>` using the existing Portal private proof key. It checks the proof before touching the target table.
- Returns only `revoked` when the row's `status` is revoked **and** `revoked_at` is present; `not_revoked` for active/pending MFA rows; `missing` when no row belongs to that tenant; `inconsistent` for contradictions; `denied` for bad inputs/proofs. It never reveals identity, email, secrets, session token, permissions, or raw tenant records.
- Uses restricted SQL role as an observation, not a claim of independent witness integrity or serving.

The existing `PostgresPortalBrowserAuthentication` exposes this functionality only through `PortalHashedSessionStatusCandidate` and retains its role, autocommit, key handling, and SQL errors redaction. New `SignedRevocationDigestStatusPgReconcileCandidate` delegates signed-intent checks and actual digest revocation to F390, and **only then** queries the restricted status. A test-only outcome `LAB_PG_ROW_REVOKED_STILL_NO_SERVING` requires this exact row-level result. Every result continues to set HTTP, business, session-issuance, recovery/deploy and `canClaimPostgresRevoked` flags to **false**, because the result is not an operational certificate.

## Test plan — actual PG18 + real Worker

A standalone GitHub CI provisions a fresh disposable PostgreSQL 18 cluster with existing Portal operator identity and runtime role. It explicitly installs F391 SQL, rejects different roles/public execution and direct table SELECTs, confirms a forged HMAC is denied, seeds a synthetic active session **without persisting its original bearer** and runs the ACTUAL F376/F386/F387 Worker in a separate network-disabled Node24 container with HMAC publisher and Ed25519 signer. PHP verifies that exact signature and then runs F390 digest reconciliation plus F391 readback.

Tests cover active/missing/other-tenant/malformed digests, forged proof, invalid external signature/nonce/intent, forced PostgreSQL UPDATE failure, a retry leading to a physically revoked row and idempotent second retry, plus a missing hash for which the SQL revoke function returns TRUE but the new status remains `missing`.

## Important no-go boundaries

This is **not** a transactional, simultaneous remote+local authorization fence. F391 does not eliminate races between status read and HTTP serving. A stale/restored local DB can report an earlier revoked row without proving the independent online witness has not moved. The SQL status function is an experimental additional restricted attack surface and requires DBA/AppSec review before any migration or runtime use. There is no durable queue scheduling, externally controlled D1/keys, reliable remote signed-ack delivery, retry leasing, tenant-wide permission/resource fencing, failover, crash-replay or disaster-recovery acceptance.

No public Laravel route, live Cloudflare Worker, Leaseweb VPS, production DB migration, main merge, real customer data, DNS changes or new expense. Security issue #64 remains OPEN/NO-GO.
