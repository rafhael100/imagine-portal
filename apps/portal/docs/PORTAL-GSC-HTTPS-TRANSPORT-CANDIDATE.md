# GSC: strict HTTPS transport candidate and disposable TLS proof

Status: unregistered development candidate. No Google traffic, real credential,
customer authority, production token use or operational activation is included.
Issue #810 remains open for its real Google and independent review requirements.

## Base and existing contracts

This slice starts at Portal #929,
`58aa1ae4b82cc0fc2bbe8cb481d62c50c6fd136a`, and keeps the secondary GSC core at
`de3315df4e8b8c07228d76f6613c799f9c174f31`. That core supplies the existing
`GoogleSearchConsoleOAuthTokenPostRequest`, its one-use handoff, the strict
`GoogleSearchConsoleOAuthTokenResponsePolicy` and the opaque token candidate.
The native `GoogleSearchConsoleSiteListingReview` and exact property identifier
are reused for sites responses. No parallel token/property authorization parser
is introduced.

The preceding HTTP-kernel laboratory proved the authenticated application and
PostgreSQL chain with plain HTTP loopback. It did not exercise certificate
verification. The new transport fills that concrete development gap while
preserving the Portal's closed operational configuration.

## Component and entry points

`app/Infrastructure/SeoReview/GoogleSearchConsoleHttpsTransportCandidate.php`
is not registered in a service provider or route. Its constructor is private.
The only factory is:

```php
GoogleSearchConsoleHttpsTransportCandidate::forTlsLab(string $caFile): ?self
```

The factory and both request methods require CLI SAPI, `APP_ENV=testing`,
`PORTAL_GSC_HTTPS_TRANSPORT_LAB=1`, GitHub Actions on a hosted runner, cURL and
sodium. Existing instances recheck those guards. The CA must be a readable,
bounded regular file, not a symlink. This is a disposable test facility, not an
environment switch for production. `config/google_search_console.php` and all
operational route, provider, IAM and vault settings remain unchanged.

The methods are:

```php
exchange(GoogleSearchConsoleOAuthTokenPostRequest $envelope, int $trustedNow)
    : ?GoogleSearchConsoleOAuthTokenCandidate

inspectSites(GoogleSearchConsoleOAuthTokenCandidate $candidate,
    string $exactSite, int $trustedNow): ?array
```

`exchange` consumes the existing envelope before validating/sending its form.
An attempted exchange remains consumed after TLS, HTTP, timeout or response
failure. A disabled laboratory gate refuses the operation before consuming the
envelope. No automatic retry exists for either operation; a one-use code is
never retransmitted by this component.

Only synthetic code/secret/token values are accepted in this execution mode.
The successful token response is parsed into the existing opaque type. The
sites method places its synthetic access token only in the Authorization
header and returns a property fingerprint plus safe laboratory flags. It never
returns raw headers, body, tokens, OAuth code or verifier. Exceptions and cURL
diagnostics are not included in results.

The candidate's backend getters do not authenticate its source. In particular,
passing a candidate object to `inspectSites` does not establish that it came
from this transport, a consumed state, Google or a tenant-authorized callback.
Those remain separate provenance and authority requirements; every positive
authorization flag remains false.

## Actual TLS with no Google connection

The two URL/method pairs are fixed:

| Operation | Canonical URL | Socket mapping in the only execution mode |
| --- | --- | --- |
| Token exchange | POST `https://oauth2.googleapis.com/token` | `oauth2.googleapis.com:443:127.0.0.1:18443` |
| Property list | GET `https://www.googleapis.com/webmasters/v3/sites` | `www.googleapis.com:443:127.0.0.1:18444` |

The official Google documentation specifies the
[token exchange endpoint](https://developers.google.com/identity/protocols/oauth2/web-server)
and the [sites.list GET endpoint and readonly scope](https://developers.google.com/webmaster-tools/v1/sites/list).
Sites requests have no body, and the exact property identifier is used only to
review the response; it cannot become the request destination.

The fixture generates a private ephemeral CA and separate leaf certificates
whose SANs match the two canonical hostnames. `CURLOPT_CONNECT_TO` redirects only
the TCP connection to the fixed loopback ports. As documented by
[libcurl](https://curl.se/libcurl/c/CURLOPT_CONNECT_TO.html), the original URL
hostname remains the TLS SNI/verification and HTTP hostname. The fixture also
rejects an unexpected SNI or Host value.

The client explicitly enables
[certificate-chain verification](https://curl.se/libcurl/c/CURLOPT_SSL_VERIFYPEER.html)
and [hostname verification](https://curl.se/libcurl/c/CURLOPT_SSL_VERIFYHOST.html),
requires TLS 1.2 or newer and HTTPS-only protocols, disables redirects and
proxies, and uses a fresh non-reused connection. The observed remote IP/port and
effective URL are checked against the same constants after the transfer.
There is no browser-supplied host, endpoint, proxy, CA or cURL option.

The response bounds are 8 KiB of headers, 16 KiB for token bodies, and 32 KiB for
sites bodies. Connect timeout is 500 ms and total timeout is 2 seconds. Only
JSON with an optional complete `charset=utf-8` or `charset="utf-8"` parameter is
accepted. Unexpected content encoding is refused; no compressed response can
bypass the byte limits.

Successful TLS proves that the synthetic peer has a certificate for the
requested hostname under the ephemeral test CA. It does **not** prove Google
identity, consent, real access-token validity or real property ownership.
`tlsLabVerified=true` is deliberately distinct from
`providerOriginVerified=false` and `productionAuthorized=false`.

## Authenticated Kernel composition

The existing `labs/gsc-http-kernel/HttpKernelLab.php` accepts this candidate only
through server-owned laboratory composition. The harness selects it with the
explicit TLS laboratory flag and `PORTAL_GSC_HTTPS_CA_FILE`. If factory creation
fails in TLS mode, the run fails; there is no fallback to plain HTTP.

F115 still consumes the exact original session-bound state before F118 creates
the one-use token envelope. The same candidate returned by `exchange` reaches
`inspectSites` and the existing vault. The local F118 review adapter reports
only synthetic loopback success. The safe HTTP response identifies the TLS lab
mode without claiming Google verification.

The same post-response transaction then revalidates the original session,
membership revision, organization and `seo.gsc.connect`, and preserves the
existing SQL/RLS locks through atomic candidate/grant persistence. TLS success
does not bypass that fence. Revocation races, connect/view isolation, replay,
cross-tenant checks and secret oracles are rerun by the Kernel harness with TLS.
No changes to the F114/F115 authorization rules or vault implementation are
part of this slice.

## Tests and CI

Files under `labs/gsc-https-transport/`:

- `make-certificates.py`: creates the ephemeral CA, a wrong CA, correct-host leaf certificates and a wrong-host leaf certificate under the runner's temporary directory.
- `mock-tls-provider.py`: listens only on `127.0.0.1:18443` and `127.0.0.1:18444`, validates the request form/headers and records numeric counters without secrets.
- `verify-https-transport.php`: exercises the actual PHP/cURL candidate against those TLS sockets.

The PHP command is:

```sh
php apps/portal/labs/gsc-https-transport/verify-https-transport.php \
  .qa/gsc-f109/apps/portal/app/Domain/SeoReview \
  "$RUNNER_TEMP/gsc-https-tls/ca.pem" full \
  "$RUNNER_TEMP/gsc-https-tls/metrics-valid.json"
```

After restarting the fixture with `--certificate-mode wrong-host`, the same
runner uses mode `wrong-host` and that process's new metrics file. Trust-root
failures use `wrong-ca.pem` in the full suite; hostname failures use a certificate
signed by the correct CA for the wrong SAN. Both failures must occur before any
HTTP form or bearer reaches either server. The wrong-host sites input is an
explicit negative synthetic candidate; it is not presented as a successful
exchange or part of the positive chain.

The matrix covers:

- Valid form and header-only bearer, optional refresh, plain/quoted JSON charset and exact property evidence.
- Wrong CA and wrong SAN, no repeated envelope after a failed handshake, and no proxy interception through process environment variables.
- Twenty invalid token response cases, including same-origin/cross-origin/egress redirects, HTTP errors, wrong MIME, unbalanced charset quotes, bad JSON, oversized headers/body, timeout, wrong scope/type and invalid expiry.
- Twelve sites scenarios, using tokens actually received from the corresponding exchange: malformed/oversized/error/redirect/timeout responses, missing property, restricted permission and duplicates.
- Invalid or non-synthetic request fields, bearer header injection, expired/overlong-lifetime candidate, environment gate changes and serialization/redaction behavior.

The fixture's token, sites, redirect-target, unexpected-route and violation
counters are compared with a fresh baseline for each assertion group. A failed
request alone does not prove that a redirect or retry was prevented; the
counter oracle must also show the expected single or zero HTTP requests.

`.github/workflows/portal-gsc-https-transport-ci.yml` owns the dedicated TLS
matrix. The existing authenticated Kernel workflow additionally runs the full
PG18 chain in TLS mode and preserves its strict rollback. Certificates and
keys are generated only in the disposable runner and are not committed or
uploaded as artifacts. The original HTTP mock remains available for the
Kernel harness's separate F116 sink regression tests.

Development validation is reported separately: PHP AST parsing and static
workflow checks do not establish PHP/cURL or PG18 success. Actual execution
results for this candidate must come from the workflow; no assertion count or
passing PHP run is claimed here before that evidence.

## Remaining operational work

This component has no operational factory, registration or enable toggle. A
future reviewed binding must source real client and vault material from managed
secrets and retain the authenticated state/session provenance and post-network
revocation fence. Google Cloud registration, consent, a controlled real test
property, official Google HTTPS traffic, staging/browser validation and
independent AppSec/DBA/LGPD release review remain external to this delivery.

No refresh operation, Search Console write, Google revocation request, real
credential, customer data, VPS, DNS, merge, deployment or new spending is used.
