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
