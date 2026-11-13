# Private PostgreSQL email confirmation

This branch adds a concrete administrative PHP ledger, a pipe bridge and a Python operator console to the transport/coordinator previously isolated in #609/#610. It is for exactly one private invited operator, not a customer registration endpoint or application runtime provider.

## Isolation

The host Python process sends through the existing Workspace relay and never reads the PostgreSQL administrator password, application key, TOTP key or email HMAC key. A read-only tool mounted in the existing provisioning container uses the PostgreSQL loopback namespace. JSON with the ephemeral proof travels only over child stdin; no secret appears in argv/environment, normal receipts or provider exceptions. No new database port, HTTP route, application egress, grant or web registration is created.

A new independently random 32-byte email-proof key must be generated in the foundation's root-protected .private directory, mode 0600, UID/GID 82, without displaying it. It is mounted into the tool only. The existing backup archives the complete .private directory; take a complete backup before installing the separate ledger and after confirmation. Never rerun the empty-foundation installer/verify against the enrolled database.

## State and proof rules

PgLedger creates public.portal_development_email_challenges separately, refuses an existing table, enables FORCE RLS with no runtime grants/policies, and pins database portal_identity_dev, PostgreSQL 18, admin principal, IPv4 loopback, installation marker and existing private proof key. Scope checks refuse weakened RLS or runtime access to the new table.

Only the single invited identity with enrolled password/MFA, one owner invited membership in one active organization, no sessions/grants/resource claims may reserve. HMAC binds the six-digit numeric code to identity, organization, membership, normalized email, all three security revisions, random reference and installation marker. Only the keyed digest persists. TTL is 5 minutes from database time. A partial unique index prevents parallel live challenges.

States: reserved -> delivered only after SMTP acceptance. Delivered -> consumed and email_verified_at commit together after digest/scope/revision/deadline checks. Wrong valid-format proofs count toward five attempts, then blocked. Failure requests revocation and never retries automatically. Expired or uncertain challenges require explicit administrative reconciliation; no automatic reissue. An immutable-scope/deadline trigger prevents terminal-state reactivation and attempt resets.

The ledger uses broad locks appropriate for this one-account private pilot, not a scalable public registration service. A 2-second lock conflict refuses rather than guessing success. The web application continues to deny login; identity and membership stay invited and no session/business grant is created.

## Operator procedure after CI/review and authorized private installation

1. Back up the existing enrolled database. Generate the independent email-proof key without output; do not overwrite an existing key.
2. Check out the exact reviewed tool SHA at /srv/imagine/portal-mail, keeping source readable and secrets private.
3. Run DockerLedger.install() once; it refuses an existing ledger. No identity schema installer or old migrations run.
4. Hand the console to the operator BEFORE starting confirm_email.py. Require root/private TTY and PORTAL_EMAIL_CONFIRM=PRIVATE_INVITED_EMAIL_ONLY.
5. The console displays exact sender/recipient and waits for the operator to type ENVIAR. Only then does it reserve and send the proof.
6. Operator copies the six-digit code from the received email into hidden console input. Never submit code/password/MFA seed to chat. Up to five attempts; no redirecting/recording the console.
7. Observe only the final non-secret receipt, verify email_verified_at and invited statuses without reading secret fields, then take a complete backup.

## Tests and remaining limitations

28 local Python unittest tests cover transport, coordinator and child-pipe behavior. A dedicated CI installs the actual foundation into disposable PostgreSQL 18, seeds the synthetic invited account using the existing enrollment rehearsal, and exercises ledger uniqueness, reserved-vs-delivered rules, five-attempt lockout, key/revision mismatch, revocation, expiry, immutable fields, effective grant/RLS drift refusal, lock conflict, successful one-use confirmation and rollback on forced second-write failure. Results must be read from the exact workflow run; no local PHP/PostgreSQL is available.

Restore of database AND keys can resurrect old proof state (#64). Installation marker is scope binding, NOT an external monotonic witness. No login activation or public serving is permitted by this package. Before any future login activation, resolve restore/quarantine/revocation handling and reconcile outstanding proofs. This tool does not claim production readiness or independent security approval. The operational ledger is separate from the review-only #603 migration; neither that migration nor test fixtures are deployed by this tool.

## Six-digit operator code
The operator requested a human-readable numeric code because the remote console cannot paste reliably. New issuance uses secrets.randbelow(1_000_000), zero-padded to exactly six ASCII digits. No code is stored in plaintext: the existing independent HMAC key binds it to all scope fields. Maximum five failed comparisons per challenge, five-minute database expiry, minimum 60 seconds between requests and at most three issued challenges per identity/hour (including revoked/blocked ones). These rate checks share the identity scope lock, so concurrent resend cannot bypass them. Never reset historical attempts to bypass throttling. The database's existing immutable table still allows at most nine minutes structurally; the issuer only accepts TTL=300 and writes exactly five minutes. No table migration or key reset is needed. The HMAC domain changes to v2-six-digits; old 64-character codes must be revoked, not reused.
