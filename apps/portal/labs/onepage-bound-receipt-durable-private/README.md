# Proposed disposable receipt-input log (dormant)

Parent c3115ad02f6e60788fb724dad2c429de3c2b7ad7 stages an immutable
read-only synthetic observer before the buyer's committed-ID readback.
Keep that direction: seed strictly from the accepted producer plan and
the separate fixed #926 test sentinel. Do not construct evidence from
the committed read. The positive requires an exact binding as well as
a separate staged observation; neither alone authorizes a write. The
new row is a synthetic receipt **input** marker, not a payment receipt,
baixa, eligibility, release or briefing. There is no paid_at, real
provider event, data export, signing, route or gateway. Dates in the
existing observer are synthetic read instants and never payment time.

Proposed inert SQL has explicit disposable database, bootstrap login,
loopback, port, path and existing binding-table guard, then two
NOLOGIN profiles. Observer has exact source read EXECUTE only, source
admits at most one row (one order/entry) and returns version and digest;
writer has exact append EXECUTE only, not source reader or buyer MFA.
Both get schema USAGE and no table grants. Native SECURITY DEFINER owner
can select internally, append to new log but cannot update/delete it.
No existing objects, schema permissions, migrations or product grants
are changed. RLS stays forced. SQL uses one matched, statement-snapshot
CTE for append and replay: verify pending native order, immutable scope,
exact attempted reservation/binding and normalized evidence, distinct
scope fields/account/environment/ref/ID/customer/value/currency/due,
active fixture status, clear reversal, source version/digest. It never
accepts the caller's evidence payload. Source changes committed before
append/replay are observed; this is not proof against changes after the
write snapshot or independent operational revocation. No claim of
successful SQL or verified ACLs until the future smoke.

Future isolated harness input, intentionally not implemented by this
source check: at the exact c311 post-COMMIT entry read cut, supply the
attested bootstrap plus observer and two independent writer backends
with distinct minimal actual role authentication; bind each PDO to the
same native isolated cluster via read-only role, login, DB, server,
backend/port and bootstrap identity observations. Keep the source
stager in the bootstrap test only (not a worker), committing one fixture
version at a time, and independent test oracle as row count plus
fingerprint. The fixture stages pending, reversed and positive controls
from the original separate source personas, stale version, rollback
that remains invisible from another backend, subsequent one committed
marker, replay no-op from another connection and denial on later
source-version change. Test ACL denial for mutual function calls and
table reads/writes on actual profiles before claiming grants proved.

**First blocker:** existing #928 trust host and Unix auth cannot prove
independent credential custody for new logins. Do not reuse that trust
path for those roles or use SET ROLE/borrow a buyer proof. A later
approval must identify one dedicated non-reused disposable PG18
runner, exact artifacts, segregated role-specific authenticated
connections (SCRAM audited for both TCP and Unix/no trust fallback for
the new roles), no operator credentials, test operator/runtime scope,
capacity and teardown. Review and attest those inputs before any
fixture SQL; then prove distinction via actual role sessions and
controls. No such runner or authorization was present here.
No workflow or executable launcher is registered.

Source command only (no DB or network):
env -i PATH=/usr/bin:/bin LC_ALL=C PHP_BIN=/absolute/php
/usr/bin/python3 apps/portal/labs/onepage-bound-receipt-durable-private/check.py --check
(one line). It reuses parent pinned composition, PHP lint, plus static
SQL guard/role-wall checks. This is not SQL parsing, execution, role
installation, test of teardown, or account authority.

Operationally the next decision remains choosing an independently
provisioned trusted account/tenant/order/line reader and an authoritative
fresh active receipt including paid_at provenance. GET, financial
authorization, receipt persistence in a real app and briefing need their
own approval and are not implemented.
