# F385 — Real PostgreSQL18 logout and forced SQL failure (revocation retry still NO-GO)

F384's PHPUnit controller test used a synthetic identity adapter to demonstrate the revocation-only path can avoid a witness outage, without opening login/session/business routes. F385 repeats the essential durability question against the actual restricted Portal PostgreSQL 18 authentication adapter and actual PortalBrowserAuthController::logout() method.

A dedicated GitHub-hosted CI bootstraps disposable PostgreSQL 18, provisions the Portal identity foundation with non-customer synthetic credentials, creates real business-shaped session references under a test identity, and calls the existing Laravel logout controller with an in-memory Laravel session. The real backend session must become revoked before the controller returns HTTP 204 and removes the browser envelope.

The same test then creates a fresh active SQL session and installs a temporary, runner-only PostgreSQL trigger that forces durable revocation UPDATE to throw an error. The controller must return 503 authentication_unavailable, redact SQL details and clear the browser envelope. Crucially, the SQL session remains active in the database. The test explicitly asserts that this is not durable revocation and cannot be used for serving. After dropping only the synthetic fault trigger, it retries revocation using the real restricted adapter, asserting that the SQL record becomes revoked.

## Security consequence

A purely local browser logout is not sufficient during SQL failure; a recovery/restored SQL session can remain active after a failed write. Production needs an independently durable revocation intent/outbox or a related witness-first protocol that survives local PostgreSQL failure, has an authenticated retry worker, and prevents all protected HTTP/tenant/resource serving until the revocation is reconciled. Neither F384 nor F385 implements that operational protocol. Browser logout must not claim success on error.

This is not an end-to-end live Laravel HTTP route, since the direct controller invocation uses an in-memory test session; F384 already tests the real HTTP middleware/CSRF route with a synthetic adapter. Both proofs complement each other but are not equivalent to a production deployment. No customer data, public route, real external Cloudflare/D1, production SQL change, VPS, DNS, merge, deploy or expense. Security issue #64 remains OPEN/NO-GO.
