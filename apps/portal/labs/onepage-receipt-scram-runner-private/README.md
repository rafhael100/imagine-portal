# Private draft: single all-SCRAM receipt-input runner

This source derives temporary copies only from cb7ae04d and its pinned
#928 / c3115ad0 / 00534d0 graph. No product, auth, migration, public
artifact or checked-in workflow changes. Never run here. Not ready
for runtime until independent review and separate approval of exact
head, host and steps.

Only approved future profile: one single-job disposable non-root Linux
namespace containing loopback only, no other processes with access,
no other app endpoints, both network families without default routes,
free loopback 55439, proven AF_UNIX listener capacity inside a private
path, ephemeral trusted package provisioning before network isolation.
The secure source checks Git reviewed HEAD/status, identity pins, tools
and namespace. It cannot on its own prove trusted host ownership or
absence of all other processes. The parent supervisor is trusted test
orchestration; credentials and actor PDOs in the same harness are
**not** proof of independent UID/process memory isolation. If a
reviewer requires custody against a concurrent sibling user/process,
do not run this candidate; a separate protected broker/UID boundary
must be designed, authorized, and proved instead.

Derived worker uses one new PG18, first startup all-SCRAM, exact
pinned HBA installed before startup, native role/database/start identity
guards, no fallback. Unix is a server-owned listener only; client auth
is rejected. All test passwords are fresh and distinct, created only
in future --run in local scratch 0600 owner files, held in memory per
credentials lookup, never values passed as argv/env or emitted. The
initdb password-file argument contains a path, not a password. PDO
bootstrap uses in-memory config only, and the three original bearer
PDO cut points receive only the appropriate role value. Runtime arms
observer/writer fixture NOLOGIN profiles only after committed binding,
checks exact HBA table shape on the same already attested maintenance
connection before schema, and tests wrong role secrets, Unix/wrong
database denial, independent authenticated backend IDs and cross-role
ACL denial. The setup keeps all database/PHP fixture checks and one
bounded worker; only sanitized terminal result after exact markers and
owned cluster shutdown/port and scratch verification. Output capture
is streamed into at most 64 KiB of private memory; routine raw log
files are disabled, the invalid configuration test keeps its one capped
scratch diagnostic, no transcript or artifacts are exported. The script
compares canonical epoch bigints using the exact Guard SQL and checks
explicit role/function ACLs and actual table/role denials. Fresh synthetic
credentials are removed even if uncertain PGDATA is retained for
destruction of the disposable host. Outer disposable job must have
a 15-minute ceiling and enforce destruction even on process loss.

Freshness is a synthetic observed_at from independently staged fixture
input, not an Asaas timestamp. Matched input requires bound_at <= observed_at
<= current statement, at most 60 seconds old. The dynamic wrong-interval
case is staged before any append and must deny even with matching identity;
SQL rejects stale source versions, pending/reversed, wrong scope and
requires durable single append, rollback visibility and replay. No
concurrent revocation or post-snapshot authority proved, no paid_at,
payment, billing, service activation, briefing or Sites.

Offline: env -i PATH=/usr/bin:/bin LC_ALL=C PHP_BIN=/absolute/php
/usr/bin/python3 apps/portal/labs/onepage-receipt-scram-runner-private/run.py --check
(one command). Does source derivation, exact anchors, inherited source
pins, and PHP/Bash lint only, no socket or DDL. As an approval checklist
for active future mode, provision pre-reviewed matching PG18 bin/libs
with PHP 8.4 and POSIX/PDO/required extensions, clean lock-derived Portal
vendor, prove AF_UNIX and lo-only namespace in that job, and inject only
explicit CI sentinel + reviewed Git head and canonical tool paths.
No workflow or evidence of live SQL validity, SCRAM enforcement, or
teardown in this update. A second reviewer should inspect HBA API
semantics, log confidentiality and both auth/worker lifecycle stages.

Later same-order view planning may reuse verified behavioral assertions
from #703 run 43538089877 and #694, not their auth or payment adapters.
No briefing or eligibility step is part of this source.
