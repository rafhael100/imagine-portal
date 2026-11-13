# F387 — Resolve unknown F386 network outcomes without claiming local session revocation

The F386 independent revocation intent POST is witness-first and records a hashed session reference plus a monotonic sequence. A network failure AFTER remote commit but BEFORE the HTTP response is **ambiguous**: retrying the POST must not invent a successful result or duplicate the append-only intent.

F387 adds a **dormant, server-to-server signed readback** endpoint on the existing candidate Worker:

- GET /v1/portal/revocation-intents/{intent_id}, where the intent ID is a fixed random 16-byte lowercase hex identifier.
- Requires an independent 32-byte HMAC publisher key, timestamp freshness within 30 seconds, and a newly generated 256-bit nonce supplied in X-Imagine-Witness-Nonce.
- The HMAC covers the exact path, method GET, timestamp and nonce using its own signature domain; unknown/invalid tenant, unauthorized caller, invalid nonce and non-existent ID fail closed. The endpoint is never public/unprotected.
- A private D1 lookup verifies that the immutable intent exists AND the current witnessed sequence/session floor are still at least the recorded intent. If a privileged database administrator has rolled the witness below the intent, the endpoint returns 503, NOT a signed success.
- A valid request yields a fresh 30-second Ed25519 receipt signed in a separate domain. It contains the intent identifier, session SHA256, intent/witness sequence, session floor, organization/instance and exact status **pending_local_reconciliation**. No raw session token, SQL credentials, email, browser cookie, issuer secret or billing information appears.
- The Portal-side candidate `SignedRevocationIntentReadbackCandidateVerifier` pins trusted external signing public key, tenant, instance, requested ID, session digest, nonce, issued/expiry and signature. **Even matching receipts cannot claim PostgreSQL revocation or grant HTTP/business/recovery/deploy.**

### Disposable verification

GitHub Actions Node24 uses true in-memory SQLite transactions with a D1-compatible adapter, real F376/F386 Worker code and ephemeral Ed25519/HMAC keys. It simulates a successful remote F386 commit whose HTTP reply was lost, then confirms a fresh, authenticated and signed readback. Tampered keys, wrong ID, missing nonce, expired timestamp, absent intent, forged content, and administrative simulated rollback are denied. PHP 8.3/8.4 verifies the exact signed JSON in a **separate process** and checks that all authorization flags, including canClaimPostgresRevoked, are false. Existing F376 tests are also rerun.

### Remaining blockers

F387 does **not** implement actual HTTP/TLS PHP readback client, safe retries, post-failure revocation reconciliation against PG18, local SQL outbox, D1 admin-separated custody, independent service deployment, key rotation, long-term durability, privacy retention/erasure policy, rate limits, mTLS, HA, or production AppSec/DBA review. Even a fully signed receipt may only establish that an intention was recorded: it is NEVER proof that local PostgreSQL sessions were durably revoked. An external privileged administrator could still roll back D1; the in-memory test intentionally demonstrates this threat.

Everything is draft code and disposable CI. No Cloudflare deploy, public HTTP endpoint, VPS change, real customer data, GitHub main merge, DNS change, or expense. Issue #64 remains OPEN/NO-GO.
