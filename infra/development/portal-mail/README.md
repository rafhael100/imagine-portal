# Google Workspace relay — private development adapter

The import-only Python standard-library relay adapter isolates SMTP delivery from account verification. It has no CLI, issuer, database connection, activation function, background worker, HTTP route or environment toggle. Importing or running the module sends nothing. It is not registered with the Laravel application.

## Observed VPS evidence

The operator configured Google Workspace SMTP relay for the single VPS IPv4 85.17.188.240, domain senders and mandatory TLS. A certificate-validated STARTTLS handshake on port 587 succeeded. SMTP MAIL FROM and RCPT were accepted. After explicit authorization, a plain test message from sistema@imaginecomunicacao.com.br to rafhael@imaginecomunicacao.com.br was accepted without recipient refusals; the operator confirmed inbox receipt. This validates that one test delivery only, not domain-wide deliverability, SPF/DKIM/DMARC or account verification.

The first-account tool separately returned PASS. The identity remains invited, email unverified and login blocked. A post-enrollment backup exited 0. Do not run the empty-foundation verifier or installer against that populated identity database.

## Adapter behavior

- Fixed relay host, port 587, IPv4 source binding and pilot sender/recipient.
- Certificate/hostname validation using the platform trust store; mandatory STARTTLS, then fresh EHLO.
- No account password, SMTP AUTH, debug output, logging, local queue or automatic retry.
- Explicit single envelope recipient; header injection and payload-size checks.
- Fixed error receipts discard provider exception chains and sensitive responses.
- A successful send_message means SMTP acceptance, not inbox receipt. DATA failures may be ambiguous: do not blindly retry. QUIT errors after acceptance do not report delivery failure.

Run `python3 -m unittest -v test_relay.py` from this directory. All eight tests mock SMTP; CI never sends email.

## Remaining integration

A reviewed issuer must bind the challenge to the existing identity, membership, organization, email and security revision; persist only its digest; handle expiry, attempts, revocation, single consumption, ambiguous delivery and restoration. Never pass secrets via shell arguments, environment, logs or chat. The existing service container is internally isolated; host SMTP reachability does not establish application-container egress.

Do not call this adapter with a real verification challenge until that integration and the restore/revocation handling are implemented and tested. Do not mark email verified merely because SMTP accepts a message or the operator reports receipt of the plain test.

## Private ledger integration
See VERIFICATION.md for the new administrative PostgreSQL bridge and operator console. The relay remains import-only; the separate console requires operator control, explicit ENVIAR and hidden proof entry. This does not enable login or public application routes.

## Six-digit operator code
The operator requested a human-readable numeric code because the remote console cannot paste reliably. New issuance uses secrets.randbelow(1_000_000), zero-padded to exactly six ASCII digits. No code is stored in plaintext: the existing independent HMAC key binds it to all scope fields. Maximum five failed comparisons per challenge, fifteen-minute database expiry, minimum 60 seconds between requests and at most three issued challenges per identity/hour (including revoked/blocked ones). These rate checks share the identity scope lock, so concurrent resend cannot bypass them. Never reset historical attempts to bypass throttling. The database's existing immutable table still allows at most nine minutes structurally; the issuer only accepts TTL=900 and writes exactly fifteen minutes. No table migration or key reset is needed. The HMAC domain changes to v2-six-digits; old 64-character codes must be revoked, not reused.

## Operator-approved 15-minute deadline
The operator explicitly chose 15 minutes. New issuance requires TTL=900 in both coordinator and PostgreSQL writer. Run DockerLedger.upgrade_ttl() once against the existing private ledger before issuance; it locks the table and replaces only the two-column deadline check with the named fifteen-minute check, inside one transaction. Existing deadlines, statuses, attempts and identity data are unchanged; it does not extend or revive old codes. Fresh installations use the same constraint. No foundation reinstall, key rotation or credential changes. Tests reproduce the legacy nine-minute check, upgrade it, and require an exact 900-second deadline for new codes.
