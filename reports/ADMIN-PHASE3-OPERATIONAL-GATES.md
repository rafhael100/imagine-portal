# Phase3 — operational preparation, pending external approval

DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.

## Review provenance

This is an additional author review, not an independent external AppSec/DBA/UX sign-off. No external reviewer was assigned or impersonated. Scope inspected: Portal891 deployment, grants/migration order, middleware/policy, laboratory transaction paths; Portal892 HTTP client/config/tests; Performance414 envelope, inbox, SQL authority/RLS and financial boundaries. UI evidence is automated CI, not a human accessibility/UX review of every changed view. Full independent review of all changed files remains NO-GO.

Findings addressed: inbox fixed timestamp after lock/read; missing post-lock authority check; unbounded SQL statement/receipt; HTTP destination not pinned to private IP and response not bounded; missing resource/log limits and nonexclusive Compose namespace. Added mTLS material requirements to integrated candidate. Remaining risks: native authority adapter absent, distributed revocation cannot be made atomic merely by checking callbacks, inbox rows retained without a production retention/partition policy, production DB TLS/secret lifecycle and release image digests not homologated. Runtime retains synthetic shared-role tables; these are NOT production multiempresa authorization.

## Official read-provider contract — proposal v1, not connected

Sources: Google Ads [reporting field reference](https://developers.google.com/google-ads/api/fields/v26/metrics), [customer fields](https://developers.google.com/google-ads/api/fields/v26/customer); Meta [account insights](https://developers.facebook.com/docs/graph-api/reference/adaccount/insights) and [Insights API](https://developers.facebook.com/docs/marketing-api/insights/). Documentation consulted 2026-11-16; API version must be explicitly selected/verified before implementing the provider. These links are design references, not proof of an authorized account or API access.

Every request requires a server-owned company/service/account consent reference and current delegation, never a browser-supplied account as authority. Contract fields: provider, explicit API version, account reference, campaign reference, account timezone/currency, start/end dates, attribution/window settings, pagination cursor, fetched_at, upstream request ID, evidence digest and completeness. Normalize provider monetary units with integer/decimal arithmetic; never cast raw costs to float or assume BRL. Preserve raw provenance separately with access/retention policy. Read-only scopes and read/report endpoints only; forbid mutations even with broader token scopes. Bound pages/rows/body/time and honor provider quota/retry-after, with jitter and a durable cursor; revoked consent terminates pagination/retries. No browser/user token passes to Performance. No credentials were requested, read or used here.

Result statuses: unavailable, denied, stale, partial, verified. Missing metrics MUST remain null with reason, completeness and source; zero is permitted only when explicitly returned in a complete, current official response for the matching scope. UI must not label synthetic data or an unverified receipt as official. Account/campaign authorization, OAuth issuance/revocation and credential vault are not connected. ChatGPT Ads has no operational connector or API assumption and stays blocked.

## Financial policy proposal v1 — NOT ACCEPTED

Approved baseline remains R$490 setup/channel, monthly management20% of verified media, R$170 minimum/channel where contract applies; media is separate from Imagine remuneration. Existing V7 and pending-policy blocks remain unchanged. No new pricing decision was made.

Before partial periods can close, approve an immutable document with policy_id/version/digest, contract reference, effective date, timezone, rounding, eligibility intervals, billing/recognition distinction and explicit rules for each case:

| Scenario | Decision required; no default selected |
|---|---|
| First partial month | Full minimum versus calendar-day pro rata; denominator and inclusive boundaries |
| Mid-month channel inclusion/removal | Channel activation/cessation timestamp, minimum proration and whether setup applies |
| Suspension/reactivation | Billable eligible days, management during suspension, setup reuse/new setup |
| Late official statement/correction | Reopen exact revision, preserve previous history, reconcile differences |
| Policy update | Determine affected periods, invalidate incompatible review fingerprint, require new reviewer acceptance |

Test acceptance matrix for future implementation:1-day/last-day start, February/leap year, timezone midnight, verified zero versus missing spend, one/two/three channels, overlapping suspension intervals, duplicate reactivation, late spend, currency mismatch and policy digest changed after review. Expected fee for an unapproved partial policy is null/status contract_policy_required. Existing tests prove this block; they do not choose a policy.

Asaas/C6: prepare sandbox-only server adapters after official credentials/access and authenticated webhook/statement contracts are available. Require sandbox identity, event authenticity, tenant/contract/payment binding, persistent deduplication, replay checks, discrepancies and independent reconciliation before any financial state becomes authoritative. No transactions, token grants or sandbox requests executed in this phase. There is no approved operational provider claim.

## VPS demonstration runbook (not executed)

No VPS commands, deploy, network changes or services were started for Performance in this phase. The separate Sites task previously established private Sites127.0.0.1:18090 and CRM18081 must remain intact. Those observations are historical and do NOT certify a free port/resource budget now.

Before deployment: fresh read-only inventory of all configured/running/stopped container binds, kernel listeners, network/volume ownership, CPU/RAM/disk/IO and backup metadata. Reject18081 and18090; choose/reserve a genuinely free loopback port immediately before startup. No port is selected for Performance yet. Proposed exclusive Compose project: imagine-performance-admin-staging, its own volumes/networks/database and no external Docker network/socket. Explicit memory/CPU/PID limits and rotating logs added; validate under actual host capacity. Do not reuse Portal/CRM/Sites credentials, volumes, DBs or CAs. The existing generic staging proposal must never be started over another project.

Require approved private technical HTTPS domain, trusted TLS, network/access restriction, nominal administrator and MFA, secure provisioning of unique secrets, APP_ENV=staging and all external/charge/write gates off. Do not enable local/testing in a public VPS. No public demonstration URL exists for this phase.

Install/update: pin reviewed commit/image digests, prove provenance, back up first, owner-only additive migrations, runtime minimum grants, TLS DB verification, start exact namespace, check401/403, password/MFA/session revocation, RLS and gates. Do not run migrate:fresh/down-v/remove-orphans against shared projects. Compare all existing container states/restarts before/after.

Backup/recovery: authenticated encryption with separate keys, approved offsite destination/retention/RPO/RTO; restore into a NEW isolated database/volume and prove RLS, grants, revoked sessions/permissions and nonce/receipts survive. Reapply current revocations before serving restored data. Rollback app/proxy to pinned previous images while preserving volumes; incompatible schema needs new restored DB, not destructive migration. Monitor health, resource pressure, sanitized correlation, failed auth/MFA, stale source data, replay and receipt growth. Stop only this project's ingress/app on failed gates. Production/customer publication remains forbidden.

## GO/NO-GO checklist

- [ ] Independent AppSec/DBA/UX review, including native identity source and SQL privilege grants.
- [ ] Approved server-owned Portal Performance authority and service/contract ledger, least-privilege RLS mapping, revocation/rotation and tenant/operator audit.
- [ ] Operational HTTP receiver bootstrap, mTLS ingress/principal mapping, vault, rotation, revocation and no plaintext ingress. Runner fixtures are not deployable services.
- [ ] Cross-system correlation/outcome auditing, authenticated nominal login/MFA tied to delegation, two simultaneous operators and revocation under actual distributed concurrency.
- [ ] Real official read sources/consent and partial financial policy approved; sandbox gateways homologated.
- [ ] Fresh host inventory, private domain/access path, pinned release images and TLS DB; backup/restore/rollback on the actual authorized staging host.
- [ ] Explicit deployment authorization. No public address or operational homologation claimed.
