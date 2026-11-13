# Verification coordinator boundary

The import-only coordinator connects a ledger protocol to the private Workspace transport. It reserves a cryptographically random 256-bit token, sends it in the message body only, and marks delivery accepted only after SMTP acceptance. Failure requests revocation and never retries automatically. Confirmation delegates an atomic digest/scope/revision/TTL/attempt-limit/one-use operation to the ledger, returning email_verified only. Login and business authorization stay false in every receipt.

## Explicit scope and limitations

There is NO concrete PostgreSQL implementation of ProofLedger in this package, no production issuer, no CLI/HTTP route, no container/network integration and no runtime registration. This cannot yet verify the enrolled VPS identity. Do not substitute the test mocks or the disposable PHP test fixture as an operational ledger.

The protocol requires:
- authoritative scope read from an existing invited identity and unique invited membership in an active organization, with no prior sessions/grants;
- keyed digest bound to identity, organization, email and revision; random non-secret reference, independent from the token;
- authoritative database time, TTL 540 seconds, attempt limit and single pending challenge;
- monotonic reservation/delivery/revocation states and single consumption with email_verified_at in the same transaction;
- consume only an acknowledged, unexpired delivered challenge; reject changed revisions, restored/quarantined state and already consumed records;
- reservation rollback on failure, and quarantine/reconciliation if revocation cannot be durably acknowledged.

SMTP acceptance does not prove inbox delivery or ownership. A delivery acknowledgement lost after DATA can be ambiguous. The coordinator never assumes that a revocation request succeeded and does not automatically reissue a proof. A persistent implementation and restore/revocation integration must settle uncertain states before enabling real enrollment delivery.

## Verification evidence

Twenty local standard-library unittest tests pass: eight transport tests and twelve coordinator tests, using mocks only. They prove sequencing, fixed pilot recipient, token exclusion from receipts/subject, refusal to send after reservation/scope failure, revocation requests, absence of automatic retry, invalid-input rejection and fixed error receipts without exception chains. Mocked consume outcomes exercise coordinator behavior, NOT actual PostgreSQL expiration, concurrent consumption, attempt counting or restore handling. Those guarantees still require real database integration tests.

No new messages were sent, no identity records changed and no login was enabled by this change.
