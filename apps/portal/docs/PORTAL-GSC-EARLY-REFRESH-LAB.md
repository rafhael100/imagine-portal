# Authenticated early GSC renewal laboratory

This extends the authenticated HTTP/TLS proof in #929/#930 and addresses a
remaining lifecycle gap from #810: replacing an access token with a response
obtained by using the stored refresh token. The existing vault's
`rotateSyntheticCandidate()` only changes encryption keys; it does not perform
OAuth renewal.

The candidate is **synthetic and unregistered**. It runs only on hosted CI,
against the disposable PG18 database and fixed loopback TLS fixtures. No
operational factory, browser route, worker, schedule, credential, Google call,
production migration or retention policy is enabled.

## One complete path

1. The real Laravel Kernel restores the encrypted browser session and applies
   its HTTPS, same-origin and CSRF middleware. The request body must be empty;
   no token, tenant, actor, permission or property authority comes from HTTP.
2. The existing first-party principal function verifies the business session,
   MFA, current organization/membership revisions and `seo.gsc.connect`.
3. A transaction locks the exact live candidate/grant pair and records a claim
   for its ciphertext hash, issuance, expiry and grant verification time. The
   original OAuth state is not consulted or extended.
4. The unchanged pinned vault decrypts that candidate. An opaque, single-use
   envelope receives its refresh material. The claim is **committed before
   network I/O** and therefore visible to a competing connection.
5. The client POSTs to the fixed token endpoint through verified laboratory
   TLS. It parses the actual response with the existing immutable policy. If
   the response omits a refresh token, explicit composition retains the exact
   predecessor refresh material; a supplied valid replacement takes its place.
6. The same returned candidate supplies the bearer for an actual fixture
   `sites.list` request. Exact property and sufficient permission are required.
7. A new transaction rechecks the original session, principal, generation,
   active grant, validity and claim. It keeps these locks until commit.
8. A narrowly scoped NOLOGIN function stages removal of the predecessor pair.
   The original vault's insert-only method encrypts/inserts the successor and
   the coordinator inserts its matching grant in the same transaction.
9. Completion records only predecessor/successor metadata. A deferred database
   constraint independently checks the final successor/grant pair. Both
   ledgers become immutable inside that completed transaction, including if a
   caller executes `SET CONSTRAINTS ALL IMMEDIATE` before trying to alter them.

The core stays pinned to
`de3315df4e8b8c07228d76f6613c799f9c174f31`. No vault class or encryption codec is
copied, and no AAD/expiry field is edited to simulate renewal. The pinned vault
itself reads/decrypts the resulting credential in the integration checks.

## Concurrency and failure behavior

| Situation | Laboratory behavior |
| --- | --- |
| Two workers claim the same version | One durable claim; the loser performs no provider request. |
| Encryption-key rotation changes the ciphertext while an attempt is unfinished | A second unique constraint on the tuple blocks another claim; the original attempt fails its generation check. |
| Token error, timeout or insufficient property access | Preserve the previous pair and its unfinished claim. No automatic retry. |
| Logout, loss of connect, revision change, revocation, expiry, deletion or generation change during I/O | Refuse the stale replacement; do not recreate or reactivate a record. |
| Failure after successor writes | Roll back the entire replacement; restore byte-exact predecessor ciphertext and grant. The earlier committed claim remains. |
| Commit after only the internal delete | The database constraint rejects commit and restores the predecessor. |
| Claim from another valid session for the same actor | Reject; the claim remains bound to its original session. |
| Revocation after successful renewal has committed | Existing local revoke operation remains available. |

The 30-second claim window bounds this laboratory operation. A failed or
abandoned claim is not automatically recycled, including after that window.
This is deliberately a closed failure state, not an approved operational
recovery policy. Metadata exists only in the disposable fixture and is removed
with the fixture; no audit/retention obligation is inferred from it.

## Database privilege boundary

The runtime keeps its existing SELECT/INSERT and one-way revocation privileges.
It gains no generic DELETE, ciphertext UPDATE, expiry UPDATE, claim-table read
or claim-table write permission. Only named functions execute under the new
NOLOGIN owner, with a fixed search path and row security enabled. The owner
derives tenant/actor from the existing principal, not from caller scope values.

Initial inserts still require their consumed original state. The alternative
insert proof applies only to the exact actor/property of a claim in `writing`
state, in the current transaction, under its same live principal. A forged
tenant/actor GUC alone is insufficient.

The replacement mechanism uses DELETE+INSERT internally because the existing
vault primary key permits one row per tenant/actor/property and the core
store is insert-only. Its partial state cannot commit: the deferred constraint
and completed-pair guards are part of this mechanism, not optional PHP checks.

## Verification and rollback

The `portal-gsc-https-transport-ci.yml` job runs the original TLS suite and a
separate refresh envelope/response harness. The Kernel workflow runs three
variants sequentially:

- Original authenticated HTTP proof, before applying any refresh SQL.
- Original authenticated TLS proof, still without refresh SQL.
- Authenticated TLS plus the refresh SQL and `gsc-refresh/scenarios.php`.

The third variant retains the original checks and adds durable competing claims,
response preservation/replacement, exact property evidence, real middleware
denials, failed-response preservation, eight post-response races, atomic
rollback, commit attacks and later local disconnect. Numeric fixture counters
measure actual requests and prove that losing/repeated attempts do not reach
the provider. Checks/assertions are not counted as independent scenarios.

Local validation covers PHP syntax through AST, Python/YAML/Bash and the TLS
fixture. Actual PHP/cURL/PostgreSQL execution is a CI gate; executed counts and
exact-SHA run links are recorded in the PR after that gate completes.

`rollback-refresh-fence.sql` restores the initial state-only insert policies,
explicitly removes the two completed-pair triggers and removes only the new
owner's objects with `DROP OWNED ... RESTRICT`. The existing six-policy cleanup
and rollback of six GSC plus twelve identity migrations then run unchanged.

## Deliberate limit: early renewal

The current vault only reads an unrevoked bundle while its access-token expiry
is still valid; retention can remove the entire expired bundle. This candidate
therefore renews **before** that expiry. It does not introduce independent
refresh-token lifetime or permission to read expired bundles.

The legacy response policy remains unchanged. A response carrying
`refresh_token_expires_in` is rejected rather than silently creating a new
time-limited-consent policy. Post-expiry refresh, recovery from ambiguous I/O,
reauthorization UX, background authorization after logout, scheduling, provider
revocation, secret-manager composition and operational retention remain
separate implementation/decision work. Real staging also requires the owned
Google application, controlled property and approvals described in #810.

Protocol reference: [Google web-server OAuth, refresh and revocation](https://developers.google.com/identity/protocols/oauth2/web-server#offline).
The fixture proves our protocol handling and concurrency boundaries; it does
not prove Google identity, actual consent or access to a real property.
