# GSC: authenticated Laravel HTTP kernel laboratory

Status: development evidence only. Real Google OAuth, production token use,
customer display and operational deployment remain disabled. This slice does
not complete issue #810 or authorize a live tenant connection.

## Base and purpose

This isolated branch starts at Portal #928,
`951e02e5d8eedee93851416778027cf07d105296`, which already contains F421 and the
business Portal browser authentication stack. It does not import the parallel
administrative authentication branch or reinterpret `seo.view` as
`seo.gsc.connect`.

The immutable secondary Portal checkout remains
`de3315df4e8b8c07228d76f6613c799f9c174f31`, at `.qa/gsc-f109`. Its OAuth parser,
state types, property identifier, encrypted candidate vault and six migrations
are the existing F421 dependencies. RC50 is not a drop-in replacement: that
line removed F108/F109 identity-link components used by this laboratory.

The missing composition was an authenticated HTTP request chain. Previous
F114–118 laboratories proved genuine Portal password/TOTP and PostgreSQL state
fences, but called the domain layer directly. F116 also discarded its parsed
token candidate after returning a safe summary, so an unrelated token fixture
could not honestly prove continuity into the vault.

## What runs

`labs/gsc-http-kernel/verify-http-kernel.php` boots the actual Laravel HTTP
Kernel and registers routes only inside its CLI process. It sends independent
`Illuminate\Http\Request` objects through the `web` middleware and unchanged
`PortalBrowserAuthGuard`. The unchanged `PortalBrowserAuthController` uses
`PostgresPortalBrowserAuthentication` with ephemeral synthetic fixture keys.

Each request forgets the resolved Laravel session driver. A cookie must recover
the encrypted file session from a private temporary directory. Identity,
business-session issuance, TOTP replay counters, organization/membership
revisions, permission grants, OAuth state and encrypted candidates live in the
same disposable PostgreSQL 18 database. This uses the file session envelope,
not the optional private PostgreSQL browser-envelope driver.

The initial password and MFA requests use real Argon2id/TOTP adapters and
database functions. No identity, bearer, tenant, permission or provider evidence
is accepted from a query or DTO. The GSC controller reads the opaque business
reference from the framework session; its configured organization is server
owned. PostgreSQL derives the GSC actor and tenant from verified UUIDs through
the existing `portal_gsc_f114_connect_principal` function and exact
`seo.gsc.connect` grant. The read-only permission preflight is tested as a
snapshot, never used as the HTTP write authorization gate.

| Step | Existing boundary and new composition |
| --- | --- |
| Connect | POST requires origin, CSRF and business MFA session; F114/F115 stores PKCE state bound to the original session and revision. |
| Callback | GET accepts only `state` and `code`; the original session and one-use state protect the OAuth callback. State consumption commits before any provider call. |
| Token response | F118 builds the one-use envelope from the consumed state; F116 performs its fixed loopback cURL POST and strict parsing. |
| Candidate handoff | A new optional server-only F116 sink receives the exact parsed opaque candidate once. Existing two-argument F116 callers keep their behavior. |
| Property evidence | The same candidate's synthetic access token is sent only to `http://127.0.0.1:18765/sites`; F117 requires exact property match and owner/full-user evidence. |
| Vault and grant | A fresh database transaction rechecks the same original session, consumed state, revisions and connect grant after both HTTP responses, then writes encrypted candidate and synthetic property-grant rows atomically. |
| Local disconnect | POST rechecks the business principal and atomically revokes candidate and synthetic property grant. It does not call Google's revocation endpoint. |

The candidate's existing `takeForTrustedVault()` is a server-only read, not a
destructive consume operation. One-use provenance is supplied by the state CAS
and token envelope, not inferred from that method's name. No raw response,
token, PKCE verifier, secret or opaque Portal reference is returned by the GSC
controller. Synthetic candidate/grant rows in this disposable database do not
mint a Portal membership permission or authorize live Google/customer access.

## Post-response transaction and direct SQL protection

`post-response-fence.sql` is a lab-only SQL fixture, not an application
migration. It refuses any database other than `portal_gsc_f115_lab` on PG18 and
uses the existing `NOLOGIN` F114 owner and unprivileged direct-login runtime.

The new write-principal function reuses the F114 principal locks and verifies
the original F115 state/session/revision binding after the network interval.
Those locks remain held through candidate encryption, candidate insert,
synthetic grant insert and commit. A failed grant insert rolls back the token
insert, while the already-consumed OAuth state stays consumed.

Tenant/actor GUCs are not treated as authentication. Restrictive RLS predicates
revalidate the live business principal for every candidate/grant read or
revocation; inserts additionally require the original consumed state. Direct
SQL with only forged scope cannot read, insert or revoke records. Previously
valid context also stops working after session or connect-grant revocation.

The runtime receives no identity/session/binding table SELECT privilege.
Candidate UPDATE is limited to `revoked_at`; grant UPDATE is limited to status
and revocation time. Additional policies permit only the transition from an
unrevoked row to a revoked row, preventing direct SQL from restoring NULL or
`active` after disconnect. No new permission code or operational auth policy is
introduced.

## Scope of transport evidence

The Portal side is an **in-process HTTP Kernel test**. Its HTTPS Request scheme
exercises application origin/scheme checks; it is not a TLS handshake or a
browser test. Assertions check the actual `Secure`, `HttpOnly`, host-only,
`SameSite=Lax` cookie metadata and encrypted session persistence, not browser
enforcement of those attributes.

The default synthetic provider side does perform real cURL network I/O, restricted to
fixed IPv4 loopback endpoints with redirects and proxies disabled and bounded
responses/timeouts. `mock-provider.php` delegates token scenarios to the
unchanged F116 mock and adds an exact synthetic bearer-protected sites response.
Nothing follows the generated Google authorization URL or contacts Google.

### TLS variant of the same authenticated chain

The additional `PORTAL_GSC_HTTP_KERNEL_HTTPS_LAB=1` mode injects the unregistered
`GoogleSearchConsoleHttpsTransportCandidate` into the same `LabFlow`. Its factory
also requires the independent HTTPS-transport laboratory gates and an ephemeral
CA supplied through `PORTAL_GSC_HTTPS_CA_FILE`. An unavailable factory fails the
run; it never silently falls back to HTTP.

The token and sites requests now exercise certificate-chain and hostname
verification over real TLS. Their fixed Google URL hostnames are mapped only to
the two fixed loopback sockets by the laboratory transport. The ephemeral CA
attests the synthetic server, not Google. The same opaque candidate reaches the
same post-response fence, encrypted vault, atomic synthetic grant and revocation
checks. The callback reports `tlsLabVerified` only for that injected mode.

The existing F118 adapter contract remains a synthetic transport review. A TLS
result does not promote its provider-origin or customer-authorization flags.
The explicit F116 compatibility tests at the end of the harness still use the
original HTTP mock, which the TLS workflow also starts. The Portal side remains
an in-process Kernel test in both modes.

## Verification

The workflow `.github/workflows/portal-gsc-http-kernel-ci.yml` checks the exact
candidate SHA and #928 ancestry, checks out the immutable core, uses pinned
action SHAs, installs locked Composer dependencies, and runs PHP 8.4 with
PostgreSQL 18. It verifies the existing closed-route/configuration tests and the
existing F116 transport and F117 property matrices before this harness.

After the existing F421 identity/core migrations and F114/F115 SQL fixtures, it
applies `post-response-fence.sql`, starts the provider on loopback and runs:

```sh
php apps/portal/labs/gsc-http-kernel/verify-http-kernel.php \
  .qa/gsc-f109/apps/portal/app/Domain/SeoReview
```

All F114–118 flags remain necessary. This slice additionally requires
`PORTAL_GSC_HTTP_KERNEL_LAB=1` and `PORTAL_GSC_TOKEN_VAULT_LAB=1` with
`APP_ENV=testing`, CLI SAPI and the exact disposable database. Operational
routes, providers and `config/google_search_console.php` are untouched and
continue to deny activation.

The harness covers:

- Anonymous, incomplete-MFA, missing-CSRF, sibling-origin and insecure-scheme requests; no token call after those denials.
- Rejected identity fields, cross-tenant callbacks, a second valid MFA session substituted for the original, and callback replay.
- The complete original-state → envelope → actual response candidate → property response → encrypted vault → synthetic grant → local revocation chain.
- Two competing revocations blocked while the post-response write transaction holds its locks; session and grant revocation during the preceding network interval leave no candidate or grant.
- Redirected/malformed/wrong-scope token responses, exact-property mismatch and insufficient property permission, while failed exchanges remain consumed.
- Expiry of the actual stored candidate using the vault's trusted-clock argument; no prolonged wait or invented second token is used for that check.
- Distinct `seo.gsc.connect` and `seo.gsc.view`, atomic rollback on a grant conflict, no reactivation after revocation, and direct SQL attacks against RLS with forged or stale scope.
- The optional sink's disabled gate, at-most-once handoff and exception behavior; response and encrypted-session secret checks.

Synthetic users use distinct documented test IPs so the real five-attempt/IP
throttle remains enabled. No TOTP counter or throttle is reset to make the
requests pass. Fixtures and encrypted file sessions are cleaned after the run;
the workflow rolls migrations back in reverse order with `RESTRICT`, without
`CASCADE`.

The original HTTP version passed **3,230 assertions** in PHP 8.4.27 and
PostgreSQL 18.7 on commit `58aa1ae4b82cc0fc2bbe8cb481d62c50c6fd136a`, including
the rollback of six GSC and twelve identity migrations
([CI evidence](https://github.com/rafhael100/imagine-portal/actions/runs/44195816823)).
Those assertions include repeated privacy checks and are not 3,230 independent
scenarios. PHP/Composer and PG18 remain unavailable locally; the additional TLS
variant must be verified in the workflow on its own candidate SHA.

## Gates that remain external

Google Cloud client/consent configuration, real property-owner authorization,
managed secrets, audited Google HTTPS token/property transports, actual Portal
HTTPS/browser/staging validation, LGPD and AppSec review remain separate gates.
No real credentials, customer identity, VPS, DNS, merge, deployment or new
spending is part of this slice. Issue #810 remains open for those requirements.
