# Exact-package recovery, synthetic CI

This opt-in continuation runs after the unchanged Phase7 checks and the Phase8
navigation checks. Both source and restored stacks use
`deploy/phase6/compose.yaml` without an overlay. It does not authorize a VPS
operation, deployment, customer-data restore, older-image rollback, external
backup destination, key rotation, billing, or a new operational policy.

The exercise keeps the browser authenticated while restarting the original six
containers. The same database session store, encrypted session payloads, APP_KEY,
TOTP credentials, service identity, keyring and client certificate remain in use.
It then stops all four application services before taking custom-format dumps of
both databases. The paired archive authenticates the generation, Portal/engine
source SHAs, Compose digest, database filenames and dump digests together using
XChaCha20-Poly1305. Tampering, a wrong key, a different generation and an existing
plaintext destination are refused before any restore command.

The codec is bounded to 32 MiB per dump and keeps the encoded pair in memory.
These limits are refusal thresholds, not measured capacity: the complete
seal/open operation can require several times the input size. This CI uses small
synthetic databases and does not qualify the codec for large operational backups.

The restore creates a separate Compose project, new PostgreSQL volumes and new
cluster identifiers. It refuses nonempty targets. All table-content digests,
sequences, database/schema/table/column grants, role memberships, RLS policies and
function privileges are compared before any restored application starts.
PostgreSQL NULL ACLs are expanded to their object-specific defaults for this
comparison; an explicit empty ACL remains distinct. This accommodates pg_dump's
omission of redundant default ACLs without ignoring any effective privilege.
Isolated transactions must still detect a new column grant, a new runtime-role
membership and an explicit empty table ACL, then roll back to the baseline.

After the backup, a new live read creates additional durable receipts, nonces and
audits. The live nominal alpha administrator's session epoch and grants, and
the receiver's alpha delegations, are then revoked. A CURRENT checkpoint is
captured directly from the quiesced source databases **outside the archive**.
Both stale restored authorities must fail their CURRENT gate while the restored
application services remain stopped.

Reconciliation is a deliberately narrow CI fixture: it may increase session/TOTP
counters, copy observed revocations and revisions, and insert missing append-only
records. It cannot change a password, TOTP secret, principal scope or role, add an
identity, re-enable a revoked grant, delete an audit or overwrite a receipt/nonce.
Conflicts refuse recovery. Both CURRENT gates must then pass before the four
restored applications start. The previous alpha cookie must be rejected, while
the beta database session remains usable. The runtime receiver role must reject
the revoked delegation and a duplicate persisted nonce. That nonce probe tests
the database uniqueness barrier; it is not described as a newly signed HTTP
envelope replay test.

Only sanitized counts, source identities and check names are uploaded. Keys,
dumps, decrypted files, CURRENT checkpoints, browser cookies and synthetic vaults
remain temporary private material and are removed with the isolated project.
The original database contents are compared again to prove the restore did not
write to them.

With `ADMIN_PHASE10_STORAGE_CI=1`, the same quiesced generation also captures
the bounded `portal_storage` tree, authenticates its metadata and content, and
binds it to the exact paired database ciphertext. The storage restore runs into
a new, literally empty named volume before the first Portal one-shot mounts it.
The Phase10 fixture checks the restored digests and modes and rejects malformed
trees and occupied targets without overwriting them; see its README for the
size, ownership and offline-writer limits. It is not an arbitrary-volume import.

This establishes recovery properties for this exact synthetic CI package. It
does not establish external custody, a WORM/monotonic antirollback anchor,
cross-site durability, or capacity for large operational archives. The already
available secret mounts are reused under isolated CI control; operational
secret escrow and external backup recovery remain separate release gates. A
sealed old snapshot alone never authorizes current access.

A recovery-browser failure of the restored beta session reports only an
allowlisted HTTP status, route class and heading visibility under
`PHASE8_RECOVERY_SESSION_DIAGNOSTIC`. The browser assertion is unchanged:
redirects to login, a missing heading or any non-200 result still fail the run.
The offline classifier has negative tests for injected paths and statuses;
no URL, cookie, response body, token or identity appears in CI logs. This is
diagnostic instrumentation, **not** a fix or proof that the historical
intermittence has ended (Portal issue #997).
