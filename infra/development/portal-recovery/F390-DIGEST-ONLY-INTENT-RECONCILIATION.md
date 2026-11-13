# F390 — Digest-only recovery from independently recorded revocation intent

The previous F389 cross-process experiment passes a raw 256-bit session reference in a private fixture between the PostgreSQL seed process and its PHP retry. That design is unacceptable as a durable retry queue: the original bearer should not need to be persisted or transported after a process crash.

F390 removes the **raw session bearer from the recovery workflow**. The actual PostgreSQL 18 seed process generates a fresh 32-byte random session reference and stores its SHA256 digest in the real Portal session table. It writes only organization, instance and the digest to a mode-0600 disposable fixture. It discards the original reference before any Node Worker/reconciler process starts.

A separate, network-disabled Node 24 process runs the actual F376/F386/F387 Worker code against ephemeral SQLite, inserts an append-only authenticated revocation intent and produces a fresh HMAC-authenticated and Ed25519-signed F387 readback tied to the exact SHA256 and tenant. PHP then checks the real signed receipt and accesses the existing restricted PG18 runtime role **without ever seeing the original session reference**.

The new review-only `PortalHashedSessionRevocationCandidate` contract and `SignedRevocationDigestPgReconcileCandidate` require exact server-pinned tenant, instance, random intent ID, digest, one-time nonce, signature and short TTL before calling `revokeSessionDigest()`. The existing `PostgresPortalBrowserAuthentication` implementation reuses **the existing** `portal_browser_revoke(digest,HMAC)` SECURITY DEFINER function, its proof key and restricted/autocommit role; there are **no new SQL functions, additional runtime SQL grants, or schema migrations**.

## Crucial limitation: success is NOT proof of a revoked row

The existing `portal_browser_revoke` function is idempotent and returns `TRUE` even when no matching row exists. F390's candidate therefore returns only `LAB_REVOKE_FUNCTION_ACCEPTED_NO_STATUS_PROOF`; all authorization, HTTP, deployment, and `canClaimPostgresRevoked` flags remain **false**, even on apparent success. An independent trusted readback of a specific row (or a stronger future SQL return contract) is required before the reconciliation workflow can reliably mark an intention fulfilled.

The CI injects a real PostgreSQL trigger error, confirms that a valid independent Ed25519 intent does not force local revocation during SQL outage, then drops the error and retries the same signed intention until the ADMIN-ONLY CI inspection observes `portal_auth_sessions.status='revoked'`. It explicitly confirms missing digest SQL calls may be idempotently accepted but **never authorize or claim revocation**, and forged/nonmatching receipts or malformed digests cannot change SQL state.

## Security boundaries and outstanding work

No operational, persistent independent witness service, no durable intent queue worker, no post-crash bearer escrow, no D1 protected external administrator and off-host signing key custody, no scalable tenant IAM, and no PostgreSQL *restricted revocation status* function that can distinguish missing/revoked rows. No positive serving middleware, cross-host atomicity, admin rollback-proof service, failover, key rotation, regional DR/restore or production audits are complete.

Everything is intentionally disposable CI under one runner trust domain; F390's use of an admin SQL inspector in **tests only** is not permissible as a production reconciliation strategy. There are no Laravel route bindings, public endpoints, Cloudflare deployments, real customer data, VPS actions, GitHub main merges or additional expenses. Issue #64 remains OPEN/NO-GO.
