"""Negative security experiments for the *non-operational* Modelo C lab.

These assertions only exercise synthetic SQLite state in TemporaryDirectory.
They are not full SEC-R03-X01..X24 product acceptance tests.
"""
from __future__ import annotations

from pathlib import Path
import os
import shutil
import sqlite3
import tempfile
import threading
import unittest

from hybrid_model import Conflict, Denied, HybridLab, MANIFEST, digest


class HybridModelCTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix="portal-r03-c01-")
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.lab = HybridLab.provision(self.root / "model")
        self.backup = self.root / "pre-revocation.sqlite"

    def recover(self, partial=False):
        sha = self.lab.snapshot(self.backup)
        self.lab.revoke("actor-a", "org-a")
        current_seq = self.lab.state()[1]
        generation = self.lab.begin_recovery(current_seq, sha)
        self.lab.restore(self.backup)
        if partial:
            with self.assertRaises(Denied):
                self.lab.reconcile(generation, partial=True)
        return generation

    def complete_recovery(self):
        generation = self.recover()
        self.lab.reconcile(generation)
        seq = self.lab.state()[1]
        self.lab.open_for_lab(seq, approval_fixture=True)
        return generation

    def test_stale_backup_cannot_serve_before_reconciliation_and_after_global_reset(self):
        # Before the disaster, the laboratory actor is active.
        self.assertTrue(self.lab.attempt("actor-a", "org-a", 1, 1))
        generation = self.recover()
        self.assertTrue(self.lab.member_active("actor-a", "org-a"))  # old grant restored
        self.assertEqual("DENY", self.lab.state()[2])
        self.assertEqual([], self.lab.effects())
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", 1, 1, nonce="bad-after-restore")
        with self.assertRaises(Denied):
            self.lab.attempt("actor-b", "org-b", generation, 2, nonce="bad-before-ready")
        self.assertEqual([], self.lab.effects())

        seq = self.lab.reconcile(generation)
        self.assertEqual("READY", self.lab.state()[2])
        self.assertFalse(self.lab.member_active("actor-a", "org-a"))
        self.assertTrue(self.lab.member_active("actor-b", "org-b"))
        with self.assertRaises(Denied):
            self.lab.attempt("actor-b", "org-b", generation, 2, nonce="bad-ready")
        with self.assertRaises(Denied):
            self.lab.open_for_lab(seq, approval_fixture=False)
        self.lab.open_for_lab(seq, approval_fixture=True)
        self.assertEqual("OPEN", self.lab.state()[2])

        # A global session reset denies B's old session, too.
        with self.assertRaises(Denied):
            self.lab.attempt("actor-b", "org-b", generation, 1, nonce="bad-old-session")
        with self.assertRaises(Denied):
            self.lab.attempt("actor-b", "org-b", generation, 2, nonce="bad-no-new-session")
        with self.assertRaises(Denied):
            self.lab.fixture_reauthenticate("actor-a")

        # This method is a TEST FIXTURE, not a real reauthentication mechanism.
        self.lab.fixture_reauthenticate("actor-b")
        self.assertTrue(self.lab.attempt("actor-b", "org-b", generation, 2,
                                         nonce="allowed-b-after-fixture"))
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", generation, 2, nonce="never-a")
        self.assertEqual(
            [("allowed-b-after-fixture", "actor-b", "org-b", "portal-http")],
            self.lab.effects(),
        )

    def test_restore_cannot_rewind_z4_generation_or_revocations(self):
        generation = self.recover()
        gen, seq, mode, epoch, reconciled, sha, manifest = self.lab.state()
        self.assertEqual((2, "DENY", 2, 0, digest(self.backup), MANIFEST),
                         (gen, mode, epoch, reconciled, sha, manifest))
        self.assertGreater(seq, 1)
        # Repeating the Z3 restore leaves the separate authority unchanged.
        self.lab.restore(self.backup)
        self.assertEqual((gen, seq, mode, epoch, reconciled, sha, manifest), self.lab.state())
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", 1, 1)
        self.assertEqual([], self.lab.effects())

    def test_z4_unavailable_after_open_denies_all_writers_and_readers(self):
        offline = self.lab.z4.with_suffix(".offline")
        os.replace(self.lab.z4, offline)
        try:
            with self.assertRaises(Denied):
                self.lab.attempt("actor-a", "org-a", 1, 1, nonce="z4-missing")
            with self.assertRaises(Denied):
                self.lab.state()
            self.assertEqual([], self.lab.effects())
        finally:
            os.replace(offline, self.lab.z4)
        self.assertTrue(self.lab.attempt("actor-a", "org-a", 1, 1))

    def test_z4_unavailable_after_reopen_denies_even_new_fixture_session(self):
        generation = self.complete_recovery()
        self.lab.fixture_reauthenticate("actor-b")
        offline = self.lab.z4.with_suffix(".offline")
        os.replace(self.lab.z4, offline)
        try:
            with self.assertRaises(Denied):
                self.lab.attempt("actor-b", "org-b", generation, 2, nonce="z4-offline")
        finally:
            os.replace(offline, self.lab.z4)
        self.assertEqual([], self.lab.effects())

    def test_two_recovery_coordinators_cannot_commit_same_stale_cas(self):
        sha = self.lab.snapshot(self.backup)
        initial_seq = self.lab.state()[1]
        errors, successes = [], []

        def begin():
            try:
                successes.append(self.lab.begin_recovery(initial_seq, sha))
            except (Conflict, Denied) as exc:
                errors.append(type(exc).__name__)

        threads = [threading.Thread(target=begin) for _ in range(2)]
        for t in threads:
            t.start()
        for t in threads:
            t.join(timeout=5)
            self.assertFalse(t.is_alive())
        self.assertEqual([2], successes)
        self.assertEqual(1, len(errors))
        self.assertEqual("DENY", self.lab.state()[2])

    def test_repeated_or_stale_open_attempts_do_not_override_fence(self):
        generation = self.recover()
        self.lab.reconcile(generation)
        before = self.lab.state()[1]
        self.lab.open_for_lab(before, approval_fixture=True)
        with self.assertRaises(Conflict):
            self.lab.open_for_lab(before, approval_fixture=True)
        with self.assertRaises(Denied):
            self.lab.begin_recovery(before, digest(self.backup))
        self.assertEqual("OPEN", self.lab.state()[2])

    def test_wrong_snapshot_digest_fails_closed_without_restoring_state(self):
        original = self.lab.snapshot(self.backup)
        wrong = self.root / "tampered.sqlite"
        shutil.copyfile(self.backup, wrong)
        wrong.write_bytes(wrong.read_bytes() + b"tampering")
        generation = self.lab.begin_recovery(self.lab.state()[1], original)
        with self.assertRaises(Denied):
            self.lab.restore(wrong)
        self.assertEqual("DENY", self.lab.state()[2])
        self.assertEqual([], self.lab.effects())
        # No Z4 restore receipt exists: reconciliation is explicitly forbidden.
        with self.assertRaises(Denied):
            self.lab.reconcile(generation)

    def test_interrupted_reconciliation_stays_closed_until_new_verification(self):
        generation = self.recover(partial=True)
        self.assertEqual("DENY", self.lab.state()[2])
        with self.assertRaises(Denied):
            self.lab.open_for_lab(self.lab.state()[1], approval_fixture=True)
        self.assertEqual([], self.lab.effects())
        seq = self.lab.reconcile(generation)
        self.lab.open_for_lab(seq, approval_fixture=True)
        self.assertEqual("OPEN", self.lab.state()[2])

    def test_cross_tenant_claims_and_unknown_writer_are_denied_without_effect(self):
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-b", 1, 1, nonce="cross-tenant")
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", 1, 1, writer="unlisted-cron",
                             nonce="unknown-writer")
        self.assertEqual([], self.lab.effects())

    def test_revocation_and_inflight_writer_are_serialized_only_inside_model(self):
        entered = threading.Event()
        release = threading.Event()
        revoked = threading.Event()
        failures = []
        ordered = []

        def hold():
            entered.set()
            if not release.wait(timeout=3):
                raise AssertionError("Synthetic writer wait timed out.")

        def writer():
            try:
                self.lab.attempt("actor-a", "org-a", 1, 1, nonce="before-revoke",
                                 pause_before_effect=hold)
                ordered.append("writer-committed")
            except Exception as exc:
                failures.append(repr(exc))

        def revoker():
            try:
                self.lab.revoke("actor-a", "org-a")
                ordered.append("revoked")
            except Exception as exc:
                failures.append(repr(exc))
            finally:
                revoked.set()

        a = threading.Thread(target=writer)
        a.start()
        self.assertTrue(entered.wait(timeout=3))
        b = threading.Thread(target=revoker)
        b.start()
        self.assertFalse(revoked.wait(timeout=0.1),
                         "Revocation bypassed the simulated commit barrier.")
        release.set()
        a.join(timeout=5)
        b.join(timeout=5)
        self.assertFalse(a.is_alive())
        self.assertFalse(b.is_alive())
        self.assertEqual([], failures)
        self.assertEqual(["writer-committed", "revoked"], ordered)
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", 1, 1, nonce="after-revoke")
        self.assertEqual([("before-revoke", "actor-a", "org-a", "portal-http")],
                         self.lab.effects())

    def test_stale_worker_generation_and_old_session_cannot_commit(self):
        generation = self.complete_recovery()
        self.lab.fixture_reauthenticate("actor-b")
        with self.assertRaises(Denied):
            self.lab.attempt("actor-b", "org-b", 1, 1, writer="portal-worker",
                             nonce="stale-worker")
        self.assertTrue(self.lab.attempt("actor-b", "org-b", generation, 2,
                                         writer="portal-worker", nonce="fresh-worker"))
        self.assertEqual(1, len(self.lab.effects()))

    def test_synthetic_idempotency_key_does_not_create_two_db_effects(self):
        self.assertTrue(self.lab.attempt("actor-a", "org-a", 1, 1, nonce="mock-effect"))
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", 1, 1, nonce="mock-effect")
        self.assertEqual(1, len(self.lab.effects()))

    def test_corrupt_z4_authority_schema_fails_closed(self):
        db = sqlite3.connect(self.lab.z4)
        try:
            db.execute("DROP TABLE fence")
            db.commit()
        finally:
            db.close()
        with self.assertRaises(Denied):
            self.lab.attempt("actor-a", "org-a", 1, 1, nonce="corrupt")
        self.assertEqual([], self.lab.effects())


if __name__ == "__main__":
    unittest.main()
