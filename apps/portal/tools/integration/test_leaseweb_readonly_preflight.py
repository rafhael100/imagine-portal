"""Adversarial tests: Leaseweb read-only audit must never open secrets/write data."""
from __future__ import annotations

import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest
from unittest import mock

SCRIPT = Path(__file__).with_name("leaseweb_readonly_preflight.py")
spec = importlib.util.spec_from_file_location("leaseweb_preflight", SCRIPT)
assert spec and spec.loader
preflight = importlib.util.module_from_spec(spec)
spec.loader.exec_module(preflight)

class ReadOnlyPreflightTests(unittest.TestCase):
    def test_missing_folder_stays_no_go_and_never_emits_environment(self):
        with tempfile.TemporaryDirectory() as base:
            nonexistent = Path(base) / "not-present"
            with mock.patch.dict(os.environ, {"MY_SECRET":"SHOULD_NOT_LEAK"}), \
                 mock.patch.object(preflight, "version_command",
                                   return_value={"available":False,"successful":False}):
                report = preflight.overview(nonexistent,probe=False)
            blob=json.dumps(report)
            self.assertNotIn("SHOULD_NOT_LEAK",blob)
            self.assertFalse(report["checks"]["staging_folder_exists"])
            self.assertEqual(report["release_decision"]["status"],"NO_GO")
            self.assertFalse(report["release_decision"]["deploy_allowed"])
            self.assertFalse(report["preflight_basic_host_ready"])
    def test_never_opens_runtime_credentials_and_checks_world_readable_files(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            private=root / ".private"
            private.mkdir()
            runtime=private / "runtime.json"
            password=private / "admin-password"
            runtime.write_text('{"api_secret":"NEVER_READ_THIS_VALUE"}',encoding="utf-8")
            password.write_text("VERY_PRIVATE_PASSWORD",encoding="utf-8")
            runtime.chmod(0o600)
            password.chmod(0o600)
            observed=[]
            original_open=open
            def guarded_open(path,*args,**kwargs):
                observed.append(str(path))
                if str(private) in str(path):
                    raise AssertionError("Secret was opened instead of lstat metadata.")
                return original_open(path,*args,**kwargs)
            with mock.patch("builtins.open",side_effect=guarded_open), \
                 mock.patch.object(preflight,"version_command",
                                   return_value={"available":True,"successful":True}):
                report=preflight.overview(root,probe=False)
            blob=json.dumps(report)
            self.assertNotIn("NEVER_READ_THIS_VALUE",blob)
            self.assertNotIn("VERY_PRIVATE_PASSWORD",blob)
            self.assertTrue(report["checks"]["runtime_file_metadata"]["private_mode"])
            self.assertEqual(report["checks"]["private_file_permission_issues"],[])
            self.assertTrue(all(str(private) not in item for item in observed))
            runtime.chmod(0o644)
            with mock.patch.object(preflight,"version_command",
                                   return_value={"available":True,"successful":True}):
                bad=preflight.overview(root,probe=False)
            self.assertIn("runtime_permissions",bad["checks"]["private_file_permission_issues"])
            self.assertFalse(bad["preflight_basic_host_ready"])
    def test_symlink_secret_must_not_be_read_or_treated_as_private(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            private=root / ".private"
            private.mkdir()
            (private/"runtime.json").symlink_to("/etc/passwd")
            details=preflight.file_metadata(private/"runtime.json")
            self.assertTrue(details["exists"])
            self.assertTrue(details["symlink"])
            self.assertFalse(details["private_mode"])
            self.assertFalse(details["regular"])
    def test_probe_limited_to_fixed_localhost_and_port(self):
        for host,port in [("example.com",18083),("127.0.0.1",443),
                          ("0.0.0.0",18083)]:
            with self.assertRaises(ValueError):
                preflight.local_listener(host,port)
    def test_version_diagnostics_never_expose_cli_stdout(self):
        class FakeResult:
            returncode=0
            stdout="TOKEN=SHOULD_NOT_BE_IN_JSON"
            stderr="secret error"
        with mock.patch.object(preflight.shutil,"which",return_value="/usr/bin/docker"), \
             mock.patch.object(preflight.subprocess,"run",return_value=FakeResult()):
            result=preflight.version_command(["docker","--version"])
        self.assertEqual(result,{"available":True,"successful":True})
        self.assertNotIn("TOKEN",json.dumps(result))
    def test_cli_cannot_accept_arbitrary_paths_or_deploy_switches(self):
        with self.assertRaises(SystemExit):
            preflight.main(["--deploy"])
        with self.assertRaises(SystemExit):
            preflight.main(["--root","/etc"])
        with mock.patch.object(preflight,"overview",return_value={
            "release_decision":{"status":"NO_GO","deploy_allowed":False},
            "checks":{"docker_cli":{"successful":True},"compose_cli":{"successful":True},
                      "docker_daemon":{"successful":True},"private_folder_exists":False,
                      "runtime_file_metadata":{"private_mode":False},
                      "local_preview_port_18083_open":False,"disk_free_gib_rounded_down":1,
                      "private_file_permission_issues":[]}
        }):
            with contextlib.redirect_stdout(io.StringIO()) as output:
                code=preflight.main(["--json"])
            self.assertEqual(code,0)
            self.assertFalse(json.loads(output.getvalue())["release_decision"]["deploy_allowed"])

if __name__=="__main__":
    unittest.main()
