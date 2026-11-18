import json
import os
from pathlib import Path
from types import SimpleNamespace
import tempfile
import time
import unittest
from unittest.mock import patch

from executor import approved, dispatch


class ExecutorTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(dir=Path(__file__).resolve().parent)
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for folder in ("approved", "state", "handlers"):
            (self.root / folder).mkdir(mode=0o700)
        self.now = int(time.time())
        self.digest = "sha256:" + "a" * 64
        self.record = dict(request="r1", operation="install-n8n-staging",
                           target="n8n", version=self.digest, task="task-1",
                           initiator="juliana", approver="owner", expires=self.now + 60,
                           environment="staging")
        self.policy = {"approvers": ["owner"], "targets": {"n8n": {
            "install-n8n-staging": [self.digest], "status": ["current"],
            "backup-run": ["daily"],
        }}}
        self.calls = []

    def verify(self, **changes):
        return approved(self.record | changes, self.policy, "r1", self.now)

    def write(self):
        (self.root / "approved/r1.json").write_text(json.dumps(self.record))
        (self.root / "policy.json").write_text(json.dumps(self.policy))
        handler = self.root / "handlers" / self.record["operation"]
        handler.touch(mode=0o700)

    def run_dispatch(self, rc=0):
        def run(args, **options):
            self.calls.append((args, options))
            return SimpleNamespace(returncode=rc)
        original = Path.lstat
        ancestors = set(self.root.parents)

        def fixture_stat(path):
            info = original(path)
            if path in ancestors:
                return SimpleNamespace(st_mode=info.st_mode, st_uid=os.getuid())
            return info

        with patch.object(Path, "lstat", fixture_stat):
            return dispatch("r1", self.root, self.root / "state",
                            self.root / "handlers", os.getuid(), run)

    def test_policy(self):
        self.assertEqual(self.verify(), ("install-n8n-staging", "n8n", self.digest))

    def test_denials(self):
        for changes in (
            {"operation": "shell"}, {"target": "other"}, {"environment": "production"},
            {"version": "latest"}, {"version": "sha256:" + "b" * 64},
            {"approver": "juliana"}, {"expires": self.now}, {"request": "r2"},
            {"task": "../file"}, {"extra": "docker.sock"}, {"target": "n8n;id"},
        ):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                self.verify(**changes)

    def test_approved_dispatch_and_replay(self):
        self.write()
        self.assertTrue(self.run_dispatch())
        self.assertEqual(self.calls[0][0][1:], ["n8n", self.digest])
        self.assertNotIn("DOCKER_HOST", self.calls[0][1]["env"])
        with self.assertRaises(FileExistsError):
            self.run_dispatch()
        self.assertEqual(len(self.calls), 1)

    def test_status_can_be_read_repeatedly(self):
        self.record.update(operation="status", version="current")
        self.write()
        self.assertTrue(self.run_dispatch())
        self.assertTrue(self.run_dispatch())

    def test_backup_failure_requires_new_authorization(self):
        self.record.update(operation="backup-run", version="daily")
        self.write()
        self.assertFalse(self.run_dispatch(rc=1))
        with self.assertRaises(FileExistsError):
            self.run_dispatch()

    def test_missing_approval(self):
        with self.assertRaises(FileNotFoundError):
            self.run_dispatch()

    def test_writable_policy(self):
        self.write()
        (self.root / "policy.json").chmod(0o666)
        with self.assertRaises(ValueError):
            self.run_dispatch()
        self.assertEqual(self.calls, [])

    def test_symlink_handler(self):
        self.write()
        handler = self.root / "handlers/install-n8n-staging"
        handler.unlink()
        handler.symlink_to("/run/docker.sock")
        with self.assertRaises(ValueError):
            self.run_dispatch()
        self.assertEqual(self.calls, [])


if __name__ == "__main__":
    unittest.main()
