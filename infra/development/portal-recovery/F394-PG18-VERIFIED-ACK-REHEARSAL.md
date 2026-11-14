# F394 — PG18-verified revocation before append-only ACK claim (review-only)

F393 implemented a separate HMAC-authorized ACK writer and Ed25519 readback but could not independently ensure a caller truthfully observed local PostgreSQL session revocation. F394 connects the existing **real PG18 F391 status** and **Worker F393 ACK** in a disposable, sequential, *cross-process integration rehearsal*.

F394's new **unregistered** PHP `Pg18VerifiedRevocationAckPreparationCandidate` uses the F391 `SignedRevocationDigestStatusPgReconcileCandidate` path. It refuses to prepare any ACK body unless all have happened:

1. A fresh independently signed F387 intent receipt matches the exact trusted SHA256 session digest, tenant, instance, intent ID, nonce, expiry and pinned signing key.
2. The genuine restricted PG18 Portal runtime role successfully executes the HMAC-protected digest revocation function after failure/retry as needed.
3. The separate F391 SECURITY DEFINER function, explicitly installed **only in a disposable CI cluster**, returns the precise `revoked` state from the real table. No missing/active/other-tenant row can be acknowledged.

On success, PHP outputs an **unsigned** strict JSON claim body to a private UID82-only scratch file. PHP has no Worker ACK writer HMAC key and makes no network request. A separate Worker Node24 container with **no network access** reopens the same file-backed ephemeral SQLite ledger that originally received the intent, authenticates the POST with the dedicated F393 ACK writer key, stores exactly one append-only ACK claim, then reads it back with a **different** HMAC key and a new nonce to obtain a signed Ed25519 receipt.

A fresh PHP process independently verifies that actual Worker signature via F393 verifier, then uses the restricted PG18 role to confirm that the specific session is still revoked. The ACK receipt retains status **`ack_recorded_local_claim_unverified`**, even in this positive lab. Every HTTP, business, issuance, restore, deploy, queue-complete and `canClaimPostgresRevoked` flag remains false.

## Failure injection and trust limits

The CI creates a live synthetic PG18 session without persisting its raw bearer. It installs a temporary trigger that forces SQL revoke to fail. Under that fault, the planner must return a negative result, **no ACK body file exists**, and the real F393 SQLite ledger still contains zero ACKs. After the trigger is removed, retry succeeds, the SQL row becomes revoked, and ONLY THEN can the F393 ACK be written. Separate negative tests reject wrong/expired independent receipts and confirm missing-row status cannot be mistaken for revoked.

All HMAC/Ed25519 keys are **ephemeral CI-only**, with private files erased after the job. Although Node and PostgreSQL run as separate containers with a persistent scratch SQLite file between Worker invocations (simulating a process restart), they remain under ONE GitHub runner administrative trust domain. This is not an external independently protected D1 root, not a durable production job queue, and not proof of no TOCTOU race between PG status read and ACK publication. A privileged PG or D1 administrator could still roll back the relevant storage.

No scheduler, lease, atomic cross-host commit, independent attestation, key custody/recovery, production failover, customer data, public Laravel routes, Cloudflare deployments, VPS modification, DNS, new expense, or main merge. Issue #64 remains OPEN/NO-GO.
