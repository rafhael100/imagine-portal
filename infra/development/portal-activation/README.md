# Private operator activation and password/MFA rehearsal

This root-operated administrative package follows confirmed email possession. It is not a public registration route, release, business permission grant or customer login rollout. Do not close restore issue #64 or claim production readiness.

## Reviewable action

activate.php requires APP_ENV=staging, an actual operator TTY and PORTAL_OPERATOR_ACTIVATION=PRIVATE_AUTH_ONLY. It first inspects the single invited identity for the fixed approved operator email and displays its organization. It changes nothing until the operator types ATIVAR in that console.

The database transaction pins PostgreSQL 18, portal_identity_dev, postgres/session principal, loopback and private proof/installation marker. It locks the first-account scope and requires:
- exactly one identity, one owner invited membership and one active organization;
- already verified email and enrolled encrypted MFA, valid independent TOTP key and Argon2id hash;
- exactly one consumed email challenge with matching email/identity/membership/tenant/revisions/marker and valid consumption timestamp, no outstanding reserved/delivered challenge;
- zero authentication sessions, membership grants or service claims;
- FORCE RLS on seven protected tables, unprivileged runtime roles with no memberships or direct table privileges.

Only identity.status and membership.status become active; existing revision triggers advance their revisions. Password, encrypted MFA seed, counter, email timestamp and keys are not rewritten. Repeated activation refuses. A second-write error rolls the identity update back. No session or business grant is created.

## Password and authenticator probe

probe-login.php reads the existing Portal password and the six-digit code from the AUTHENTICATOR APP through hidden TTY input, not chat, argv or environment. That is a different code from the email confirmation. It uses the real restricted portal_browser_runtime connection and existing authentication functions to begin password authentication, complete MFA, assert a valid session, assert all four business permission preflights remain denied, revoke the session immediately and verify revocation. Pending/active references are never printed. Cleanup also runs on failure.

Existing throttles are preserved: do not reset counters or retry more than five times per 15 minutes. A failed probe does not silently deactivate the identity or reset its password/MFA. Console handlers return fixed receipts, never provider diagnostics. The wrapper creates complete private backups before and after either operation.

## Operational boundaries

Run only from a separately pinned checkout at /srv/imagine/portal-activation. run.sh refuses a different path, non-root or redirected TTY. It uses the existing provisioning container/namespace with --no-deps, mounting this source read-only. No container rebuild, schema migration, foundation installer or main merge is needed. Web application settings, allowed organization, public proxy and source images stay unchanged; this does not make the public Portal login navigable.

Snapshot restore can resurrect previously revoked authentication state. #64 remains unresolved. This package limits the pilot to root-operated verification with no business grants and immediate session revocation; it does not supply an external monotonic witness or authorize serving customer resources. Public serving and restore/recovery reconciliation are separate work.

## Verification

Dedicated GitHub CI installs only a disposable foundation, reuses the existing synthetic enrollment/email-ledger rehearsal, and adds activation refusal/atomicity/credential preservation plus actual password+MFA and permission-denial/session-revocation tests. No real email or operator credentials are used. Local PHP/PostgreSQL is unavailable; report exact CI results rather than claiming local PHP passes. Bash syntax is checked locally. Operational activation still requires the operator's explicit ATIVAR and a real credential probe in their private console.


## Next step: read-only private activation status (candidate follow-up)

The new `status` subcommand is a read-only snapshot observation. It requires the same root-only pinned private checkout, genuine operator TTY, staging environment, PostgreSQL 18 loopback, private proof key and restricted runtime-role/RLS posture. It never reads the password or authenticator code, starts a user session, activates a membership or gives business permissions. Unlike `activate` and `probe-login`, its wrapper does **not** make unnecessary backups because it performs no data mutations.

From the already pinned authorized private checkout, a human operator can use:
`sudo bash /srv/imagine/portal-activation/infra/development/portal-activation/run.sh status`

Possible messages: `INVITED_VERIFIED_PRIVATE_ONLY` means not activated; `ACTIVE_PRIVATE_STATUS_ONLY` means invited identity/membership are now both active, with no business grants and no unrevoked sessions. `NO_GO` covers unsafe/mixed states, missing proof, unexpected user/org, session activity, RLS or role privilege drift. No result proves password/MFA actually worked or that the Portal is ready for public traffic.

**IMPORTANT:** the PR implementing this status command is a separate draft and MUST be independently reviewed, pinned by commit and placed into the existing private checkout only through the authorized operational process. The CI is disposable; it does NOT touch the Leaseweb VPS. No new deployment/activation is assumed. Restore anti-rollback issue #64 remains **OPEN**, and public serving stays **NO-GO**.
