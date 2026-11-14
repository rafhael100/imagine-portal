# F397 — Signed Worker triage against real restricted PostgreSQL18

F396 verified PHP security decision policy using genuine signatures from Worker F376/F386/F392/F393/F395, but with a synthetic local status adapter. F397 replaces that adapter with **actual Portal PostgreSQL18 and the restricted HMAC/FORCE-RLS F391 status function**. This is a disposable, review-only integration test.

A GitHub-hosted disposable PG18 database provisions actual Portal identity roles, and seeds an active synthetic browser/business session without writing its raw 256-bit bearer outside the first seed process. An isolated Node24 container with **no external network access** executes genuine F376/F386/F392/F393/F395 Worker code using in-memory SQLite and ephemeral HMAC and Ed25519 secrets. The Worker records two independent intents and leases: (1) the SHA256 of the active PostgreSQL session, and (2) an independently valid intent whose SHA256 is **absent** from PostgreSQL. It deliberately stores a **validly signed but FALSE F393 ACK claim** for the active session before any SQL revocation, illustrating that an ACK publisher can misrepresent actual PG state.

The PHP F396 read-only triage consumes the exact JavaScript-generated Ed25519 cursor, lease and ACK receipts, binds them to trusted organization/instance, intent, digest and fresh nonces, and queries F391's role-restricted PostgreSQL status:

- A valid independent ACK while the SQL row is still **active** must return `ACK_CLAIM_CONTRADICTS_ACTIVE_POSTGRES`, with all access and completion booleans false.
- The second independently signed intent, whose hash is not in SQL, must return `POSTGRES_SESSION_MISSING_ESCALATE`, not "revoked."
- Forged cursor, lease or ACK signatures/nonces must fail without becoming SQL permission.
- A CI-only PostgreSQL trigger forces the real restricted `portal_browser_revoke` to throw. The SQL row stays active despite the prior signed ACK, and triage continues to reject completion.
- After removing the fault, a genuine restricted HMAC revoke and F391 row read yield `revoked`, but both with and without ACK still produce **NO SERVING**. The absent row remains missing.

F397 uses a single CI runner administrative trust domain and ephemeral SQLite; it does not prove operational independent Cloudflare D1, escrowed Ed25519 key custody, PostgreSQL↔witness atomicity, worker leases under real network partition, HA/DR, tenant IAM, rate limiting, retention, key rotation, queue completion or race-free positive HTTP middleware. The test runs only through GitHub Actions and deletes all database volumes and secrets afterward. No Laravel public auth routes, main merges, VPS Leaseweb/Cloudflare deploy, real customers, DNS changes or extra expense. Issue #64 remains OPEN/NO-GO.
