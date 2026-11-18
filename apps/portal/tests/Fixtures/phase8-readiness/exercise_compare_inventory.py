#!/usr/bin/env python3
"""CI-only comparison of two actual collector snapshots, with fixed Compose names."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

sys.dont_write_bytecode = True
MODULE = Path(__file__).resolve().parents[3] / "deploy/phase6/compare-inventory.py"
spec = importlib.util.spec_from_file_location("compare_inventory", MODULE)
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)

SERVICES = ("portal", "portal-web", "portal-db", "receiver", "performance-web", "performance-db")
NETWORKS = ("ingress", "portal_front", "portal_storage", "performance_front", "performance_storage", "service_link")
VOLUMES = ("portal_storage", "portal_bootstrap", "portal_pg", "performance_pg")
ENVIRONMENT = ("GITHUB_ACTIONS", "ADMIN_PHASE14_INVENTORY_CI", "GITHUB_RUN_ID", "GITHUB_RUN_ATTEMPT")


def expected_from_before(before, environ):
    """Expectation observes no 'after' field and never learns unexpected additions."""
    p.validate_snapshot(before)
    project = "imagine-performance-p7-ci-" + environ.get("GITHUB_RUN_ID", "") + "-" + environ.get("GITHUB_RUN_ATTEMPT", "")
    expected = {"schema": p.EXPECTED_SCHEMA, "purpose": "ci-rehearsal", "project": project,
                "host_fingerprint": before["host_fingerprint"], "before_sha256": p.digest(before),
                "proposal": {key: before["proposal"][key] for key in p.PROPOSAL_FIELDS},
                "additions": {"containers": [project + "-" + service + "-1" for service in SERVICES],
                              "networks": [project + "_" + name for name in NETWORKS],
                              "named_volumes": [project + "_" + name for name in VOLUMES]}}
    p.validate_manifest(expected, "ci-rehearsal", environ)
    return expected


def exercise(before_path, after_path):
    before, before_record = p.read_file(before_path, p.MAX_SNAPSHOT)
    expected = expected_from_before(before, os.environ)
    environment = {key: os.environ.get(key, "") for key in ENVIRONMENT}
    environment.update(PATH="/usr/bin:/bin", LC_ALL="C", PYTHONDONTWRITEBYTECODE="1")
    with tempfile.TemporaryDirectory(prefix="phase14-inventory-ci-") as directory:
        manifest = Path(directory) / "expected.json"
        original = p.canonical(expected)
        manifest.write_bytes(original)
        manifest.chmod(0o600)
        completed = subprocess.run([sys.executable, "-B", str(MODULE), "--mode", "ci-rehearsal", "--before", before_path,
                                   "--after", after_path, "--manifest", str(manifest)], stdin=subprocess.DEVNULL,
                                  stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=20, env=environment)
        p.need(len(completed.stdout) <= 64 * 1024, "input_limit")
        result = p.parse_json(completed.stdout)
        p.need(isinstance(result, dict) and result.get("schema") == p.RESULT_SCHEMA
               and result.get("mode") == "ci-rehearsal", "schema")
        p.need(manifest.read_bytes() == original, "input_changed")
        p.unchanged_inputs((before_record,))
        # The subprocess is the checked-in comparator, not a supplied command.
        # Copy only its bounded, sanitized public result fields.
        selected = {key: result[key] for key in ("status", "counts", "differences", "before_sha256", "after_sha256",
                                                "expected_manifest_sha256", "reason") if key in result}
        report = {"schema": "performance.phase14.inventory.exercise.v1", "source": "synthetic",
                  "comparison": selected, "expected_manifest_unchanged": True,
                  "installation_authorized": False, "deployment_authorized": False, "input_authenticity_verified": False,
                  "continuous_availability_verified": False, "volume_contents_verified": False, "volume_identity_verified": False,
                  "uncollected_configuration_verified": False, "network_volume_ownership_verified": False, "vps_modified": False}
        passed = completed.returncode == 0 and result.get("status") == "UNCHANGED_EXISTING_INVENTORY_OBSERVED"
        report["status"] = "INVENTORY_COMPARISON_EXERCISE_PASS" if passed else "INVENTORY_COMPARISON_EXERCISE_REFUSED"
        return report, 0 if passed else 1


def main(argv=None):
    try:
        parser = p.Parser(description=__doc__, allow_abbrev=False)
        parser.add_argument("--before", required=True)
        parser.add_argument("--after", required=True)
        args = parser.parse_args(argv)
        report, code = exercise(args.before, args.after)
    except Exception as error:
        report = {"schema": "performance.phase14.inventory.exercise.v1", "source": "synthetic",
                  "status": "INVENTORY_COMPARISON_EXERCISE_REFUSED", "installation_authorized": False,
                  "deployment_authorized": False, "vps_modified": False,
                  "reason": str(error) if isinstance(error, p.Refused) and str(error) in p.ERRORS else "input"}
        code = 1
    print(json.dumps(report, sort_keys=True, separators=(",", ":")))
    return code


if __name__ == "__main__":
    sys.exit(main())
