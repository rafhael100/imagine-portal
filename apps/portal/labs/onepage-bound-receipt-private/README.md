# Private committed Onepage receipt-input fixture

Existing purchase is not repeated: [#937 native buyer run](https://github.com/rafhael100/imagine-portal/actions/runs/44217777210/job/134511806348)
proves MFA and owned draft/accept/replay, not provider receipt. Exact
local base 42a3fe2e09463ffcf8d5010e2de4258cf19ffa0b, the fixture content
of [#928](https://github.com/rafhael100/imagine-portal/pull/928). Its
[existing job](https://github.com/rafhael100/imagine-portal/actions/runs/44192633470/job/134425735241)
proves the synthetic persisted binding and projection. Upstream
bound-checkpoint used payload built directly from its own returned ID
and invented time offsets; that existing proof remains untouched and
is not independent evidence of a paid receipt.

The new test hook is derived in a private temporary #928 harness at the
cut immediately after recognized COMMIT and before a second guarded buyer
backend rereads the persisted entry ID. It stages an immutable read-only
fixture ledger from the existing accepted plan and the already pinned
#926 synthetic sentinel, before the buyer read. Nothing is seeded from
the readback. The observer sees a normalized bound record and only a
read-by-ID capability. Separate logical persona means no PDO, no buyer
MFA/proof, no arbitrary filters, no writer, no external reader and no
roles/grants added, but does not imply process isolation or operational
independent authority. Native company UUID is the fixture organization
scope; a distinct production provider tenant is not assumed. The exact
existing BoundPaymentFixture compares all contract, scope, account,
environment, payment, customer, reference, amount/currency, due date,
freshness, status and reversal fields. The hook expects known exact
fixture match and denies pending, absent binding, reversed and unknown;
absent binding must not invoke the reader. No secret or identifier is
printed or exported. No receipt or eligibility decision is persisted.

[Portal #745](https://github.com/rafhael100/imagine-portal/pull/745)
pin 9a269c7b9af889b61ac367884890834688ef8e69,
[F1-392](https://github.com/rafhael100/imagine-portal/actions/runs/43591266358),
illustrates the separately trusted binding source/observer, not a
currently authorized native Onepage database source or live Asaas read.
Its legacy checkout/Sites entitlement authority is not wired here.
Prior exact 9 offline #924→#916 verifier cases are reused as historical
evidence for match, mismatch/revocation and presence of a trusted source;
the legacy full-settlement rule is not this pilot's policy. The existing
Onepage observation code c198c054 is reused, not rewritten. No broad
fixture suite rerun was needed or performed.

## Offline and live gate

Offline source only: env -i PATH=/usr/bin:/bin LC_ALL=C
PHP_BIN=/absolute/php /usr/bin/python3
apps/portal/labs/onepage-bound-receipt-private/check.py --check
(as one command). Nested upstream composition and generated PHP lint
are exercised. No PostgreSQL, SQL, CI, remote write or PSP call. No
runtime mode in this package. A future authorized runner should prove
this checkpoint and guarded teardown, not restart the browser proof.

Actual accepted receipt and same-order briefing still require an
independently provisioned service read capability establishing the exact
provider/account/wallet, immutably bound order/company/line/contract
purchase, and an authoritative timestamp and active (not reversed)
receipt observation. The buyer-only SQL reader must never be called by a
worker or with borrowed buyer proof. For a future service persona, prefer
a scoped execute-only read of one reserved identity, with independently
attested principal/account and revocation/claim scope, no table DML or
SELECT, no new privilege granted here. That shape and trusted custody
need separate review and approval; actual GET needs explicit permission.
A generic historical payment, payment ID by itself, webhook flag,
CONFIRMED, or a provider date with unknown timestamp semantics do not
confirm paid_at. Neither system clock nor bound_at is paid_at. Even a
successful fixture marker is only a source match, not live signed
receipt, financial authorization, full settlement or access to briefing.
A distinct approved same-order durable receipt writer and buyer-scoped
briefing gate are downstream decisions, not hidden in this patch.
