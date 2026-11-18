# Offline materials comparison — Phase12

`materials_preflight.py` compares an already prepared private package with an explicit, externally reviewed expectation. It complements `preflight.py`; it does not install or repair a package. It uses Python's standard library and a local OpenSSL 3 executable, without Docker, a database connection, network requests, credential generation or changes to materials.

Success is `MATERIALS_MATCH_OBSERVED`, with exit code 0. Refusal is `REFUSED`, with exit code 1 and one fixed reason code. Both outputs keep `deployment_authorized`, `human_approval_verified`, `custody_verified`, `sql_grants_verified`, `live_tls_verified` and `nominal_enrollment_verified` **false**. A matching manifest is an input comparison, not proof that its author has operational authority.

## Invocation and prerequisites

Run the installed, reviewed script by its absolute path on Linux with Python 3, `/usr/bin/openssl` 3.x and `/proc/self/fd`. The invoking operator must already be allowed to read the seven private directories; their different owners usually require a trusted local root session. The utility does not acquire privileges or change access permissions.

```sh
python3 -B /approved/portal/apps/portal/tools/performance-release/materials_preflight.py \
  --mode operational-candidate \
  --manifest /approved/release/materials-expected.json
```

Those paths are illustrative. The manifest must be an existing absolute canonical path. There are no discovery, generation, correction, secret-on-argv, OpenSSL-option or installation arguments. Argument abbreviations and extra arguments are refused. Standard output contains a single bounded JSON result; private OpenSSL diagnostics never reach standard output or standard error.

The verifier opens regular files read-only, pins descriptors, checks metadata again before success and uses inherited descriptors for OpenSSL CA/chain/CRL inputs. It never writes certificates or keys to temporary files. Private values are compared in process memory. Only public certificate/CRL DER is fingerprinted; passwords, application keys, shared secrets, private keys and Basic password hashes are not fingerprinted or reported. Python memory is not a secure erasure facility. Run this under the same private operator procedure used to handle the original files, without process tracing, core dumps or exposing the process memory.

## Fixed mounted package

Every runtime directory has mode `0700`, the indicated numeric UID **and** GID, and exactly the listed files. Links, hard-linked files, directories in place of files, special files, empty files, additional files, unsupported modes, changed files and noncanonical paths are refused. Directory paths must be distinct and cannot contain one another. Individual files and the manifest are limited to 128 KiB.

Public certificates, CA bundles, CRLs and `pg_hba.conf` allow `0400` or `0644`. Private files require `0400`, except the four Portal secret files, which also allow `0600`. These are the existing mounted ownership profiles, not instructions to apply chmod/chown automatically.

| Manifest directory key | UID:GID | Exact files |
|---|---|---|
| `portal-secrets` | `33:33` | `portal_app_key`, `portal_db_password`, `portal_authority_password`, `portal_service_identity` |
| `portal-pki` | `33:33` | `portal_db_ca.pem`, `performance_ca.pem`, `portal_client.pem`, `portal_client.key` |
| `web-pki` | `101:101` | `tls_certificate`, `tls_private_key`, `access_passwords` |
| `portal-pg` | `999:999` | `bootstrap_password`, `server.pem`, `server.key`, `pg_hba.conf` |
| `performance-pg` | `999:999` | `bootstrap_password`, `server.pem`, `server.key`, `pg_hba.conf` |
| `receiver-private` | `33:33` | `db-password`, `service-keyring.json`, `db-ca.pem` |
| `receiver-tls` | `101:101` | `server.pem`, `server.key`, `clients-ca.pem`, `clients.crl` |

The manifest also names **one public file outside those mounts**, `browser_ca_file`. Compose does not provide the browser-origin CA as a runtime file, so this reference supplies an explicit trust anchor instead of silently using the host's trust store. It has mode `0400` or `0644`, is a single-linked regular file and is owned by root or the invoking operator. Its certificates are pinned like all other CAs. The public manifest itself permits `0400`, `0600` or `0644` and the same root/operator ownership rule.

The verifier does not open the CI bootstrap directories, browser vault, curl authentication file, CA private key, recovery keys, another project's materials or an arbitrary directory tree. Bootstrap SQL passwords are read only from the two explicit PostgreSQL mounts. No owner or migrator password may appear in the Portal or receiver runtime secret sets.

## Expected-manifest contract

Schema: `imagine.performance.materials.expected.v1`. All keys below are required; unknown or duplicate JSON keys and nonfinite numbers are refused. The operational expectation must come from the custodian's reviewed public inventory, with the intended scope and validity horizon fixed **before** comparison. Do not build an operational expectation by observing a mismatching package until it passes.

| Field | Expected value |
|---|---|
| `schema` | Literal schema above |
| `purpose` | `operational-candidate` or explicitly guarded `ci-rehearsal` |
| `project` | Operational: `imagine-performance-p6-staging`; CI: exact current `imagine-performance-p7-ci-RUN-ATTEMPT` |
| `valid_until_epoch` | Explicit integer UTC epoch, strictly later than the current clock; no lifetime or rotation default |
| `browser_host` | Approved DNS name or canonical IP address included in the browser leaf SAN |
| `directories` | Object mapping exactly the seven directory keys above to existing absolute canonical paths |
| `browser_ca_file` | Explicit public CA path outside those seven directories |
| `ca_sha256` | Five role keys below, each with an ordered list of lowercase SHA256 fingerprints of the public DER CA certificates |
| `certificates` | Five role keys below, each containing exactly `chain_sha256`, `dns_sans`, `ip_sans` |
| `client_crl_sha256` | Lowercase SHA256 of the one public DER client CRL |
| `identity` | Exactly `issuer`, `key_id`, `expires_at`, `companies`, `operations`; no secret |
| `http_basic_users` | Exact nonempty list of expected public Basic-auth usernames, without password hashes |
| `hba` | Exactly `portal_database` and `performance_database`, each with the expected ordered list of parsed rule token arrays |

The five CA/certificate roles are:

| Role | Certificate and private key | CA | Required leaf SAN / EKU |
|---|---|---|---|
| `portal_database` | `portal-pg/server.pem`, `server.key` | `portal-pki/portal_db_ca.pem` | Exactly DNS `portal-db`; `serverAuth` |
| `performance_database` | `performance-pg/server.pem`, `server.key` | `receiver-private/db-ca.pem` | Exactly DNS `performance-db`; `serverAuth` |
| `service_server` | `receiver-tls/server.pem`, `server.key` | `portal-pki/performance_ca.pem` | Exactly DNS `performance.internal`; `serverAuth` |
| `service_client` | `portal-pki/portal_client.pem`, `portal_client.key` | `receiver-tls/clients-ca.pem` | Explicit expected DNS/IP SAN set; `clientAuth` |
| `browser` | `web-pki/tls_certificate`, `tls_private_key` | `browser_ca_file` | Explicit expected DNS/IP SAN set including `browser_host`; `serverAuth` |

`chain_sha256` is an ordered list, with the leaf first and any supplied intermediates following. Pins cover the complete PEM certificate sequence. The accepted SAN profile contains only canonical DNS names and IP addresses, without wildcard names, duplicate entries or other GeneralName types. SAN sets must match the explicit expectation, and the required EKU must be present by itself. CN fallback or an absent EKU cannot satisfy these checks merely because OpenSSL's purpose/hostname check is permissive.

For example, an individual server role has this shape; the placeholder is deliberately invalid until replaced with a reviewed public fingerprint:

```json
{
  "chain_sha256": ["REVIEWED_LOWERCASE_SHA256_OF_PUBLIC_DER"],
  "dns_sans": ["performance.internal"],
  "ip_sans": []
}
```

`identity.issuer` and `identity.key_id` use the existing 8–96 character ASCII identifier profile. `companies` contains 1–16 distinct canonical nonzero UUIDs; `operations` contains distinct entries from `dashboard.read`, `clients.read`, `reports.read`. Actual identity fields and their list order must equal the expectation. The active keyring contains exactly that issuer and key ID, with the same active flag, integer expiry and secret. Both JSON formats reject additional fields or keys. The expiry must be strictly beyond `valid_until_epoch`. This intentionally narrow package supports one current S2S identity; it does not invent a key-rotation overlap procedure.

`hba` compares ordered parsed records after ignoring comments/blank lines. The supported subset requires at least one `local` and one `hostssl` record, simple database/user identifiers, canonical CIDR networks on `hostssl` records and `scram-sha-256` everywhere. It refuses `host`, `hostnossl`, `trust`, includes, hostname addresses, separate address/netmask fields, quoted lists and authentication options. This conservative parser does not claim to implement every PostgreSQL HBA feature. Restrict the expected database, role and CIDR values for the reviewed package; the CI fixture's broad synthetic rules are not an operational recommendation.

## PKI and credential checks

All CA and leaf fingerprints must match the public expectation. CA certificates must explicitly have `CA:TRUE`; if CA key usage is present, it must permit certificate signing. Leaves cannot have CA privileges. The verifier checks each unencrypted private key internally and compares its derived public key with the leaf. Leaf keys must differ from one another and from the pinned CA public keys. It accepts one PEM PKCS#8, RSA or EC private key per private file, without an interactive passphrase prompt or provider URI.

OpenSSL verifies supplied chains against only the explicit CA bundle, including self-signed root signatures, at **both the current time and the requested horizon**. It checks server hostname/IP and server/client purposes. There is no `partial_chain`, global trust-store fallback, AIA lookup, CRL download, OCSP request or disabled time check. Host OpenSSL behavior and its default security level still apply; this is not evidence that a differently built runtime OpenSSL accepts the package.

For the client certificate, verification uses depth 2 to match the existing Nginx setting and checks CRLs for the **whole chain**. `clients.crl` currently accepts exactly one PEM CRL. The signature, `lastUpdate`, `nextUpdate`, public fingerprint and revocation result must be valid now and through the explicit horizon. A client chain that needs additional issuer CRLs is refused, even if its leaf CRL is valid. Supporting a CRL bundle requires a separate, explicit extension of this profile; a missing CRL is never bypassed. Server-certificate revocation and external browser trust are not independently proved by this package.

The existing synthetic CA lacks a key-usage extension, so the verifier does not introduce global `-x509_strict` or a new algorithm/CA-key-usage issuance policy. It does not approve proposed 30-day lifetimes, 7-day rotation, algorithms or password-hash costs. Those remain explicit security/custody decisions. Chain and purpose validation still enforce declared restrictions and unsupported critical extensions.

The Portal app key must be canonical `base64:` encoding of 32 bytes. SQL password files use 16–4096 printable nonspace ASCII characters; the S2S secret uses the existing minimum of 32 printable characters, bounded at 256. Application key, S2S secret and the five SQL passwords are compared locally to reject equality. These syntax/equality checks do not measure entropy, prove that PostgreSQL has those passwords, or attest exclusive generation/custody.

`access_passwords` must contain exactly the expected usernames and syntactically supported password hashes (bcrypt, APR1/MD5 crypt, SHA256/SHA512 crypt or the Nginx SHA form). Plaintext, extra users and duplicate users are refused. Accepting a file's syntax does not approve a hash algorithm/cost, verify the password or establish that the username is a real authorized person.

## Operational boundaries

Operational mode refuses the CI project, the CI owner-placeholder file, known Phase6/Phase7 synthetic CA/name patterns, `smoke`, example/test names and the two fixed CI company UUIDs. A mode flip cannot promote the existing CI package. Detecting known markers cannot establish that arbitrary renamed material is genuine; independent custody approval remains mandatory.

The offline comparison does not connect to either database. It does not verify SQL role ownership, grants, delegation revision, certificate enrollment or revocation reconciliation. The expected certificate fingerprint, issuer/key/company/operation mapping must still be checked against the owner-maintained SQL authority. No CA or credential reuse across other projects can be disproved by inspecting these seven directories alone.

Nominal administrator creation remains the existing private `portal-admin:bootstrap` command in `app/Console/Commands/BootstrapPortalAdmin.php`: approved staging procedure, TTY password entry and private TOTP enrollment output. This verifier neither creates accounts/TOTP seeds nor turns `tests/Fixtures/phase7/fixture.php` into an installer. It does not apply migrations, provision database roles, perform a live TLS handshake, prove readable in-container mounts, verify backup custody or start a service. Follow `deploy/phase6/RELEASE-GATES.md` and the separate release/source/image preflight for those gates.

## Disposable verification

The existing Phase7 generator remains unchanged and CI-only. Immediately after it produces the materials, the Phase12 exercise derives a clearly labeled synthetic expectation in a new temporary directory, with an explicit five-minute test horizon shorter than the existing 1,800-second identity expiry. It does not extend, replace or correct the generated materials. Expected paths come from `P6_CI_MATERIAL`; full Compose variables and Docker builds are unnecessary at this point.

The integration exercise requires root, `GITHUB_ACTIONS=true`, `ADMIN_PHASE12_MATERIALS_CI=1`, `ADMIN_PHASE7_CI=1`, numeric `GITHUB_RUN_ID`/`GITHUB_RUN_ATTEMPT` and the exact existing `phase7-RUN-ATTEMPT` material directory. Preserve `ADMIN_PHASE8_NAVIGATION_CI` when that fixture supplied the three-operation identity. Only this guarded CI mode accepts the extra `phase7_owner_password` file, and only with the literal inert value `CI_PLACEHOLDER_NOT_AN_OWNER_PASSWORD`.

```sh
sudo --preserve-env=GITHUB_ACTIONS,ADMIN_PHASE12_MATERIALS_CI,ADMIN_PHASE7_CI,GITHUB_RUN_ID,GITHUB_RUN_ATTEMPT,ADMIN_PHASE8_NAVIGATION_CI,P6_CI_MATERIAL \
  python3 -B apps/portal/tests/Fixtures/phase12-materials/test_materials.py

python3 -B apps/portal/tests/Fixtures/phase12-materials/test_crypto.py
```

The first exercise uses the original generated package for its positive comparison and disposable copies for negative cases. It checks file sets/types/links/modes/owners, placeholder isolation, key pairing, public pins, SAN, identity/keyring coherence, explicit scope, duplicate JSON keys, credential reuse, HBA, horizon and CI/operational separation. It emits only a sanitized JSON report and checks that the original mounted materials were not changed. Do not publish its temporary material or expected-manifest directories.

The second exercise runs without root, creates fresh temporary synthetic PKI and invokes real OpenSSL. It covers explicit SAN/EKU, CA/leaf separation, private-key mismatch, CRL signature/revocation/time, chain validity at both instants, metadata changes and diagnostic redaction. Its intermediate-chain case proves why a valid leaf CRL alone is insufficient and why an intermediate cannot silently become the trust anchor. Passing this crypto test does not claim that the privileged seven-directory integration ran on the local machine.

## Primary references

OpenSSL 3.0 documentation: [verification options](https://docs.openssl.org/3.0/man1/openssl-verification-options/), [verify](https://docs.openssl.org/3.0/man1/openssl-verify/), [x509](https://docs.openssl.org/3.0/man1/openssl-x509/), [crl](https://docs.openssl.org/3.0/man1/openssl-crl/), [pkey](https://docs.openssl.org/3.0/man1/openssl-pkey/) and [CA test CRL dates](https://docs.openssl.org/3.0/man1/openssl-ca/). The repository's Nginx configuration is the runtime contract; the [Nginx ssl_crl reference](https://nginx.org/en/docs/http/ngx_http_ssl_module.html#ssl_crl) describes whole-chain CRL requirements.
