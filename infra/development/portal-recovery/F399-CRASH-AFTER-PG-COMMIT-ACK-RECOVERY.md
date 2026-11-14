# F399 — Recover an ACK committed remotely after the HTTP reply is lost

F394 proved that a signed independent revocation intent followed by actual, HMAC-protected PostgreSQL18 row status `revoked` can prepare an ACK claim before a separate independent Worker writes it. Yet a real incident may crash at TWO dangerous boundaries: **after PG18 revokes but before an ACK is published**, or **after the independent ACK is committed but before the local process receives its HTTP response**.

F399 adds a standalone, dormant rehearsal of the second crash boundary, building on F394's existing test of the first. It **does not** add a production scheduler or move any of the F371–F398 NO SERVING gates.

## Disposable end-to-end sequence

1. Create a private, synthetic Portal PostgreSQL18 cluster with real restricted identity roles. Explicitly install the F391 status SQL *only inside the disposable cluster*.
2. Create one active business-shaped session, discard its original random bearer and retain only organization UUID and SHA256 session digest.
3. Start the actual Worker F376/F386/F387 code in a **network-disabled Node24 container** backed by an ephemeral file-backed SQLite ledger. Publish a signed independent intent and verify that no F393 ACK exists.
4. Invoke F394 PHP against real PG18: inject a SQL failure and prove **no ACK body** can be prepared while the session remains active; remove the fault, retry the actual role-scoped revoke, and prove HMAC-scoped status `revoked`. Only then is an unsigned, minimal ACK claim body saved to a private 0600 fixture.
5. In a fresh Node process, call the **actual F393 Worker POST** with its own HMAC key, verify the immutable ACK was committed, and intentionally **drop the HTTP result** without creating any local receipt file.
6. In another process, retry the same POST: a **409 duplicate** is expected. The caller must NOT claim success based on 409 alone; it performs the **separate authenticated GET F393** with read-only HMAC, fresh 256-bit nonce, verifies the Ed25519 signed receipt binds the exact intent ID, tenant, sequence and session digest, then stores the first trusted acknowledgement receipt.
7. A separate PHP process verifies the **actual Worker Ed25519** signature with the F393 strict verifier AND independently checks that real PG18 status remains revoked. All HTTP/business/queue complete and SQL proof authority flags stay false.
8. Restart Node again. The same attempted POST is denied as a duplicate; a *new* signed readback has a different nonce/signature, the previously stored ACK remains immutable, and the system does not create a second ACK. PHP verifies the first receipt a second time.

## Boundaries still unsolved

The file-backed SQLite used by F399 survives only multiple disposable CI processes on one GitHub runner. This is **not independent administrative custody** or a live remote Cloudflare D1 service. The exercise uses a private unrotated key fixture only within CI; production credential escrow, secret rotation, mTLS/TLS SPKI-pinned client, crash-persistent leased scheduler, durable local ACK outbox, trusted remote readbacks under network outage, monitoring, replay budgets, retention, HA/DR, and off-host witness rollback resistance remain unimplemented.

An ACK is only an externally recorded **claim**. It cannot independently certify that the SQL row is currently revoked, and cannot authorize an HTTP request. Every receipt retains status `ack_recorded_local_claim_unverified`. A malicious ACK publisher can still lie, which F396/F397 triage tests explicitly reject through real SQL comparisons.

No Merge/main, production database migration, customer data, public Laravel auth route, Leaseweb VPS or Cloudflare deployment, DNS change or new cost. Issue #64 remains **OPEN/NO-GO** pending AppSec/DBA/DR evidence.
