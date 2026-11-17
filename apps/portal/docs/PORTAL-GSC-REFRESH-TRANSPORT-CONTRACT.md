# GSC: synthetic early-refresh transport contract

This slice extends the unregistered TLS candidate from Portal #930,
`ed6a4f75c20064ae78c433ceabcac636065fc8e3`. The GSC core remains pinned at
`de3315df4e8b8c07228d76f6613c799f9c174f31`; neither its token parser nor its
vault codec, key rotation or retention rules change.

## Contract

`GoogleSearchConsoleRefreshTokenPostRequest::prepare` accepts the existing
`GoogleSearchConsoleEncryptedTokenSecret` returned by a vault read, a fixed
synthetic server configuration, and an explicit trusted time. Its constructor
is private. It refuses absent refresh material, non-readonly scope, malformed
tokens and an already expired predecessor. The configured values are exactly:

```php
[
    'clientId' => '1234567890-f116syntheticonly.apps.googleusercontent.com',
    'clientSecret' => 'synthetic-secret-f116-private-only',
]
```

These are public synthetic fixtures, not operational credentials. No identity,
tenant, role, state, callback code, verifier or redirect URI enters the refresh
form. The envelope contains only `client_id`, `client_secret`, `refresh_token`
and `grant_type=refresh_token`. It cannot be cloned or serialized, clears its
secret holder on handoff and permits at most one handoff.

`GoogleSearchConsoleHttpsTransportCandidate::refresh($envelope, $trustedNow)`
rechecks the predecessor expiry before sending the form to the same fixed token
URL and loopback TLS socket. It requires the additional
`PORTAL_GSC_REFRESH_LAB=1` gate, alongside all existing TLS laboratory gates.
No real Google execution mode is introduced. TLS, redirect/proxy restrictions,
timeouts and response limits reuse the existing candidate transport.

The returned token response passes through the unchanged pinned parser. If it
includes a refresh token, the returned candidate uses that new material. If it
omits one, the transport explicitly composes the newly parsed access token and
expiry with the predecessor's refresh material. This preserves actual response
provenance; it does not fabricate a new provider response or token fixture.

The official Google [web-server OAuth documentation](https://developers.google.com/identity/protocols/oauth2/web-server#offline)
describes refresh through the token endpoint. Temporary-consent metadata such
as `refresh_token_expires_in` remains unsupported and is rejected by the strict
legacy parser. This slice does not silently ignore that field, infer a refresh
token lifetime, or allow reading an expired vault bundle.

## Authority and concurrency remain separate

An opaque secret type does not prove how a caller obtained it. Preparing this
envelope does not authenticate a user, check a property grant or admit a stored
version for remote I/O. The integrator must obtain the secret through the real
encrypted/RLS-protected vault and durably claim the exact still-live version
under the current business MFA session and `seo.gsc.connect` before preparing
the request.

Two independently prepared envelopes could each attempt I/O; process-local
one-use alone does not prevent that. The integration's durable version claim
must ensure that a second worker cannot transmit the same version. After TLS
refresh and current sites evidence, the integrator must again validate the
principal, revisions and predecessor version before the transaction replacing
the candidate/grant. An old PKCE state's 600-second TTL is not renewal
authority. A failed or ambiguous exchange must not reopen the attempted version
for automatic retry.

All candidate flags for provider identity, tenant authority, token persistence
and customer display remain false. This is **early renewal of a synthetic
candidate while its predecessor is still valid**, not a complete operational
refresh lifecycle, background scheduler or authorization to continue after
logout, grant revocation or expiry. Key re-encryption remains distinct from
OAuth access-token renewal.

## Evidence

The dedicated command is:

```sh
php apps/portal/labs/gsc-https-transport/verify-refresh-transport.php \
  .qa/gsc-f109/apps/portal/app/Domain/SeoReview CA_FILE METRICS_FILE
```

It verifies omission/preservation, returned-token rotation, use of the refreshed
bearer for sites and denial when that actual new bearer lacks the exact property,
one-use after success or failure, a wrong CA, expiry between
preparation and transmission, malformed inputs and secret-safe diagnostics.
Eight invalid provider responses cover `invalid_grant`, redirects, malformed
JSON, wrong scope, oversized body, timeout and unsupported temporary-consent
metadata. Request counters prove the absence of retry/redirect requests.

The transport harness wraps a synthetic token response in the opaque vault
result type to isolate the transport contract; it does not claim a PostgreSQL
decryption or authorization proof. The integrated Kernel/PG18 harness supplies
the actual encrypted predecessor and proves claim/CAS/rollback and revocation
behavior. PHP AST/static checks and fixture smoke results are also distinct
from execution of those PHP and PG18 workflows.

No operational configuration, provider registration, authentication policy,
real credential, Google request, VPS, DNS, merge or deployment is enabled.
