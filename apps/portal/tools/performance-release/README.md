# Explicit candidate preflight

`preflight.py` compares an expected manifest supplied by the caller with fresh,
read-only observations of local source checkouts, the fixed six-service Phase6
Compose configuration and already available Docker images. It does not create
or approve the expected manifest, publish an image, contact a registry, pull,
build, start a container, apply SQL or install the project.

Use the explicit CLI with absolute canonical local paths:

```sh
python3 /absolute/portal/apps/portal/tools/performance-release/preflight.py \
  --mode operational-candidate \
  --manifest /private/reviewed-candidate.json \
  --portal-root /absolute/portal \
  --engine-root /absolute/engine
```

The caller provides the intended `P6_*` environment values. The verifier invokes
only the fixed Compose file with the manifest's project name and
`--env-file /dev/null`; it does not silently load a checkout's `.env` file.
Configuration is captured in memory and is not printed or saved. The expected
manifest must already fix its full canonical JSON SHA-256, including resource
limits, flags, mounts and image references. A different private material path
therefore requires a new reviewed manifest; the verifier never learns or
accepts a new expected value from a mismatch.

## Manifest v1

The JSON object has exactly these fields; duplicate JSON keys are refused:

| Field | Expected value |
| --- | --- |
| `schema` | `imagine.performance.release.candidate.v1` |
| `purpose` | `operational-candidate` or the separate `ci-rehearsal` mode |
| `portal_sha`, `engine_sha` | Exact lowercase 40-character Git SHAs |
| `project` | `imagine-performance-p6-staging` in operational mode |
| `files` | Exactly `portal` and `engine` objects mapping each required relative path to its SHA-256 |
| `rendered_compose_sha256` | SHA-256 of the complete rendered Compose object encoded with UTF-8, sorted object keys, no insignificant whitespace and unescaped non-ASCII characters |
| `images` | Exactly the six service names, each with exactly `reference` and `image_id` |

The required file set is fixed deployment/SQL/dependency configuration plus all
Portal migration PHP files present in the checkout. The verifier requires the
exact set, tracked files and matching contents. It verifies each repository
root and HEAD and refuses tracked changes or nonignored untracked files under
`apps/portal` and the engine's `src`, `deploy/private-admin` and
`contracts/admin/phase2` scopes. An engine checkout nested at `portal/engine` is
outside the Portal scope. CI must set `PYTHONDONTWRITEBYTECODE=1` before the
inventory and preflight fixtures so incidental Python bytecode is not added to
the deployable source directory. Ignored build/runtime artifacts do not become
proof of source provenance; image provenance remains an external release gate.

In operational mode every image reference must be `repository@sha256:...`, and
the exact reference must occur in that local image's `RepoDigests`. The local
configuration image ID must separately match `image_id`. These values are
different identities: a local `sha256:...` image ID alone is not a distributable
registry digest. The comparison does not establish that an image was built from
the stated Git revision or that an external reviewer approved the association.

The command requires Docker's `default` context with endpoint exactly
`unix:///var/run/docker.sock`. Remote Docker environment/context/TLS overrides
are refused. It queries images only with the minimal ID/RepoDigests projection;
it also reads the image's declared user solely to verify Portal web's inherited
`101:101` identity. It does not inspect environment variables, credentials or
arbitrary labels.
Subprocesses use fixed argument arrays, no shell or stdin, bounded output and
timeouts. File inputs are size-bounded, canonical, regular files checked for
changes during reads. Diagnostics contain fixed codes rather than private
paths, configuration, command stderr or nested exception messages.

The structural checks also require the exact services, private networks and
named volumes, fixed receiver/Portal Nginx binds, declared service users,
capability restrictions and the loopback-only origin. Configured PostgreSQL
UID 999 is checked as a Compose declaration; this does not replace verification
of the image's actual account and volume ownership during the runtime rehearsal
or installation gate.

## Result and limits

Success is `CANDIDATE_MATCH_OBSERVED`, with `deployment_authorized=false`.
`expected_manifest_sha256` hashes the parsed manifest encoded as canonical JSON
using the same rules above; it can differ from a hash of the input file's bytes
when that file contains indentation or a different object-key order.
`human_approval_verified`, `build_provenance_verified`,
`registry_release_published` and `host_readiness_verified` remain false. The
command reports correspondence with the supplied expected candidate, not a
release decision, host inventory, image publication or distributed atomic
snapshot. Source/configuration are rechecked after image inspection, but
another actor can change the environment after the command finishes.

Host resources, live port/network conflicts, PKI/certificate custody, nominal
administrators, private ingress, external backup custody and restore receipts,
monitoring and scoped start approval remain separate gates in
`deploy/phase6/RELEASE-GATES.md`. This is an explicit tool; it is not attached to
startup or an automatic deployment path. A real pre-start use requires images
to have been made available through an independently authorized process.

The separate `ci-rehearsal` mode requires `GITHUB_ACTIONS=true`,
`ADMIN_PHASE11_RELEASE_CI=1` and the current run/attempt-specific Phase7 project.
It permits local CI tags so the already materialized test images can exercise
the verifier, always labels them `local_ci_rehearsal` and never turns them into
an operational release. Only the isolated fixture creates a synthetic expected
manifest, once, before calling the real verifier. It does not simulate human
approval.
