# Compare the inventories before and after an isolated project

`compare-inventory.py` compares two existing `performance.phase6.host-readonly.v2` JSON snapshots against an explicit expected baseline and exact resource-name additions. It closes the before/after comparison required by `RELEASE-GATES.md`; the existing `readonly-inventory.py` remains the collector.

The comparator uses only Python's standard library and reads three explicitly named local files. It does not call Docker, inspect the current host, contact a VPS, reserve a port, start a resource, repair a mismatch or authorize installation. It does not assume that there are eight existing projects. Every preexisting container and network is included, and every preexisting volume name and Compose project is retained, including resources without Compose ownership.

## Result and exact scope

Exit code 0 means `UNCHANGED_EXISTING_INVENTORY_OBSERVED`: the four categories below match the expected comparison. Exit code 1 means either `DIFFERENCES_OBSERVED` or a fixed-code `REFUSED` because the input/expectation contract could not be validated.

| Category | What is preserved | What an addition must satisfy |
|---|---|---|
| Containers | Same ID and every collected field: name, image reference, status, `started_at`, restart count, Compose project label, configured bindings and active bindings | Exact expected name and exact new-project label; no other extra container is ignored |
| Networks | Same ID and every collected field: name, internal flag, subnets, gateways and IPv4 endpoint-address list | Exact expected name in the new project's namespace |
| Named volumes | Every preexisting **name** remains present | Exact expected new name in the new project's namespace |
| Compose projects | Same name and collected status for every preexisting project | Exactly the explicitly expected new project |

A recreated container or network with the same name has a different ID and is refused. An extra network or volume is refused even if its name starts with the permitted project prefix. A container with an expected name but a different or missing project label is refused. Required additions cannot be silently omitted. The target project and its expected new resource names must be absent from the baseline.

The result contains only bounded category/count summaries, fixed difference codes and SHA256 digests of the validated public JSON inputs/expectation. It does not print resource names, IDs, addresses, image references, file paths, raw input or exceptions. Difference categories are `compose_projects`, `containers`, `networks`, `named_volumes`; difference codes are `removed`, `changed`, `unexpected_added`, `expected_missing`, `wrong_project`.

The following flags remain **false on every comparator result**, including success:

- `installation_authorized`, `deployment_authorized`, `human_approval_verified`;
- `input_authenticity_verified`, `continuous_availability_verified`;
- `volume_contents_verified`, `volume_identity_verified`;
- `uncollected_configuration_verified`, `network_volume_ownership_verified`.

The collector records only volume names. A volume deleted and recreated with the same name between captures cannot be distinguished, and no volume-content comparison is performed. Network and volume namespace strings do not authenticate ownership: their ownership labels are not collected. Container image references do not prove immutable image contents or build provenance. Configuration absent from the v2 projection—such as mounts, environment, command, capabilities or uncollected endpoint identity—cannot be compared here.

Equal observed states at two times do not prove continuous availability between them. The input files, host fingerprint and timestamps are not authenticated by this comparator. The host fingerprint is the collector's shortened machine-ID digest; matching it does not establish who captured the data or that it came from the intended authorized host.

CPU count, memory totals/availability and free disk are schema-checked but are **not part of the four-category preservation comparison**. The proposal's port/network conflict observations are also schema-checked and can change when the intended project starts; only the static proposal is bound to the expectation. Success does not imply that a port was free, a conflict is acceptable, capacity is sufficient or the new application is healthy. No freshness duration, resource budget or approval policy is invented.

## Invocation

Use an installed, reviewed script by its absolute path. All three inputs must be existing absolute canonical regular-file paths. Links, hard links, special files and files changed during the comparison are refused. The program never rewrites the inputs.

```sh
python3 -B /approved/portal/apps/portal/deploy/phase6/compare-inventory.py \
  --mode operational-candidate \
  --before /approved/release/inventory-before.json \
  --after /approved/release/inventory-after.json \
  --manifest /approved/release/inventory-expected.json
```

The example paths are placeholders. The supported platform is Linux with Python 3 and ordinary local file access. This command has no capture, shell, Docker-context, override, mutation or automatic fallback option. Run the existing collector separately under the authorized inventory procedure.

## Input contract

Each snapshot must have exactly the fields emitted by the current v2 collector: schema, UTC capture time, host fingerprint, CPU/memory/disk observations, Compose projects, containers, networks, named volumes, the full proposal, `installation_authorized=false` and `status=NO_GO_PENDING_REVIEW_AND_APPROVAL`. Nested resource and conflict objects also have closed field sets. Unknown fields, duplicate JSON keys/resource identities/names, nonfinite JSON numbers, invalid types and incomplete records are refused. Container/network IDs use 64 lowercase hex characters; the observed host fingerprint uses 16.

Snapshots are limited to 4 MiB each and the expectation to 64 KiB. Each of the Compose-project, container and network lists permits at most 1,024 entries; there are at most 4,096 volume names. Per-container binding lists are limited to 256 entries, network subnets/gateways to 128 entries each, network endpoint lists to 4,096 entries and each conflict list to 4,096 entries. These are parser limits, not an infrastructure capacity claim.

The snapshots must have the same expected host fingerprint. Their UTC timestamps must satisfy `before < after`. This is an ordering check, not an attestation or a freshness policy. The static proposal must match the expectation exactly in both captures:

```json
{
  "project": "imagine-performance-p6-staging",
  "port": 18092,
  "subnet": "10.87.6.0/28",
  "receiver_ip": "10.87.6.3",
  "gateway_candidate": "10.87.6.1"
}
```

These are the already proposed example values, not reserved or automatically selected resources. The responsible operator supplies the values from the reviewed captures. The comparator preserves the collector's existing RFC1918/candidate-address/protected-port constraints without choosing alternatives.

## Explicit expected baseline and additions

Expected schema: `imagine.performance.inventory.expected.v1`. It contains exactly these fields:

| Field | Contract |
|---|---|
| `schema` | Literal expected schema above |
| `purpose` | Explicit `operational-candidate` or guarded `ci-rehearsal` |
| `project` | The new project whose additions are permitted; operational literal `imagine-performance-p6-staging` |
| `host_fingerprint` | Exact reviewed 16-hex fingerprint from the baseline |
| `before_sha256` | SHA256 of the complete parsed baseline, encoded by `json.dumps(value, sort_keys=True, ensure_ascii=True, separators=(',', ':')).encode('ascii')` |
| `proposal` | Exactly the five static fields shown above |
| `additions` | Exactly `containers`, `networks`, `named_volumes`, each with an explicit nonempty list of 1–32 distinct names |

Container names must begin with `project + '-'`; network/volume names must begin with `project + '_'`. Those prefix constraints narrow the accepted names but never replace exact list membership. There is no operational manifest generator that learns the additions from the `after` snapshot. Prepare/review the expectation before comparison and preserve its association with the baseline. Changing whitespace or JSON object-key order does not change the canonical baseline digest; changing actual baseline data does.

The standalone comparator does not infer required services from Compose. Exact candidate configuration, source/image correspondence and any approved optional component remain separate release gates. Its result only establishes the stated comparison against the explicit expected name lists.

## CI rehearsal with the existing six-service project

CI mode requires `GITHUB_ACTIONS=true`, `ADMIN_PHASE14_INVENTORY_CI=1`, numeric `GITHUB_RUN_ID` and `GITHUB_RUN_ATTEMPT`. The expected added project must be exactly `imagine-performance-p7-ci-RUN-ATTEMPT`. Changing only a fixture mode cannot promote that project to operational mode.

The collector's `proposal.project` is a fixed P6 literal even when the actual disposable project is P7. The expected manifest deliberately keeps these separate: `proposal.project` identifies the existing resource proposal, while the top-level `project` identifies the P7 additions. The collector does not need an override or a changed schema.

The new `tests/Fixtures/phase8-readiness/exercise_compare_inventory.py` accepts `--before` and `--after`. It builds one temporary, explicitly synthetic expectation from **only the before snapshot, the four CI environment values and fixed Compose names**: six service containers, six networks and four volumes. It never consults the after snapshot to decide what should be allowed. It then invokes the actual comparison CLI in a separate process, checks that the expectation/baseline were not rewritten, removes its temporary expectation and emits a sanitized `performance.phase14.inventory.exercise.v1` report.

Suggested integration points in the existing Phase7 job are:

1. Capture `before` after the cleanup trap and P6 variables are installed, before package config/build/start. Any separate tooling that creates or starts nonproject Docker resources must already be stable; the comparator does not silently exempt such changes.
2. Reuse the existing `ci-host-inventory.json` capture after the Phase7 browser exercise and before navigation/maintenance/recovery. The browser exercise does not restart the six base containers, and its one-shot control containers use `--rm`.
3. Run the fixture against those saved captures and retain its sanitized report as comparison evidence. Existing CI collector snapshots describe the disposable runner; operational snapshots must remain under the responsible operator's private handling.

```sh
python3 -B apps/portal/deploy/phase6/readonly-inventory.py \
  --port "$P6_LOOPBACK_PORT" --subnet "$P6_SERVICE_SUBNET" --receiver-ip "$P6_RECEIVER_IP" \
  > "$P7_EVIDENCE/host-inventory-before.json"

# Existing CI build/start and Phase7 browser steps occur next. Reuse their
# existing collector output: "$P7_EVIDENCE/ci-host-inventory.json".

python3 -B apps/portal/tests/Fixtures/phase8-readiness/exercise_compare_inventory.py \
  --before "$P7_EVIDENCE/host-inventory-before.json" \
  --after "$P7_EVIDENCE/ci-host-inventory.json" \
  > "$P7_EVIDENCE/inventory-comparison.json"
```

The fixture does not capture the host or run Docker itself. A successful CI comparison is evidence about the disposable runner workload; it is not a VPS inventory or permission to install. Its fixed expectations cover the base six-service fixture and will refuse additional resources until the test expectation is explicitly reviewed and changed.

## Verification

```sh
python3 -B apps/portal/tests/Fixtures/phase8-readiness/test_compare_inventory.py
```

The tests call the real v2 collector with injected synthetic Docker/IP/listener observations; they never inspect the local Docker host. The resulting real collector projection is passed to the actual comparator and, for integration, through the fixture and CLI subprocess. Negative cases cover container recreation, every observed preexisting-container field, unmanaged extras, wrong project labels, missing additions, network recreation/endpoints, extra names inside the allowed namespace, removed volume names/projects, host/baseline/proposal/time mismatches, malformed/duplicate/nonfinite input, file limits/links and sanitized diagnostic output. Resource consumption changes are explicitly tested as outside the preservation decision.
