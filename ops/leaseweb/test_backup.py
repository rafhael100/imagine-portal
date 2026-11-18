import hashlib
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch

from backup import FILES, MANIFEST, Restic, operate


class FixtureBackend:
    snapshot = "a" * 64

    def __init__(self, source):
        self.source = source
        self.calls = []
        self.tag = "imagine-staging-n8n"
        self.blobs = {}

    def metadata(self, command, *args):
        self.calls.append((command, *args))
        if command == "backup":
            self.blobs = {item.name: item.read_bytes() for item in self.source.iterdir()}
            return json.dumps({"message_type": "summary", "snapshot_id": self.snapshot[:8]})
        if command != "snapshots":
            raise AssertionError(command)
        return json.dumps([{"id": self.snapshot, "paths": [str(self.source)], "tags": [self.tag]}])

    def download(self, snapshot, path, destination, limit):
        assert snapshot == self.snapshot
        self.calls.append(("dump", path.name))
        data = self.blobs[path.name]
        if len(data) > limit:
            raise ValueError("over_limit")
        destination.write_bytes(data)


class BackupTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent)
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        self.source = self.root / "source"
        self.scratch = self.root / "scratch"
        self.source.mkdir()
        self.scratch.mkdir()
        self.backend = FixtureBackend(self.source)
        (self.source / "database.dump").write_bytes(b"PGDMPsynthetic")
        self.write_tar()
        self.write_manifest()
        # The temporary test tree has non-production ancestors.
        ancestors = set(self.root.parents)
        original = Path.lstat

        def owned(path):
            result = original(path)
            if path in ancestors:
                return type("Owner", (), {"st_mode": result.st_mode, "st_uid": os.getuid()})()
            return result

        self.enterContext(patch.object(Path, "lstat", owned))

    def write_tar(self, name="state/config"):
        content = b"synthetic-config"
        with tarfile.open(self.source / "n8n-state.tar", "w") as archive:
            info = tarfile.TarInfo(name)
            info.size = len(content)
            archive.addfile(info, io.BytesIO(content))

    def write_manifest(self):
        members = {}
        for name in FILES:
            data = (self.source / name).read_bytes()
            members[name] = {"bytes": len(data), "sha256": hashlib.sha256(data).hexdigest()}
        (self.source / MANIFEST).write_text(json.dumps(
            {"schema": "imagine-n8n-bundle-v1", "files": members}
        ))

    def operation(self, operation, version, target="n8n"):
        return operate(operation, target, version, self.source, self.scratch,
                       self.backend, os.getuid())

    def test_backup_and_verification(self):
        self.assertEqual(self.operation("backup-run", "daily"), self.backend.snapshot)
        self.assertEqual(self.operation("backup-verify", self.backend.snapshot), self.backend.snapshot)
        self.assertEqual(list(self.scratch.iterdir()), [])
        self.assertEqual(self.backend.calls[0], (
            "backup", "--json", "--tag", "imagine-staging-n8n", "--", str(self.source)))

    def test_restore_uses_private_scratch_and_only_lists_dump(self):
        self.operation("backup-run", "daily")
        with patch("backup.subprocess.run") as run:
            self.operation("restore-test", self.backend.snapshot)
        args = run.call_args.args[0]
        self.assertEqual(args[:2], ["/usr/bin/pg_restore", "--list"])
        self.assertEqual(Path(args[2]).parents[1], self.scratch)
        self.assertEqual(list(self.scratch.iterdir()), [])

    def test_denies_wrong_snapshot_target_before_copy(self):
        self.backend.tag = "imagine-staging-other"
        with self.assertRaisesRegex(ValueError, "scope"):
            self.operation("restore-test", self.backend.snapshot)
        self.assertEqual(len(self.backend.calls), 1)

    def test_rejects_unsafe_tar_before_transfer(self):
        self.write_tar("../unsafe")
        self.write_manifest()
        with self.assertRaisesRegex(ValueError, "unsafe_state_archive"):
            self.operation("backup-run", "daily")
        self.assertEqual(self.backend.calls, [])

    def test_rejects_tampered_restore_and_cleans_scratch(self):
        self.operation("backup-run", "daily")
        self.backend.blobs["database.dump"] = b"PGDMPdifferent"
        with self.assertRaisesRegex(ValueError, "checksum"):
            self.operation("backup-verify", self.backend.snapshot)
        self.assertEqual(list(self.scratch.iterdir()), [])

    def test_rejects_unapproved_profile_or_short_reference(self):
        for operation, version in (("backup-run", "unknown"), ("restore-test", "latest")):
            with self.subTest(operation=operation), self.assertRaises(ValueError):
                self.operation(operation, version)
        self.assertEqual(self.backend.calls, [])

    def test_rejects_non_r2_endpoint_before_reading_credentials(self):
        with self.assertRaisesRegex(ValueError, "r2_eu"):
            Restic({"repository": "s3:https://other.invalid/bucket",
                    "password_file": "/nope", "credentials_file": "/nope"}, os.getuid())

    @unittest.skipUnless(os.environ.get("IMAGINE_TEST_RESTIC"), "local Restic fixture unavailable")
    def test_real_restic_round_trip_with_local_repository(self):
        restic = os.environ["IMAGINE_TEST_RESTIC"]
        password = self.root / "fixture-password"
        password.write_text("synthetic-password\n")
        backend = object.__new__(Restic)
        backend.args = [restic, "--no-cache", "--repo", str(self.root / "fixture-repository"),
                        "--password-file", str(password)]
        backend.env = {"PATH": "/usr/bin:/bin", "LANG": "C"}
        backend.metadata("init")
        self.backend = backend
        snapshot = self.operation("backup-run", "daily")
        self.assertEqual(self.operation("backup-verify", snapshot), snapshot)
        with patch("backup.subprocess.run", wraps=subprocess.run) as run:
            # Keep Restic real and replace only the synthetic PGDMP parser.
            run.side_effect = lambda args, **kwargs: (
                None if args[0] == "/usr/bin/pg_restore" else subprocess_run(args, **kwargs)
            )
            self.operation("restore-test", snapshot)


subprocess_run = subprocess.run
