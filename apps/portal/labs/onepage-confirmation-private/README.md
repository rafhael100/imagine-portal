# Private synthetic confirmation — corrective review v2

NOT functionally validated. No publication, push, PR, workflow dispatch,
PostgreSQL, migration, PSP call, deploy, merge or production change occurred.

## Pins and composition

Local branch `lab/onepage-confirmation-private`, base
`f9196075236be7c744c96b58653b798231976eaf`; not a checkout of #932.
Intended additive composition: #932
`82deeb0062f80f1cf979a2c0105d394c2b1adc23` plus only four #937 native lab
files at `ecc225c3f8c17caadfb2361f471c96cdc76e74e3` and these eight new files.
The 46 required blobs in `sources.json` match local files and were verified
through the official read-only GitHub connector. Full tree equivalence is
not claimed; the runtime rejects the local substitute history.

Reusable verified contracts:

- [#937 native kernel](https://github.com/rafhael100/imagine-portal/blob/ecc225c3f8c17caadfb2361f471c96cdc76e74e3/apps/portal/labs/onepage-native-kernel-private/kernel-check.php): existing password/TOTP/session, buyer-owned acceptance and logout. Four files imported unchanged; no inherited workflow import.
- [#938 receipt SQL](https://github.com/rafhael100/imagine-portal/blob/74cd2d12378b2cc7ff40fbda61327edcbcf40cd4/apps/portal/labs/onepage-bound-receipt-durable-private/receipt.sql.inc): separate observer; writer receives identifiers/version/digest and rereads exact source/scope before append. Its marker is NOT confirmation.
- [#938 checkpoint](https://github.com/rafhael100/imagine-portal/blob/74cd2d12378b2cc7ff40fbda61327edcbcf40cd4/apps/portal/labs/onepage-bound-receipt-durable-private/checkpoint.php): independently chosen fixture ID and staged source, commit/rollback/replay controls. Source ID is not derived from the native order.
- [#932 AtomicReader](https://github.com/rafhael100/imagine-portal/blob/82deeb0062f80f1cf979a2c0105d394c2b1adc23/apps/portal/labs/onepage-atomic-read/AtomicReader.php): refuses an already-open runtime transaction before reading native authority.

No #757, preview/HMAC writer, receipt-marker authority or real payment
integration is imported. Root AGENTS.md and .agents/skills returned 404;
apps/portal/AGENTS.md was read and matches the local copy. Explicit task
approval governs only this private synthetic scope.

## Correction relative to patch 39abdedb

The event source now has independent fixture sentinels (feed, entry and
individual event ID), immutable source records and a binding to exact
company/order/quote/account/environment/setup-1/amount/accepted offer and
contract. Bootstrap stages this isolated test feed; it is not a runtime
confirmation writer, not a PSP, and not proof of real received funds.
Confirmation and observation timestamps come from this declared synthetic
source, never from the buyer or solely from native order acceptance.

`onepage_test_observer` can obtain only source version/digest. The sole
`onepage_test_reconciler` receives order/source/event/version/digest, NOT JSON.
Its SECURITY DEFINER function rereads the immutable staged evidence,
validates the entry binding, source timestamp, entire native scope, amount,
state, reversal and remaining schedule, then appends. The row consumes the
unique source/event and records the decision atomically. Exact replay is
idempotent; an ID cannot be rebound to another order. Reversal is terminal
in this isolated test. No runtime role can modify source tables or directly
write decisions; the NOLOGIN owner has only required SELECT/INSERT rights.

NextStep still returns only {"next_step":null} or {"next_step":"briefing"}.
It refuses a PDO already inside a transaction before calling its attestation
closure, and refuses isolation other than READ COMMITTED. No endpoint or UI
is registered, and no alternative identity is introduced.

The positive proves FIRST release only with confirmed entry and all other
installments in good standing, without full settlement. First activation
with 1-7 days overdue is outside this positive, not automatically authorized
and not treated as suspension of an active service. The lab's null result
outside its proven scope is not an operational policy. Existing service
continuation/suspension rules are untouched; the former <=7 activation
predicate and its misleading positive were removed.

The artificial daily expiry introduced in v1 was removed. Source evidence
is immutable, with explicit terminal reversal; there is no new business
freshness policy. The positive uses D30/D60 future due dates, and a fixed
São Paulo midnight boundary predicate case avoids dependence on today's
calendar day. No clock override, changed timezone rule or fake acceptance
timestamp is installed. This does not attest a live changing provider feed.

## Workflow proposal, still private

Only the NEW workflow changes: pull_request types [opened], draft=true,
same repository on both sides, dedicated head test/onepage-confirmation-pg-lab,
base fix/portal-v5-handoff-context-2026-11-17 at exact #932 SHA. Checkout and
EXPECTED_HEAD use the PR head SHA, never the synthetic merge commit.
Before installing tooling, verify_tree requires an exact additive diff from
#932, the 46 source blobs, clean checkout and ordinary file modes. The
workflow has contents:read, no secrets, no pull_request_target, credential
persistence or artifacts. It does not require modifying main/default.

This gate controls only this job. The repository also has broad push/PR
workflows: publication could trigger those, and this proposal does NOT stop
them. Nothing has been published or triggered; coordination must review the
entire publication impact before any future push/PR.

Tooling preparation and the bounded PG18 runner/teardown retain the existing
lab approach. Tests run unprivileged inside a new loopback-only namespace;
no PSP egress, host DB, real credentials or services are used. PG local trust
is inherited; SCRAM/TLS authentication of PG roles is not claimed. Actual
browser password/MFA is exercised only in the synthetic Laravel kernel.

## Checks

Supported offline command:

    python3 apps/portal/labs/onepage-confirmation-private/check.py --offline

It verifies source blobs, Python AST, temporary composition, Bash syntax,
exact ordered markers, and rejection of missing/duplicate/failed transcripts.
Separate YAML/Bash and git diff --check are run. PHP/SQL functional validation
is NOT claimed: PHP, Composer and PostgreSQL are absent in this executor.

Only a future approved isolated CI run can prove the full chain:

1. Exact source/diff and tooling gates; effective Laravel config and actual
   loopback PDO/postmaster/control-system identity before any migration.
2. Native password/TOTP/PG session, buyer ownership and exact accepted order.
3. Independent source uncommitted/rollback invisibility; wrong version/digest
   and divergent context/amount/contract/entry/reversal/date rejected.
4. Runtime privilege metadata and defensive direct-write denials.
5. Atomic confirmation/event consumption: invisible before COMMIT, absent
   after rollback, visible after COMMIT through a new buyer PDO only.
6. Same-tenant neighbor, pending MFA and second unpaid native order closed;
   replay unique; source/event ID conflict/reuse cannot fund another order.
7. Pre-existing REPEATABLE READ transaction refused without querying authority;
   altered default isolation refused; fresh READ COMMITTED succeeds.
8. First-release positive only in good standing, deterministic midnight
   predicate, terminal reversal, unchanged native orders, native logout.
9. Exact complete transcript, bounded worker completion, verified cluster
   shutdown, free port and scratch removal. No partial PASS is sufficient.

## Exact additive files

- .github/workflows/onepage-confirmation-pg-lab.yml
- apps/portal/labs/onepage-confirmation-private/NextStep.php
- apps/portal/labs/onepage-confirmation-private/README.md
- apps/portal/labs/onepage-confirmation-private/cases.php
- apps/portal/labs/onepage-confirmation-private/check.py
- apps/portal/labs/onepage-confirmation-private/confirmation.sql
- apps/portal/labs/onepage-confirmation-private/run.py
- apps/portal/labs/onepage-confirmation-private/sources.json

No pre-existing workflow, migration, business policy, n8n or operational
code is edited. The corrective diff touches seven of these eight new files;
run.py's exact additive pre-tooling gate is retained unchanged.
