# Private source candidate: native buyer cookie → existing Onepage repository

**Not run. No browser/HTTP server, checkout route, application/auth/SQL
change, PSP, credentials, publication or CI.** Local base #928 content
equivalent `42a3fe2e09463ffcf8d5010e2de4258cf19ffa0b`. This is a
single-kernel integration candidate, not an actual browser checkout.

- [#824](https://github.com/rafhael100/imagine-portal/pull/824), head
  `9c833ef0230229763ea33a3e71e6cf3380c71a4b`:
  [43657263507/132638454058](https://github.com/rafhael100/imagine-portal/actions/runs/43657263507/job/132638454058)
  succeeded, including real Chromium and Laravel/CSRF. Its source
  `ops/private-purchase/router.php`,
  `app/Providers/FullPortalPreviewServiceProvider.php` and
  `app/Homologation/CustomerPreviewStore.php` use preview-owned HMAC and
  `purchase_rehearsal.customer_orders`; it is not buyer identity for #928.
  Provider is fake and no receipts are transferred.
- [#838](https://github.com/rafhael100/imagine-portal/pull/838), head
  `f6fcd4b62634c8aeffede6a1f970fca41c6e8e9e` is Blade login UI
  for the preview. It does not change the authentication or grant.
- [#927](https://github.com/rafhael100/imagine-portal/pull/927) observed
  at `f564ba2af16f4be6165e52c15a6e23998ea35603`:
  `PortalAdminMfaGuard` and /portal-admin flow use admin-specific
  session epoch and `portal_admin_mfa_*` for a separate authority.
  Its native MFA proof is reusable as a pattern, not as a buyer credential.
- The actual compatible path is #928's
  `PortalDevelopmentAuthServiceProvider.php:44–69,77–89` plus
  `PortalBrowserAuthController.php:56–91` and
  `tests/Integration/PortalBrowserAuthenticationPg18Test.php:1127–1175`.
  That exact provider/middleware returns an opaque business reference in
  an encrypted PG-backed cookie session. The unchanged Onepage adapters
  both derive authority from that native reference in the same DB. The
  dev provider test's existing standalone DB (portal_browser_lab:5432)
  is not the Onepage DB. The unchanged SecretFile + runtime support the
  same-namespace explicit database/port; provision the exact Onepage
  disposable profile 127.0.0.1:55439 / portal_onepage_buyer_ci and install
  the exact already-existing envelope SQL into that database as a
  fixture so the dev provider can use it. No elevated role becomes a
  runtime role, no membership is inferred from cookie or URL.

The generated #928-derived tail starts only after the existing base
guard/owner attestation. It registers existing auth routes only, with
actual CSRF/password/TOTP and actual session cookie roundtrips in the
Laravel kernel (synthetic HTTPS origin in memory). It passes the very
Request attached by middleware directly to the original buyer/order
classes, rather than adding any checkout route or writing session
state. Buyer and same-tenant neighbor use normal password and TOTP;
unlinked identity is rejected at login, pending password phase cannot
create/accept, and same-tenant authenticated neighbor cannot access
another buyer's draft. Exact terms (lab version), revision mismatch,
fresh PDO draft resume/order replay, logout denial and zero/one row
assertions are included. Initial terms are fixture-seeded, not legal
acceptance conditions for production; no loan, charge or Site.

The provider retains staging app.config policy and fixed HTTPS origin
as in the cited integration test; the process remains APP_ENV=testing
for the unmodified Onepage runtime. Preserve that distinction only in
the isolated test. No DNS/hosts file or TLS verification bypass. The PG
launcher uses inherited cluster-local trust, so native browser
password/TOTP are tested by code, not network TLS or role password auth.
Roles remain mutually separate: identity runtime, envelope runtime
(existing SQL runner grants only LOGIN to new role in this cluster),
buyer runtime. The only values sent to credentials loader are
per-run synthetic scoped fixture values; never loaded from the host.

```sh
env -i PATH=/usr/bin:/bin LC_ALL=C PHP_BIN=/absolute/php \
  /usr/bin/python3 apps/portal/labs/onepage-native-kernel-private/check.py --check
```

The command pins original input blobs (including fixture-tail and
pg-checks), composes only in tmp, lints PHP and generated Bash, and
removes that directory. It never starts a process executing the suite.

Future runner source is separately guarded and **has not been run**.
The expected exact review HEAD must be supplied from trusted package
provenance; the two preceding pinned commits/trees, changed path
manifest, clean checkout status and source blobs are checked. Checkout
must contain all three exact commits (do not use a default shallow checkout).
Set up
a dedicated single-job Linux x86-64 anonymous disposable CI namespace,
with no other services/listeners, no real secrets, no network business
access, cleanup by provider even on SIGKILL, a 15-minute job timeout,
and 127.0.0.1:55439 + private AF_UNIX allowed. No shared runner. The
guard checks initially free port, not host isolation; the trusted
runner owner is responsible for the latter. The launcher owns one
scratch subtree, with real SQL attestation unchanged and the worker
limited to 480 seconds, followed by TERM and at most 20 seconds grace
+ bounded KILL. An explicit separate POSIX/PDO/PHP8.4 test refuses
before PG initialization. No role changes except the disposable login
on the original encrypted envelope SQL role; not production TLS or PG
password auth.

Supply PHP 8.4 and extensions (including POSIX), all PG18 tools and
libpq from a trusted single signed build. Generate vendor anew from
reviewed Portal Composer lock with verified Composer, empty private
home and cache, `composer validate --no-check-publish --no-interaction`,
`composer install --prefer-dist --no-interaction --no-scripts --no-plugins`
and `composer check-platform-reqs --no-interaction`. No task-specific
network or install was used in **this** workspace. Checking lock/source
does not attest downloaded vendor contents; approval covers provenance.

Future command, **only after explicit approval and environment proof**:

```sh
env -i PATH=/usr/bin:/bin LC_ALL=C GITHUB_ACTIONS=true \
  NATIVE_KERNEL_TEST=APPROVED_SYNTHETIC_ONLY \
  NATIVE_KERNEL_EXPECTED_HEAD="$APPROVED_EXACT_COMMIT" \
  PHP_BIN="$PHP_BIN" PG_BIN="$PG_BIN" ONEPAGE_PG_RUNTIME_LIB="$PG_LIB" \
  /usr/bin/python3 apps/portal/labs/onepage-native-kernel-private/run.py --run
```

Markers are assigned by the integrated test only after unlinked denial,
pending MFA and invalid CSRF denial, password+TOTP+PG envelope,
wrong claimant/version with zero writes, and exact acceptance/replay/
logout. The public marker output is held until all exact ordered
markers, worker exit, stopped PG control state/no PID file, free port
and scratch removal match. Uncertain cluster state leaves scratch
private for whole-host disposal rather than claiming success. The
offline regression derives all 13 ordered emissions from the pinned
launch/prepare/fixture sources, including the maintenance and base
identity steps. It rejects every single missing marker, an extra or
duplicate PASS, swapped order, failure exit and over-limit transcript.
Unmarked diagnostics are discarded, never emitted.

Single-organization preview binds the configured org before auth:
it would not be honest to manufacture a second authenticated tenant
inside that same provider setup. This suite uses a **same-tenant second
authenticated buyer** and an unlinked identity, not a second valid
tenant. Existing Onepage SQL independently exercises cross-tenant
access. Deliberate browser session expiry would require seeding a
synthetic reference or weakening the native timestamp fence; omitted
here rather than handcrafting a cookie. Existing SQL fixture covers
synthetic expired native reference; this test uses real logout denial.
A success would close only cookie→same draft→same pending order in the
kernel, not native browser transport, Sandbox receipt, financial
authorization, briefing or deployment.

## Failure-only diagnostic

The #933 automatic job reached run.py and returned rc2 without a phase.
Source alone cannot tell whether that was a runtime preflight, PostgreSQL
bootstrap, a fixture assertion, transcript mismatch, or teardown. If this
candidate is later separately approved, the failure line has only three
fixed fields: phase (host/tree/tooling/loopback/assembly/worker/transcript),
count of the contiguous exact expected marker prefix (0–13), and whether
cleanup was not needed, verified or failed. A successful trap marker after
an early worker failure never advances an incomplete prefix. A full prefix
cannot override a failing process, extra marker or failed cleanup. Raw logs,
paths, stdout/stderr from helpers, failures, SQL and payloads stay private.
The offline test checks negative cases, malformed/oversize input and the
leakage boundary. No attempt was made to rerun #933.

## Private defensive derivative after #936

The original laboratory draft command filtered resume by organization
and draft ID, and create replay by organization and idempotency key.
The function's internal RLS policy does not add a buyer predicate.
The retained same-organization second-buyer test was therefore not
a fixture defect. The adjacent order review/accept code already checks
both organization and buyer, so this patch does not alter it, the
Laravel adapters, any public test, account authority, or grants.

The private composer verifies the original draft storage blob then
derives only two scoped predicates and the collision denial in
temporary files. It substitutes only the valid setup after the same
effective target/identity guards; the native negative preflight,
inherited source, order SQL and workflow are byte-for-byte preserved.
Org-wide request-key uniqueness and ON CONFLICT DO NOTHING remain.
Reusing a key as the original buyer returns the same draft; the same
key from a different buyer returns neutral denial, no draft data, and
no mutation or duplicate. A different key still permits a purchaser
to have a different valid draft. The fixture makes both users actual
same-tenant password/TOTP sessions; it asserts exact denial for
another buyer both on resume and key collision, and fingerprint/count
stability. It requires own resume and exact same-key replay from a
fresh guarded connection, plus the original terms/order checks.

The local package deliberately does not update or prepare a workflow:
the previous public candidate is unchanged and its pins cannot run
this unapproved derivative. The source check assembles and lints
ephemeral files and audits changes/markers; it never executes SQL.
An independently reviewed isolated PG18 run needs its own authorization
and exact runner pins; the existing AF_UNIX restriction is not bypassed.
