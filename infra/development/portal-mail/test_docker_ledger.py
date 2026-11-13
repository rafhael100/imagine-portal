import json
import subprocess
import traceback
import unittest
from unittest.mock import patch

from docker_ledger import DockerLedger, LedgerError
from relay import WorkspaceRelay


class BridgeTests(unittest.TestCase):
    def test_bearer_is_only_in_private_child_stdin(self):
        token = "023456"
        output = json.dumps(dict(ok=True, value=dict(reference="c" * 32,
                                                      recipient=WorkspaceRelay.RECIPIENT)))
        with patch("docker_ledger.subprocess.run", return_value=
                   subprocess.CompletedProcess([], 0, output, "")) as run:
            result = DockerLedger().reserve(WorkspaceRelay.RECIPIENT, token, 300)
        args = run.call_args
        self.assertNotIn(token, " ".join(args.args[0]))
        self.assertEqual(json.loads(args.kwargs["input"])["token"], token)
        self.assertTrue(args.kwargs["capture_output"])
        self.assertEqual(args.kwargs["timeout"], 25)
        self.assertNotIn("env", args.kwargs)
        self.assertEqual(result.reference, "c" * 32)

    def test_failed_child_does_not_expose_output(self):
        with patch("docker_ledger.subprocess.run", return_value=
                   subprocess.CompletedProcess([], 1, "private-bearer", "private-error")):
            try:
                DockerLedger().consume("c" * 32, "023456")
            except LedgerError as error:
                rendered = "".join(traceback.format_exception(error))
                self.assertNotIn("private-bearer", rendered)
                self.assertNotIn("private-error", rendered)
                self.assertIsNone(error.__context__)
            else:
                self.fail("Failed child must fail closed")

    def test_timeout_is_not_retried(self):
        with patch("docker_ledger.subprocess.run", side_effect=
                   subprocess.TimeoutExpired("child", 25, output="private")) as run:
            with self.assertRaises(LedgerError):
                DockerLedger().consume("c" * 32, "023456")
        run.assert_called_once()

    def test_non_tty_operator_entrypoint_refuses_execution(self):
        result = subprocess.run(["python3", "confirm_email.py"],
                                input="", text=True, capture_output=True)
        self.assertEqual(result.returncode, 64)
        self.assertIn("Console privado", result.stdout)


if __name__ == "__main__":
    unittest.main()
