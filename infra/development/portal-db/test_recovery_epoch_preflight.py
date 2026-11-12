"""F355: adverse cases for offline witness comparison, CI-only and no real data."""
from __future__ import annotations

import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

import recovery_epoch_preflight as gate

SNAPSHOT = {
    "organization": "org-a",
    "instance": "synthetic-ci-1",
    "generation": 5,
    "session_revision": 3,
    "membership_revision": 7,
    "witnessed_sequence": 12,
}
WITNESS = {
    "organization": "org-a",
    "instance": "synthetic-ci-1",
    "generation_floor": 5,
    "session_floor": 3,
    "membership_floor": 7,
    "sequence": 12,
}


class RecoveryPreflightTests(unittest.TestCase):
    def setUp(self):
        self.workspace = tempfile.TemporaryDirectory()
        self.addCleanup(self.workspace.cleanup)
        self.root = pathlib.Path(self.workspace.name)
        self.snapshot_path = self.root / "snapshot.json"
        self.witness_path = self.root / "witness.json"
        self.write(self.snapshot_path, SNAPSHOT)
        self.write(self.witness_path, WITNESS)

    @staticmethod
    def write(path, payload):
        path.write_text(json.dumps(payload), encoding="utf-8")
        path.chmod(0o600)

    def assert_blocked(self, snapshot=None, witness=None):
        with self.assertRaises(gate.RecoveryBlocked):
            gate.evaluate(
                SNAPSHOT if snapshot is None else snapshot,
                WITNESS if witness is None else witness,
            )

    def test_equal_epoch_is_never_serving_authorization(self):
        self.assertEqual("LAB_CONSISTENT_STILL_NO_SERVING",
                         gate.inspect(self.snapshot_path, self.witness_path))
        with patch.dict(os.environ, {
            "GITHUB_ACTIONS": "true",
            "PORTAL_DB_DISPOSABLE_CI": "1",
            "RUNNER_ENVIRONMENT": "github-hosted",
        }):
            self.assertEqual(0, gate.main([
                "--snapshot", str(self.snapshot_path),
                "--witness", str(self.witness_path),
            ]))

    def test_restored_session_epoch_is_older_than_independent_witness(self):
        self.assert_blocked(snapshot={**SNAPSHOT, "session_revision": 2})

    def test_restored_membership_epoch_is_older(self):
        self.assert_blocked(snapshot={**SNAPSHOT, "membership_revision": 6})

    def test_restored_generation_is_older(self):
        self.assert_blocked(snapshot={**SNAPSHOT, "generation": 4})

    def test_witness_sequence_advanced_after_backup(self):
        self.assert_blocked(snapshot={**SNAPSHOT, "witnessed_sequence": 11})

    def test_external_witness_lag_cannot_be_accepted(self):
        self.assert_blocked(witness={**WITNESS, "sequence": 11})

    def test_cross_tenant_or_cross_incarnation_evidence_is_blocked(self):
        self.assert_blocked(witness={**WITNESS, "organization": "org-b"})
        self.assert_blocked(witness={**WITNESS, "instance": "synthetic-ci-2"})

    def test_missing_or_extra_fields_are_blocked(self):
        self.assert_blocked(snapshot={key: value for key, value in SNAPSHOT.items()
                                      if key != "membership_revision"})
        self.assert_blocked(witness={**WITNESS, "allow_runtime": True})

    def test_invalid_revision_types_or_overflows_are_blocked(self):
        for value in (True, False, 0, -1, 1.0, "12", 1_000_000_001):
            self.assert_blocked(snapshot={**SNAPSHOT, "session_revision": value})

    def test_dangerous_scope_names_are_blocked(self):
        self.assert_blocked(snapshot={**SNAPSHOT, "organization": "../org-a"})
        self.assert_blocked(witness={**WITNESS, "instance": "synthetic/ci"})

    def test_missing_witness_fails_closed(self):
        self.witness_path.unlink()
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.witness_path)

    def test_same_path_or_hardlink_is_never_independent(self):
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.snapshot_path)
        self.witness_path.unlink()
        os.link(self.snapshot_path, self.witness_path)
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.witness_path)

    def test_symlink_witness_is_rejected(self):
        self.witness_path.unlink()
        self.witness_path.symlink_to(self.snapshot_path)
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.witness_path)

    def test_world_readable_witness_is_rejected(self):
        self.witness_path.chmod(0o644)
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.witness_path)

    def test_oversized_or_malformed_json_is_rejected(self):
        self.witness_path.write_text("X" * (gate.LIMIT + 1), encoding="utf-8")
        self.witness_path.chmod(0o600)
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.witness_path)
        self.witness_path.write_text('{"organization":NaN}', encoding="utf-8")
        with self.assertRaises(gate.RecoveryBlocked):
            gate.inspect(self.snapshot_path, self.witness_path)

    def test_cli_refuses_outside_disposable_ci(self):
        with patch.dict(os.environ, {"GITHUB_ACTIONS": "false"}):
            self.assertEqual(64, gate.main([
                "--snapshot", str(self.snapshot_path),
                "--witness", str(self.witness_path),
            ]))

    def test_cli_blocked_after_snapshot_rollback(self):
        self.write(self.snapshot_path, {**SNAPSHOT, "witnessed_sequence": 11})
        with patch.dict(os.environ, {
            "GITHUB_ACTIONS": "true",
            "PORTAL_DB_DISPOSABLE_CI": "1",
            "RUNNER_ENVIRONMENT": "github-hosted",
        }):
            self.assertEqual(65, gate.main([
                "--snapshot", str(self.snapshot_path),
                "--witness", str(self.witness_path),
            ]))


if __name__ == "__main__":
    unittest.main()
