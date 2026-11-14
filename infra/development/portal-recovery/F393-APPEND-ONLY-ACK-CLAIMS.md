# F393 — Append-only independent ACK claim for a recorded revocation

F390/F391 prove that a known SHA256 digest can be revoked and that the real restricted PostgreSQL 18 status candidate can distinguish `revoked`, `not_revoked`, and `missing`. F392 rediscovers independent immutable revocation intents after a process restart, but has **no completion acknowledgment** or durable worker lease/checkpoint.

F393 adds a distinct **ACK claim**, deliberately not an authoritative completion certificate, to the same **dormant Worker F376 prototype**. Its private candidate D1 schema `revocation_ack_claims` is append-only and foreign-keyed to the original F386 `revocation_intents`. SQL triggers reject UPDATE/DELETE, a missing/other-tenant intent, a mismatched hash or intent sequence, or an observed witness epoch behind the intent.

- `POST /v1/portal/revocation-acks` requires **ACK_CLAIM_HMAC_B64** (32-byte HMAC key different from the F386 publisher key and F392 cursor key). The HMAC covers the exact POST method, fixed path, timestamp within 30 seconds, and capped canonical JSON bytes. The body must contain exactly `organization`, `instance`, `intent_id`, `session_sha256`, `intent_sequence`, and `local_status`, where the only permitted value is `revoked_claimed`. The scope is pinned by Worker operator bindings. An accepted insert returns 204; a duplicate/replay fails closed, without overwriting the original claim.
- `GET /v1/portal/revocation-acks/{intent_id}` requires a **different** read-only `ACK_READ_HMAC_B64`, a fresh 256-bit nonce, and HMAC binding of path, timestamp and nonce. It reads exactly that immutable claim and checks witness epoch monotonicity before signing a 30-second Ed25519 receipt using a separate signature domain.
- The receipt status is **`ack_recorded_local_claim_unverified`**, never `postgres_revoked`. It includes tenant, intent, only SHA256 of the session reference, original intent sequence, current witness sequence/floor, claim timestamp, fresh nonce and expiry. No bearer, cookie or email leaves the process.
- `SignedRevocationAckClaimCandidateVerifier` validates the receipt's exact JSON keys, trusted public key, tenant, intent ID, session digest, nonce, sequence, TTL and Ed25519 signature. All outcomes set `canAuthorizeHttp`, `canAuthorizeBusiness`, `canIssueSession`, `canClaimPostgresRevoked`, `canClaimQueueFullyProcessed`, restore and deploy flags to **false**.

## Critical trust and operational limitations

**An HMAC-authenticated ACK publisher cannot independently attest that local PostgreSQL truly revoked the row.** The ACK only proves the signed statement was stored in the witness ledger. An erroneous/compromised publisher could claim success while a session is still active. F393 has NO production integration that calls F391 with audited SQL role and no independent attestation of PG server state; a real secure ACK protocol must first validate SQL revocation with a durable, authoritative and independently reviewed proof, and must not allow restoring old status or serving on ACK alone.

A duplicate ACK is blocked; after crash an authenticated readback can distinguish an existing claim from an absent claim but cannot make the claim trustworthy. The existing F392 cursor STILL enumerates past intents regardless of ACK presence, and does not mark them resolved, which is the conservative failure mode. F393 has no worker scheduler, lease, checkpoint, retry budget, key custody/separation in deployment, archive/retention or HA/DR.

## Test boundary

GitHub Actions PHP 8.3/8.4 + Node24 runs the real F376/F386/F393 Worker with disposable SQLite, actual immutable append-only SQL triggers and ephemeral HMAC/Ed25519 keys. Tests refuse unsupported keys, forged requests, missing or mismatched intents, replay, mutation/delete, wrong nonce, expired HMAC and simulated witness rollback. A PHP process verifies the actual JS Ed25519 bytes, including 17 independent positive and negative scenarios. The legacy F376 contract tests also run. These are **laboratory** proofs in one CI trust domain, not independent Cloudflare or production installations.

No public Laravel route, migrations, GitHub main merge, VPS Leaseweb change, Cloudflare deployment, DNS, customer data or extra cost. Issue #64 stays OPEN/NO-GO.
