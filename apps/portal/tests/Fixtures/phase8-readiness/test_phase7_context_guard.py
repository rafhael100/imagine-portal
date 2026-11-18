#!/usr/bin/env python3
"""Execute the real Phase7 guard/cleanup with offline executables, never Docker."""
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
SCRIPT = Path(__file__).resolve().parents[1] / "phase7/run.sh"
REPOSITORY = SCRIPT.parents[5]

# PATH contains only these disposable stubs and the actual Python interpreter.
# No stub delegates to Docker, sudo, rm, certutil or any host utility.
STUB = '''#!{python}
import json,os,pathlib,sys
name=pathlib.Path(__file__).name
args=sys.argv[1:]
with open(os.environ["P7_CONTEXT_TEST_CALLS"],"a",encoding="utf-8") as output:
    output.write(json.dumps({{"command":name,"args":args}})+"\\n")
if name=="docker":
    if args==["context","show"]:
        print(os.environ.get("P7_CONTEXT_TEST_CONTEXT","default"));sys.exit(0)
    if args==["context","inspect","default","--format","{{{{json .Endpoints.docker.Host}}}}"]:
        print(json.dumps(os.environ.get("P7_CONTEXT_TEST_ENDPOINT","unix:///var/run/docker.sock")));sys.exit(0)
    if args and args[0]=="compose" and any(operation in args for operation in ["ps","logs","down"]):
        sys.exit(0)
    sys.exit(91)
if name=="sudo":
    sys.exit(0 if args[:2]==["rm","-rf"] else 91)
if name in ["rm","certutil"]:
    sys.exit(0)
sys.exit(91)
'''


def function(source, name):
    """Extract the checked-in top-level function verbatim, including its heredoc."""
    matches = list(re.finditer(r"(?ms)^" + re.escape(name) + r"\(\)\{\n.*?^\}\n", source))
    if len(matches) != 1:
        raise AssertionError("expected one actual Phase7 function")
    return matches[0].group(0)


class ContextGuardTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="phase7-context-guard-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / "bin"
        self.bin.mkdir(mode=0o700)
        self.calls_path = self.root / "calls.jsonl"
        for name in ("docker", "sudo", "rm", "certutil", "mkdir"):
            path = self.bin / name
            path.write_text(STUB.format(python=sys.executable))
            path.chmod(0o700)
        (self.bin / "python3").symlink_to(sys.executable)
        self.env = {
            "PATH": str(self.bin), "HOME": os.environ["HOME"], "LC_ALL": "C", "PYTHONDONTWRITEBYTECODE": "1",
            "GITHUB_ACTIONS": "true", "ADMIN_PHASE7_CI": "1", "GITHUB_RUN_ID": "414", "GITHUB_RUN_ATTEMPT": "1",
            "GITHUB_WORKSPACE": str(REPOSITORY), "RUNNER_TEMP": str(self.root),
            "P7_CONTEXT_TEST_CALLS": str(self.calls_path), "DOCKER_CONTEXT": "default",
            "P7_PROJECT": "imagine-performance-p7-ci-414-1", "P6_CI_MATERIAL": str(self.root / "phase7-414-1"),
            "P7_BROWSER_VAULT": str(self.root / "phase7-browser-vault.json"),
        }

    def commands(self):
        if not self.calls_path.exists():
            return []
        return [json.loads(line) for line in self.calls_path.read_text().splitlines()]

    def execute(self, script=SCRIPT, *, extra=None):
        if self.calls_path.exists():
            self.calls_path.unlink()
        environment = dict(self.env, **({} if extra is None else extra))
        return subprocess.run(["/bin/bash", str(script)], cwd=REPOSITORY, env=environment,
                              stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)

    def harness(self, *, started, incoming, extra=None):
        source = SCRIPT.read_text()
        base = [line for line in source.splitlines() if line.startswith("base=(")]
        self.assertEqual(len(base), 1)
        # Only fixture globals and the EXIT stimulus are synthetic. Both actual
        # functions and the Compose command array are copied verbatim from run.sh.
        program = "set -euo pipefail\n" + function(source, "require_local_docker") + function(source, "cleanup")
        program += base[0] + "\np13Captured=0 p13CanariesPrepared=0 p7ResourcesStarted=" + str(started) + "\n"
        program += "trap cleanup EXIT\nexit " + str(incoming) + "\n"
        path = self.root / "cleanup-harness.sh"
        path.write_text(program)
        return self.execute(path, extra=extra)

    def assert_context_reads_only(self):
        docker = [row["args"] for row in self.commands() if row["command"] == "docker"]
        self.assertEqual(docker, [["context", "show"], ["context", "inspect", "default", "--format", "{{json .Endpoints.docker.Host}}"]])
        self.assertFalse(any(row["command"] == "docker" and "compose" in row["args"] for row in self.commands()))

    def test_actual_script_rejects_inherited_override_before_any_command_or_material(self):
        for override in ({"DOCKER_HOST": "ssh://synthetic-private.invalid"}, {"DOCKER_CONTEXT": "synthetic-remote"}):
            with self.subTest(override=next(iter(override))):
                completed = self.execute(extra=override)
                self.assertEqual(completed.returncode, 1)
                self.assertIn(b"PHASE7_LOCAL_DOCKER_REQUIRED", completed.stderr)
                self.assertEqual(self.commands(), [])
                self.assertFalse((self.root / "phase7-414-1").exists())
                self.assertFalse((self.root / "phase7-evidence").exists())

    def test_actual_script_rejects_remote_default_endpoint_before_sudo_or_compose(self):
        completed = self.execute(extra={"P7_CONTEXT_TEST_ENDPOINT": "tcp://synthetic-private.invalid:2375"})
        self.assertEqual(completed.returncode, 1)
        self.assert_context_reads_only()
        self.assertTrue(all(row["command"] == "docker" for row in self.commands()))
        self.assertFalse((self.root / "phase7-414-1").exists())
        self.assertFalse((self.root / "phase7-evidence").exists())
        self.assertNotIn(b"synthetic-private.invalid", completed.stdout + completed.stderr)

    def test_real_cleanup_does_not_touch_docker_before_resources_start(self):
        completed = self.harness(started=0, incoming=23)
        self.assertEqual(completed.returncode, 23)
        self.assertFalse(any(row["command"] == "docker" for row in self.commands()))

    def test_real_cleanup_refuses_changed_context_and_preserves_failure(self):
        for incoming, expected_exit in ((23, 23), (0, 1)):
            with self.subTest(incoming=incoming):
                completed = self.harness(started=1, incoming=incoming,
                                         extra={"P7_CONTEXT_TEST_ENDPOINT": "ssh://synthetic-private.invalid"})
                self.assertEqual(completed.returncode, expected_exit)
                self.assert_context_reads_only()
                self.assertIn(b"PHASE7_DOCKER_CLEANUP_REFUSED", completed.stderr)
                self.assertNotIn(b"synthetic-private.invalid", completed.stdout + completed.stderr)

    def test_real_cleanup_positive_control_revalidates_before_scoped_down(self):
        completed = self.harness(started=1, incoming=23)
        self.assertEqual(completed.returncode, 23)
        docker = [row["args"] for row in self.commands() if row["command"] == "docker"]
        self.assertEqual(docker[:2], [["context", "show"], ["context", "inspect", "default", "--format", "{{json .Endpoints.docker.Host}}"]])
        compose = [args for args in docker if args and args[0] == "compose"]
        self.assertEqual(len(compose), 3)
        self.assertEqual([args[5] for args in compose], ["ps", "logs", "down"])
        self.assertTrue(all(args[:5] == ["compose", "-p", self.env["P7_PROJECT"], "-f", "apps/portal/deploy/phase6/compose.yaml"]
                            for args in compose))
        self.assertEqual(compose[-1][5:], ["down", "--volumes", "--remove-orphans"])


if __name__ == "__main__":
    unittest.main(verbosity=1)
