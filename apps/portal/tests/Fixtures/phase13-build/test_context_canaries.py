#!/usr/bin/env python3
"""Owned filesystem mutations and bounded synthetic runtime projections only."""
import hashlib
import importlib.util
import json
import os
import pathlib
import stat
import sys
import tempfile
import unittest
from unittest import mock

sys.dont_write_bytecode = True
MODULE = pathlib.Path(__file__).with_name("context-canaries.py")
spec = importlib.util.spec_from_file_location("context_canaries", MODULE)
c = importlib.util.module_from_spec(spec)
spec.loader.exec_module(c)
p = c.p


class Fixture:
    def __init__(self, root):
        self.root, self.temp = root / "checkout", root / "runner"
        self.app = self.root / "apps/portal"
        self.temp.mkdir()
        for relative in ("deploy/staging", "deploy/phase6", "storage/app"):
            (self.app / relative).mkdir(parents=True)
        (self.app / ".dockerignore").write_text("synthetic policy fixture\n")
        self.env = {"GITHUB_ACTIONS": "true", "ADMIN_PHASE13_BUILD_CI": "1", "ADMIN_PHASE7_CI": "1",
                    "GITHUB_RUN_ID": "123", "GITHUB_RUN_ATTEMPT": "1", "GITHUB_SHA": "a"*40,
                    "GITHUB_WORKSPACE": str(self.root), "RUNNER_TEMP": str(self.temp),
                    "P7_PROJECT": "imagine-performance-p7-ci-123-1", "P6_PORTAL_IMAGE": "p7-portal-ci"}
        self.ctx = c.context(self.env)
        self.dirs, self.files, self.contents = c.layout(self.ctx)
        self.actual = {"image_id": "sha256:"+"b"*64, "reference": "p7-portal-ci",
                       "project": self.env["P7_PROJECT"], "service": "portal",
                       "mounts": [{"destination": "/var/www/html/storage", "type": "volume"},
                                  {"destination": "/run/secrets", "type": "bind"}]}
        self.absence = {label: True for label in self.files if label != "public_marker"}
        self.marker = True
        self.after_exec = None
        self.calls = []

    def prepare(self):
        return c.prepare(environ=self.env)

    def cleanup(self):
        return c.cleanup(environ=self.env)

    def verify(self):
        return c.verify(environ=self.env, runner=self.runner)

    def runner(self, args, *, cwd, env, limit):
        self.calls.append(args)
        if args == ["docker", "context", "show"]:
            return "default\n"
        if args[:3] == ["docker", "context", "inspect"]:
            return json.dumps("unix:///var/run/docker.sock")
        if args[:3] == ["docker", "container", "inspect"]:
            self.assert_projection(args)
            return json.dumps(self.actual)
        if args[:2] == ["docker", "compose"]:
            if args[-3:] == ["ps", "-q", "portal"]:
                return "c"*64+"\n"
            expected = ["exec", "-T", "--user", "33:33", "portal", "php", "-n", "-r", c.PHP_PROJECTION, "--"]
            if args[-11:-1] != expected:
                raise AssertionError("unexpected runtime command")
            if self.after_exec:
                self.after_exec()
            return json.dumps({"negative_absence": self.absence, "positive_marker_matches": self.marker})
        raise AssertionError("unexpected command")

    @staticmethod
    def assert_projection(args):
        if args[3:5] != ["--format", c.PROJECTION]:
            raise AssertionError("unsafe container projection")


class ContextTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="phase13-context-test-")
        self.f = Fixture(pathlib.Path(self.temp.name))

    def tearDown(self):
        self.temp.cleanup()

    def denied(self, action, reason):
        with self.assertRaises(p.Refused) as caught:
            action()
        self.assertEqual(str(caught.exception), reason)

    def test_prepare_verify_cleanup_preserves_originals_and_limits_claims(self):
        result = self.f.prepare()
        self.assertEqual(result["canary_file_count"], 11)
        before = {label: path.read_bytes() for label, path in self.f.files.items()}
        evidence = self.f.verify()
        self.assertEqual(evidence["status"], "FOCAL_CONTEXT_EXCLUSIONS_OBSERVED")
        self.assertTrue(evidence["host_original_canaries_unchanged"] and evidence["positive_image_marker_matches"])
        self.assertEqual(len(evidence["excluded_image_file_canaries"]), 9)
        self.assertTrue(evidence["storage_private_runtime_absent"])
        for field in ("storage_image_layers_verified", "build_context_content_verified", "embedded_source_content_verified",
                      "deployment_authorized", "preexisting_files_overwritten", "vps_modified"):
            self.assertFalse(evidence[field])
        self.assertEqual(before, {label: path.read_bytes() for label, path in self.f.files.items()})
        raw = p.canonical(evidence)
        self.assertNotIn(str(self.f.root).encode(), raw)
        self.assertNotIn(b"IMAGINE_PHASE13_SYNTHETIC", raw)
        self.assertEqual(self.f.cleanup()["status"], "OWNED_CANARIES_REMOVED")
        self.assertTrue(all(not path.exists() for path in self.f.files.values()))
        self.assertTrue(all(not path.exists() for path in self.f.dirs.values()))
        self.assertFalse(self.f.ctx["state"].exists())
        self.assertEqual(self.f.cleanup()["status"], "NO_OWNED_CANARIES")

    def test_preexisting_auth_is_preserved_without_other_creations(self):
        path = self.f.files["composer_auth"]
        original = b'preexisting credential fixture: do not read or overwrite\n'
        path.write_bytes(original)
        self.denied(self.f.prepare, "collision")
        self.assertEqual(path.read_bytes(), original)
        self.assertFalse(self.f.ctx["state"].exists())
        self.assertTrue(all(not directory.exists() for directory in self.f.dirs.values()))

    def test_partial_prepare_rolls_back_own_auth_but_preserves_directory_collision(self):
        directory = self.f.dirs["nested"]
        directory.mkdir()
        original = directory / "existing.txt"
        original.write_text("preserve\n")
        self.denied(self.f.prepare, "collision")
        self.assertFalse(self.f.files["composer_auth"].exists())
        self.assertEqual(original.read_text(), "preserve\n")
        self.assertFalse(self.f.ctx["state"].exists())

    def test_partial_write_failure_rolls_back_only_written_prefix(self):
        original_write = os.write
        calls = 0

        def short_then_error(descriptor, data):
            nonlocal calls
            calls += 1
            if calls == 1:
                return original_write(descriptor, data[:8])
            raise OSError("injected write failure")

        with mock.patch.object(c.os, "write", short_then_error):
            with self.assertRaises(OSError):
                self.f.prepare()
        self.assertFalse(self.f.files["composer_auth"].exists())
        self.assertFalse(self.f.ctx["state"].exists())

    def test_existing_private_state_is_not_owned_on_prepare_collision(self):
        self.f.ctx["state"].mkdir(mode=0o700)
        existing = self.f.ctx["state"] / "preserve.txt"
        existing.write_text("preexisting state\n")
        self.denied(self.f.prepare, "collision")
        self.assertEqual(existing.read_text(), "preexisting state\n")
        self.assertFalse(self.f.files["composer_auth"].exists())

    def test_changed_original_is_refused_and_cleanup_preserves_changed_bytes(self):
        self.f.prepare()
        changed = self.f.files["composer_auth"]
        changed.write_text("new external content\n")
        self.denied(self.f.verify, "original_changed")
        self.denied(self.f.cleanup, "cleanup")
        self.assertEqual(changed.read_text(), "new external content\n")
        self.assertTrue((self.f.ctx["state"] / "ownership.json").exists())

    def test_replacement_symlink_is_neither_followed_nor_removed(self):
        self.f.prepare()
        target = self.f.temp / "unrelated.txt"
        target.write_text("preserve external target\n")
        path = self.f.files["composer_auth"]
        path.unlink()
        path.symlink_to(target)
        self.denied(self.f.cleanup, "cleanup")
        self.assertTrue(path.is_symlink())
        self.assertEqual(target.read_text(), "preserve external target\n")

    def test_unowned_file_in_created_directory_is_preserved(self):
        self.f.prepare()
        original = self.f.dirs["nested"] / "external.txt"
        original.write_text("preserve\n")
        self.denied(self.f.cleanup, "cleanup")
        self.assertEqual(original.read_text(), "preserve\n")
        self.assertTrue((self.f.ctx["state"] / "ownership.json").exists())

    def test_journal_cannot_authorize_removal_of_replacement_content(self):
        self.f.prepare()
        path = self.f.files["composer_auth"]
        replacement = b"preexisting credential fixture\n"
        path.write_bytes(replacement)
        state_path = self.f.ctx["state"] / "ownership.json"
        state = json.loads(state_path.read_bytes())
        state["files"]["composer_auth"]["size"] = len(replacement)
        state["files"]["composer_auth"]["sha256"] = hashlib.sha256(replacement).hexdigest()
        state_path.write_bytes(p.canonical(state))
        self.denied(self.f.cleanup, "state")
        self.assertEqual(path.read_bytes(), replacement)

    def test_runtime_presence_and_missing_positive_marker_are_refused(self):
        self.f.prepare()
        self.f.absence["nested_pem"] = False
        self.denied(self.f.verify, "runtime_canary")
        self.f.absence["nested_pem"] = True
        self.f.marker = False
        self.denied(self.f.verify, "runtime_canary")

    def test_image_paths_masked_by_mount_cannot_claim_image_exclusion(self):
        self.f.prepare()
        self.f.actual["mounts"].append({"destination": "/var/www/html/deploy", "type": "bind"})
        self.denied(self.f.verify, "runtime_mount")

    def test_wrong_service_and_remote_docker_are_refused(self):
        self.f.prepare()
        self.f.actual["service"] = "receiver"
        self.denied(self.f.verify, "runtime")
        self.f.actual["service"] = "portal"
        self.f.env["DOCKER_HOST"] = "ssh://private.invalid"
        self.denied(self.f.verify, "context")

    def test_canaries_are_rechecked_after_runtime_read(self):
        self.f.prepare()
        self.f.after_exec = lambda: self.f.files["composer_auth"].write_text("changed during read\n")
        self.denied(self.f.verify, "original_changed")

    def test_public_marker_modes_are_independent_of_restrictive_umask(self):
        original = os.umask(0o077)
        try:
            self.f.prepare()
        finally:
            os.umask(original)
        self.assertEqual(stat.S_IMODE(self.f.dirs["nested"].stat().st_mode), 0o755)
        self.assertEqual(stat.S_IMODE(self.f.files["public_marker"].stat().st_mode), 0o644)
        self.assertEqual(stat.S_IMODE(self.f.ctx["state"].stat().st_mode), 0o700)
        self.f.verify()
        self.f.cleanup()

    def test_ci_guard_refuses_before_creating_canaries(self):
        del self.f.env["ADMIN_PHASE13_BUILD_CI"]
        self.denied(self.f.prepare, "ci_only")
        self.assertFalse(self.f.files["composer_auth"].exists())
        self.assertFalse(self.f.ctx["state"].exists())


if __name__ == "__main__":
    unittest.main()
