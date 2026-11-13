# F365 — Post-restore identity quarantine on a disposable PG18 copy

This candidate addresses a concrete risk from [Portal issue #64](https://github.com/rafhael100/imagine-portal/issues/64): a pre-revocation database backup may resurrect a session that was revoked on the live database **after** backup.

The GitHub-hosted CI harness:
1. Provisions a fresh synthetic PostgreSQL 18 Portal, reuses the real private enrollment/email/activation security tests, and inserts one **synthetic active business-shaped session** with valid revision binding.
2. Verifies the real `public.portal_browser_active` SQL function accepts that synthetic digest *before backup*.
3. Dumps the database; revokes the session in the source and confirms the live source denies the digest.
4. Restores the older snapshot to a newly-created isolated database in the same disposable cluster, explicitly without CONNECT access to runtime roles.
5. Proves the restored copy resurrects the old session *inside the isolated database*, then runs a transactionally consistent, strongly gated quarantine.
6. Quarantine terminates all old sessions, pending email challenges and browser envelopes, suspends invited/active identities, memberships and organizations, and rejects any outstanding business grants/claims it cannot reconcile. It uses the **existing database triggers** to advance revisions.
7. Verifies `portal_browser_active` denies the old digest afterwards, all runtime DB CONNECT remains blocked, and source revocation remains unchanged. The restored copy is destroyed in the runner.

The quarantine code is **strictly GitHub CI-only**, with fixed PostgreSQL database name `portal_identity_restore_f365_ci`, explicit environment guards, peer-host IPv4, admin/RLS checks, no role grants and no release verdict. It CANNOT be executed on the real VPS or on another database without review/reengineering. No secrets, live accounts, serving route, DNS, upgrade or production migration.

**Security boundary:** Resetting state to suspended after restore is a fail-safe for a narrow private pilot. It does **not** solve independently witnessed monotonic state, leaked snapshots/token reuse across keys and clusters, sessions held by external caches, concurrent serving/recovery, credential rotation, restore authorization or approval of real customer/business data. Even a passing CI will still say `OFFLINE_QUARANTINED_STILL_NO_SERVING`. Issue #64 must remain **open** and public serving remains **NO-GO** pending independent review and an operationally fenced restore process.


## F366 — adverse-state and rollback checks on the restored PG18 copy

A separate stacked PR extends the disposable PostgreSQL workflow with two hard refusals **before** quarantine: disabling FORCE RLS on the restored session table, and introducing a synthetic unresolved service-resource claim. The quarantine must return NO-GO and leave the old session **still active in the isolated copy**; this distinguishes safe refusal/transaction rollback from a misleading partial cleanup.

The workflow then restores safe FORCE RLS, removes its own synthetic claim, adds one pending email challenge and one encrypted-browser-envelope-shaped synthetic record, performs quarantine and verifies the challenge is revoked and the envelope is deleted, while the old session is no longer accepted. Production data is never used; runtime principals still lack CONNECT and no serving is authorized. This is a **proof of transactional behavior only**, not independent disaster-recovery approval.


## F367 — restored database must have no competing SQL client

The quarantine checks `pg_stat_activity` for other client backends connected to the restored database before acquiring its protected-table locks. A new adversarial CI case opens a separate privileged `psql` client on the restored, inaccessible copy; the quarantine MUST fail without changing the old session. After that client exits, the RLS/claims rejection cases and successful quarantine still run.

This check is a **time-local observability fence only**; it cannot rule out a privileged administrator reconnecting after inspection. Only independently enforced serving isolation, privileged-operator discipline, external monotonic state and comprehensive restore runbooks can support a real recovery decision. No user/app role is granted CONNECT; all tests are restricted to disposable GitHub-hosted PostgreSQL 18.
