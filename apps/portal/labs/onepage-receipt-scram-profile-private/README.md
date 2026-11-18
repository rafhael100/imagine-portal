# Inert SCRAM runner profile for synthetic receipt-input durability

Parent 00534d0eaaf6834f3101811dc00ac39c8151aedd; no app, SQL,
migration, grants or workflow changes beyond this proposed profile.
Choose **one new disposable PG18 cluster with all TCP authentication
SCRAM**. A new SCRAM rule for observer/writer alone is insufficient
if any peer can authenticate as privileged bootstrap via inherited
trust. A second database/cluster would lose the native same-order,
same-snapshot binding proof; do not copy that binding into a second
database. Do not reuse #928 startup or enable any profile on an
existing database. It is safe to reuse the pinned offline guards,
SQL identity checks and fixture code only through a derived scratch
launcher with precise credential input and no authentication fallback.

## Exact inert inputs and current checks

- HBA file is a proposed immutable profile input, not installed. Only
  six exact DB/role combinations, IPv4 loopback; no Unix client,
  replication, delegation group, includes, wildcard authenticating
  rule, or trust. This is synthetic same-namespace SCRAM, not production
  TLS or operational auth. For the first future runner maintain the
  path shape and port 55439 required by the inherited attestation.
- check_profile.py --check verifies exact HBA grammar/order, models
  192 allow/deny vectors, denies trust variants and a missing terminal
  rule, pins the source and locates exactly one of each auth cut:
  run-clean.sh initdb/auth and empty bootstrap password; pg-cases.php
  base $connect; reserve-checks.php same buyer contention helper;
  binding-checks.php second buyer reader. All interfaces remain untouched.
  This is not a PG parser/config reader or proof of active enforcement.

## Future bound execution layout (not implemented)

1. Need a separately approved single-use Linux sandbox/job with a
   fresh loopback-only namespace, UID-separated or equivalently isolated
   subprocess custody for each role and the supervisor, no other host
   namespace users, exact tools/dependency pins, a 15-minute ceiling,
   readonly external logs and mandatory disposal. A group clear/secret
   filename in the same Unix UID does not itself prove custody isolation.
2. Before startup use a new owned private root, create distinct
   synthetic authentication inputs under supervisor custody. initdb gets
   its one bootstrap secret via isolated file or FD, never argv.
   Inject the exact HBA into the brand-new cluster before the first
   start; set explicit loopback/55439 and scram-sha-256 password
   encryption. No default trust table, no fallback, no unauthorized
   listener/reload or port change. Unix socket can still be opened
   under the inherited scratch path as part of its lifecycle, but all
   Unix client authentication is rejected; local workspace AF_UNIX
   remains unavailable. Any proposed no-socket alternate is out of
   scope, not a bypass.
3. Attest PGDATA/process/PG18/control ID and actual socket endpoint.
   Before other DDL, use the same bootstrap PDO to verify database,
   login and effective config, the expected HBA rules/options (no
   errors/includes/trust), PGDATA identity and SCRAM controls; report
   only closed enums. HBA file parsing alone does not establish loaded
   config: verify secure startup and controlled positive/negative
   connections (missing/wrong creds, wrong DB, Unix, each cross-role
   password). Fail closed if either read/write role can borrow an
   upstream login. No attempt until a fresh exact approved test.
4. Use least privilege in scratch only: retain independent credentials
   for bootstrap, portal_browser_runtime, onepage_buyer_runtime,
   receipt observer and receipt writer. Reuse the pinned PHP runtime
   target checks: bootstrap gains its per-run secret only in-memory
   just prior to guarded config verification; buyer PDO substitutions at
   all pinned anchors independently consume only the appropriate
   scoped fixture credential. Use designated protected IPC or files
   resolved and opened against an owner/mode/size/one-read policy,
   with no global PG environment or dotenv, output, shell expansion,
   argv, or open inheritance to unrelated helpers. No app/policy
   guard edits. Observer receives only its credential; the two writer
   backends may share the synthetic writer credential. Do not enable
   any receipt login until base guard complete and original binding
   COMMIT confirmed. Then reattest role flags, DSN, exact backend via
   supervisor-observed identity, no membership and actual ACL denial;
   no SET ROLE, superuser-as-reader or inherited buyer MFA. Bootstrap
   alone seeds independent fixture versions; no inputs from wire.
5. Preserve private process-group cleanup and PID/control identity,
   SCRAM source version/read and writer/rollback/replay tests, only
   sanitized stage/count output after verified shutdown, no leftover
   port, pidfile, credentials or paths. Abort if custody or schema
   can only work by relaxing the original guard. Existing #937 purchase
   is already proven and should not be repeated.

## Evidence limit and approval

Review has pointed out that 00534d0 does **not** lock against
simultaneous same-order revocation, and stores no trustworthy
observation timestamp. Its marker can only prove synthetic unchanged
scope and version at an observed SQL statement snapshot. A source
version changed before the writer call is the fixture’s fence, not
concurrent revocation or freshness. Leave a separately owner-attested
revocation/read-interval capability for subsequent work rather than
grant UPDATE to writer or invent paid_at. This source never yields
baixa, receipt of funds, release, provider access or briefing.

One later bounded approval would need the final exact runner,
credential custody/isolation capabilities, closed auth and negative
matrix, exact inert receipt/source inventory, authorized disposable
runtime and approved teardown. Current artifact is a design + static
profile gate, **not an executable runner or project-ready test**;
no HBA installed, no roles, credentials, auth, database, publish,
workflow/GitHub action, PSP, or VPS touched.
