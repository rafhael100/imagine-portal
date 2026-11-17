# Bound Portal storage recovery, synthetic CI

This continuation preserves the already tested two-database bundle format.
`AuthenticatedStorageBundle` authenticates a separate storage envelope bound to
the SHA256 of `paired.enc` and the same generation, source SHAs and Compose
manifest. Swapping either encrypted database package or manifest refuses storage
recovery. The original paired archive must still pass its own authentication
before any PostgreSQL restore.

## Integration points

Enable `ADMIN_PHASE10_STORAGE_CI=1` only in the exact-package recovery CI. The
fixture has two explicit actions, to be integrated after the two-DB flow passes:

1. `bash apps/portal/tests/Fixtures/phase10-storage/run.sh capture` immediately
   after `paired.enc` has been sealed, while all four original application
   services remain stopped and **before** the restored project is created.
2. `bash apps/portal/tests/Fixtures/phase10-storage/run.sh restore` after the new
   project's PostgreSQL services are healthy, but **before the first**
   `state restore portal fresh-roles` or any other Portal one-shot. Such a
   one-shot would populate the new `portal_storage` from image defaults.

The target is a literally empty volume mounted with `volume-nocopy`. Image-default
directories are not treated as empty, ignored or deleted. If a Portal one-shot
has already populated the volume, recovery refuses it. The root initializer
checks literal emptiness before changing only that new volume root's owner to
UID/GID33. It has CAP_CHOWN only, with no recursive operation or DAC override.
All capture/import logic runs as UID/GID33.

## Identity and private handoff

The source is resolved from the stopped Portal container's actual storage mount
and verified against its current run's Compose project/volume labels. The target
name is derived from the current isolated P8 project, never caller-selected.
Before capture, the target project has no containers or volumes and the target
volume must be absent. A private receipt records that observation and its time.
Later, the target's labels, local driver, creation time, container mounts and
exact identity are checked before import. Literal-empty checks remain mandatory
inside the importer; a boolean or path alone cannot authorize overwriting data.
Docker inspection projects only service state, the two necessary Compose labels,
the storage mount, image ID and volume identity. A boolean rejects custom volume
options without reading their values. Containers are created explicitly and
cleaned up only by their returned ID; a name collision never authorizes removal.

The two-DB key, manifest and paired ciphertext are copied to a private temporary
handoff owned by UID/GID33, with directory mode0700 and input mode0400. This avoids
opening the runner's UID-owned0700 directory to runtime users. No application
runtime receives these backup materials. The existing separate APP_KEY/keyring
mounts retain their original custody; no secret escrow is created by this test.

## Proof and limits

The fixture adds explicit synthetic private-data, log and session-file canaries
before the read-only snapshot. Native sessions remain in PostgreSQL. The snapshot
also includes real generated files present in this disposable Portal volume.
The importer must reproduce every relative path, type, size, digest and mode,
and the source inventory must remain unchanged during capture/import.
A deliberately executable-mode PHP canary is copied as inert data with mode0755,
matching the ordinary permissions Laravel can generate for compiled views; it
must never be evaluated or silently have its mode changed.

Authenticated negative payloads cover traversal, absolute paths, symlink/hardlink
types, duplicate paths, file-parent conflicts, content-digest mismatch and entry
limits. Other cases cover ciphertext/key/generation/database-binding failures,
source symlinks and regular-file hardlinks, and populated destinations. Every
preflight refusal must leave the target inventory unchanged. Only a new volume
created specifically for negative tests is removed by this fixture; the source
and restored volumes remain under their existing P7/P8 cleanup.

Evidence contains counts, source/image identities, aggregate digests and check
names. File contents, file listings, encryption keys, archives and creation
receipts are not uploaded. Size bounds belong to this small synthetic package;
this is not a capacity claim for large operational backups. External custody,
external durability, an immutable antirollback anchor and operational release
approval remain separate gates.
