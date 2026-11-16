# Phase6 — release proposal, no installation authorization

This is a new candidate project `imagine-performance-p6-staging`, not replacement of any of the eight existing Compose projects. No external networks/volumes, shared SQL credentials or Docker socket. Never apply schema to CRM, Sites or existing Portal databases. Preserve18081 and18090. No deploy command is executed automatically.

## Inventory / resources

Run readonly-inventory.py on the authorized actual host; its result always remains NO-GO pending review. Candidate18092 and10.87.6.0/28 are NOT reserved or currently verified. Recheck host routes, Docker IPAM, all-interface listeners, CPU/memory/disks and current stacks immediately before installation. Select another candidate only by reviewed manifest, not silent automatic fallback. Pinned receiver IP must lie inside the selected service_link subnet and not equal its network/gateway/broadcast or any existing endpoint. Portal pins that IP while validating performance.internal with CA/mTLS. No hostname-only fallback.

Six base services; CPU maxima4.25, memory2816MiB. Optional explicit compose.access.yaml connector adds0.25CPU/128MiB (total4.5CPU/2944MiB), excluding backups/build/filesystem cache. These are ceilings, not measured capacity or guaranteed reservations. Leave8GiB RAM and2CPU scheduling margin for other projects; collect load evidence after authorized start. Four namespaced volumes, six networks, one loopback HTTPS listener. PostgreSQL18 images must have verified UID999 and correct /var/lib/postgresql volume ownership; otherwise gate blocked. All images must be reviewed digests with matching candidate source; existing unpinned Dockerfiles are build candidates, not immutable deployed artifacts.

## Exclusive materials / PKI

Custodian must approve and produce all real staging materials securely, never in chat/GitHub/CI artifacts. Distinct roots or issuing intermediates for this environment, private CA keys offline from the VPS runtime. Do not reuse CI certs, customer certs or another project's secret. Leaf SANs: portal-db, performance-db, performance.internal and the approved browser hostname; client EKUclientAuth for Portal service, server EKUserverAuth for origins/PG. Validate chain, EKU, SAN, expiry, CRL and key match before start. Proposed leaf lifetime30days/rotation7days before expiry requires security approval. Revoke compromised keys immediately at SQL delegation + keyring + CRL; force reload/invalidate sessions where applicable. Retire old key after max60s signed-request lifetime only if no incident requires immediate revocation.

| Mount | Custody / minimum |
|---|---|
| Portal secret dir | app key, own runtime SQL password, separate authority SQL password, server S2S identity JSON;0400/0600 readable by UID33 only; no migration owner password |
| Portal PKI | own PG CA and service CA/client certificate/client key; private key0400 UID33; public CA/cert0644 permitted |
| Web PKI | browser leaf and key + own nominal Basic auth file; key/access file0400 UID101; no shared credentials |
| Each PG private dir | own bootstrap password, server cert/key and pg_hba.conf; password/key0400 UID999; hostssl SCRAM only, no hosttrust or non-TLS host rules |
| Receiver private dir | reader password, service keyring and db-ca.pem; private files0400 UID33, never owner credential |
| Receiver TLS dir | own server key/cert, clients-ca.pem and clients.crl; Nginx runtime-readable key, service peer fingerprint enrolled by custodian |

Config reads secret files rather than placing app/SQL keys in container environment. Identity JSON/keyring format is the existing signed service contract; issuer/key/certificate SHA256/company/operation/revision must match owner-maintained grants. Flags alone cannot grant access. Mode stays APP_ENV=staging/private_staging, feature flags default OFF. No synthetic credential becomes operational identity.

## Native admin and synthetic authorization

Use existing trusted Portal admin provisioning one-shot outside HTTP runtime with narrowly held migrator access. User chooses nominal password and enrolls fresh TOTP through secure procedure; no password/TOTP seed in screenshots/logs/tickets. Verify password→MFA→session_version; revoke session by epoch. Provision only two explicitly synthetic companies, source_kind=synthetic_persisted, expiring product-specific dashboard.read grants for approved nominal administrators. No seo.view/CRM permission reuse. Lab-alfa/beta memberships are independent local fixtures, NOT the UUID authority. No real contract/company/campaign data needed for this demo.

Apply reviewed additive Portal migrations in order through000020 on own NEW DB, not temporary CI schema; owner outside app. Apply existing minimum grant-runtime.sql then separate performance_authority SELECT on services/grants + INSERT on access_audit only, schemaUSAGE, NOSUPER/NOBYPASS/NOCREATE/nonowner. Do not grant INSERT on authority or SELECT on audit to authority reader. Disable Automações transport unless separately configured, no inherited grants.

Performance own DB: apply002→003→004 contracts using migrator, then reader only USAGEschema, SELECT/INSERTreceipts+nonces, INSERTrequest_audit+USAGEsequence, EXECUTEcan_scope/can_nonce/resolve_delegation; no direct read/write authority, UPDATE/DELETE/TRUNCATE/ownership. Enroll technical identity only after PKI+scope review. SQL and keyring revocations must be exercised before the demo.

## Browser-only access proposal

Proposed hostname `performance-hml.imaginecomunica.com.br` requires owner confirmation; not created/resolved/tested by this package. Optional own connector overlay (UID65532, credentials0400, origin https://portal-web:8443, no host networking) + Cloudflare Tunnel + default-deny Access email allowlist, with origin TLS validated via originServerName/caPool and Access JWT validation required. Then separate Portal password+TOTP remains mandatory. No WARP/VPN/tool on user's computer. No public unrestricted origin, quick tunnel or bypass/noTLSVerify. cloudflared.example.yml is a proposal only; Access app/audience/team/tunnel identity/certificate custody are missing until securely configured. Creating tunnel/DNS/Access permission requires explicit release authorization. A raw SSH tunnel alone does NOT meet the browser-only requirement.

Official references consulted16/11/2026: https://developers.cloudflare.com/tunnel/reference/origin-parameters/ ; https://developers.cloudflare.com/tunnel/troubleshooting/https-origins/ ; https://www.cloudflare.com/plans/ . PlanFree advertises50users; verify actual account eligibility/limits before configuring. No paid upgrade authorized. R2 was reported enabled by the direction, but no bucket/credential/custody verified here.

## Backup / isolated restore / rollback

No external destination or credential has been proved in this package. Propose dedicated existing R2 bucket/prefix only after authorization; no new billing agreement. Budget/quota/retention must be approved before upload. Back up BOTH own PostgreSQL DBs consistently with pg_dump formatcustom, Portal storage, immutable image/source manifests, RLS/grant definitions and inbox/nonces/receipts/audit; protected app key/TOTP encryption key, signing keyring and certificates separately sealed under external custodian control. Never archive plaintext secrets or another product's volumes. Reuse the existing central AEAD backup/seal procedures after exact-version review; do not treat a local volume as external backup.

Recovery gate: authenticated decryption first; restore a new isolated project/database, no overwrite or production route; compare table counts, content hashes, FORCE RLS/grants/SQL profiles, session revocation epochs, delegation/keyring versions and receipt/nonces. Test latest revocation after restore; a stale snapshot must fail closed until reconciled. Preserve revocations across rollback and do not replay reads as campaign writes. Record actual object receipt/digest, custodian, restore duration, RPO/RTO. Proposed synthetic-demo RPO24h/RTO4h and retention7daily/4weekly are proposals requiring approval; no evidence of external restore is claimed.

Update: pin old and candidate source/image digests, verify exact migration diff, take validated backup before owner migration; disable demo entry, apply only own additive schema, update own app/proxy after tests and release approval, compare other eight projects' IDs/StartedAt/restarts. Do not merge automatically. Rollback disables new ingress/flags, returns to previous own image/config, preserves DB/volumes/audit/revocations. No migrate:rollback/volume deletion or authority downgrade. Restore/cutover requires explicit approval and a new isolated target if schema incompatible. Never use older code that re-enables known DSN/snapshot defects.

## Logs / denial policy / monitoring

Three separate trails: (1) Portal MFA/session security's native audit; (2) grant-scoped SQL intent/completion and engine request_attempt/result; (3) pre-authorization/transport log without trusting tenant/actor supplied by denied input. Prepared ingress JSON logs only request_id/status/duration, including Basic/MFA early401/403, without URI/query/header/username; Access connector joins ingress only, not the FPM front network. New performance_security JSON projection permits fixed event + UUID correlation only; no cookies, tokens, SQL, request body, metrics, hostname or exception string. Pre-grant failures are intentionally not inserted into a tenant table by bypassing RLS. Positive SQL audit remains mandatory; failure withholds response. A lost stderr sink may lose early-denial events: operator must monitor collection; do not claim full durable cross-system audit until external retention verified.

Docker logs rotated10MiB×3 per service, bounded SQL3s/lock1.5s, HTTP timeouts. Before demo, configure private monitor for portal health/MFAlogin, PGready+TLS, expiry thresholds, filesystem80%, RAM pressure, backup age, repeated403/5xx/timeouts; alert destination/operator not created by code. Never probe mTLS endpoints with public monitor. Record incident and revoke/disable access if PKI/audit/backup authority unavailable.

Revocation is rechecked around reads, retries and cached receipts under independent READ COMMITTED transactions. No guarantee of distributed atomic revocation is made between the final SQL check, commit and network response; sensitive writes remain unavailable. Test concurrent revocation from a second SQL connection and fail before returning when observed. This residual window is part of the first read-only synthetic demo's security decision.

## Demonstration script / approval boundary

Login nominal +MFA→Portal home→ten local lab modules (explicit simulated source)→UUID company selector→private S2S read→correlation/SQL audit. The receiver currently returns unavailable/null official metrics; local simulated campaigns are NOT receiver data. Added navigation labels make the boundary explicit. Verify two simultaneous administrators, cross-company denial, MFA expiry, session/delegation revocation, retries, no billable/external flags. Do not fabricate official indicators or contract eligibility to make screenshots attractive.

Approval required: isolated ninth project and reviewed resources; hostname/private Access policy + approved emails; exclusive PKI/nominal provisioning; external backup destination/custody/retention/cost ceiling and isolated restore; exact source/images/migration manifest; scoped initial start and post-start validation. If host inventory, secrets, backup or access remain missing, status remains NO-GO; a general approval cannot count as executed evidence.
