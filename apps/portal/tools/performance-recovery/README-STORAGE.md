# Bounded Portal storage recovery codec

`AuthenticatedStorageBundle` stores a complete, controlled Portal storage tree
in an authenticated XChaCha20-Poly1305 envelope. It preserves regular file
contents and modes, directory modes, and the root mode. Its public `inventory()`
returns only relative paths, types, sizes, modes and SHA-256 digests; it does not
return file contents.

## Paired recovery contract

The encrypted storage payload binds the exact four-field recovery manifest
(`generation`, `portal_sha`, `engine_sha`, `compose_sha256`) and the SHA-256 of
the exact `AuthenticatedBundle` V1 database ciphertext. The storage codec
checks the V1 format prefix, bounded size and digest; **the caller must
separately authenticate and open the V1 archive before using the recovered
package**. A matching storage envelope does not independently prove that the
database archive is valid, current or authorized for operational restoration.

Public operations are:

```php
AuthenticatedStorageBundle::seal(
    $sourceDir, $keyPath, $manifestPath, $pairedArchivePath, $output
);
AuthenticatedStorageBundle::restore(
    $archive, $keyPath, $expectedManifestPath, $pairedArchivePath, $destination
);
$metadata = AuthenticatedStorageBundle::inventory($directory);
```

All operations require effective UID/GID 33 and the PHP Sodium and POSIX
extensions. The key is exactly 32 bytes in a regular, singly linked file with
mode `0400` or `0600`. Control inputs and output belong in a private handoff
directory outside the source tree. The manifest has exactly the four ordered
fields above, with lowercase hexadecimal values of the required lengths.

## Preconditions and refusal behavior

The caller must stop all application writers, mount the source read-only for
capture, and establish the source volume and recovery generation. The caller
must also prove that the destination is a new, isolated volume. The codec
requires its existing root to be literally empty, writable by UID/GID 33, and
free of links or aliases. It never clears a destination, overwrites existing
files, merges trees, follows a symlink or executes restored content.

Source and target trees use only directories and singly linked regular files
owned by UID/GID 33. Normal permission bits, including ordinary file execute
bits, are preserved. Special bits and group/other write are refused. Files must
be readable by their owner; directories must be readable and traversable by
their owner. Paths must be canonical relative UTF-8 paths without control
characters, backslashes, empty components, `.` or `..`. Devices, sockets, FIFOs,
hardlinks and symlinks are refused.

Restore authenticates the entire storage envelope and validates its binding,
canonical JSON, tree ordering, duplicate paths, parent types, content digests,
base64 encoding, sizes and modes before its first destination mutation. It
then rechecks the empty root and creates each entry exclusively. Directory
modes are applied after descendants have been created. Invalid inputs are
reported through fixed error codes without paths, payloads, secrets or nested
exception messages.

## Deliberate bounds

| Bound | Maximum |
| --- | ---: |
| Regular file content | 2 MiB per file |
| Total regular file content | 8 MiB |
| Files and directories combined | 2,048 entries |
| Relative path | 512 bytes |
| Path components | 32 |
| One path component | 255 bytes |
| Storage envelope | 24 MiB |
| Paired database ciphertext | 96 MiB |

The codec holds plaintext, decoded content and encrypted buffers in memory.
These bounds support the controlled recovery rehearsal; they do not establish
capacity, duration or memory requirements for larger operational backups.

The filesystem operation is not a transaction. A write, sync, permission or
other I/O failure after successful preflight can leave a partial **new**
volume. The caller must leave applications stopped, quarantine that failed
destination, and use another freshly verified empty volume for a retry. The
codec intentionally does not erase partial output. It does not defend against
a concurrent process with write access to the private source, destination or
handoff directories, and it does not preserve ownership variations, ACLs,
extended attributes, timestamps or filesystem-specific metadata.

External backup custody, key escrow, retention, WORM storage, freshness and
revocation checks, release approval and production activation remain outside
this codec. An authenticated old package remains old. See the isolated
`tests/Fixtures/phase10-storage` rehearsal for the volume identity checks and
the coupled database/storage execution order.
