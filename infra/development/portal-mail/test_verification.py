import traceback
import unittest
from unittest.mock import MagicMock, patch

from relay import WorkspaceRelay
from verification import (IssuedChallenge, VerificationCoordinator,
                          VerificationError)


class VerificationFlowTests(unittest.TestCase):
    REFERENCE = "1" * 32
    TOKEN = "023456"

    def setUp(self):
        self.ledger = MagicMock()
        self.transport = MagicMock()
        self.ledger.reserve.return_value = IssuedChallenge(
            self.REFERENCE, WorkspaceRelay.RECIPIENT)
        self.ledger.mark_accepted.return_value = True
        self.flow = VerificationCoordinator(self.ledger, self.transport)
        self.random = patch("verification.secrets.randbelow", return_value=23456)
        self.generate = self.random.start()
        self.addCleanup(self.random.stop)

    def test_reservation_precedes_mail_and_accepted_receipt(self):
        calls = MagicMock()
        calls.attach_mock(self.ledger, "ledger")
        calls.attach_mock(self.transport, "transport")
        receipt = self.flow.send(WorkspaceRelay.RECIPIENT)
        self.generate.assert_called_once_with(1_000_000)
        self.ledger.reserve.assert_called_once_with(
            WorkspaceRelay.RECIPIENT, self.TOKEN, 300)
        self.assertEqual([c[0] for c in calls.mock_calls],
                         ["ledger.reserve", "transport.deliver", "ledger.mark_accepted"])
        self.assertNotIn(self.TOKEN, repr(receipt))
        self.assertFalse(receipt.email_verified)
        self.assertFalse(receipt.login_enabled)
        self.assertFalse(receipt.business_authorized)

    def test_proof_goes_only_in_message_body_not_subject_or_receipt(self):
        self.flow.send(WorkspaceRelay.RECIPIENT)
        args = self.transport.deliver.call_args.kwargs
        self.assertEqual(args["recipient"], WorkspaceRelay.RECIPIENT)
        self.assertNotIn(self.TOKEN, args["subject"])
        self.assertEqual(args["text"].count(self.TOKEN), 1)

    def test_other_recipient_never_reserves_or_sends(self):
        with self.assertRaises(VerificationError):
            self.flow.send("other@example.invalid")
        self.ledger.reserve.assert_not_called()
        self.transport.deliver.assert_not_called()

    def test_reservation_failure_never_sends(self):
        self.ledger.reserve.side_effect = RuntimeError(self.TOKEN)
        with self.assertRaises(VerificationError):
            self.flow.send(WorkspaceRelay.RECIPIENT)
        self.transport.deliver.assert_not_called()

    def test_wrong_ledger_scope_never_sends(self):
        self.ledger.reserve.return_value = IssuedChallenge(
            self.REFERENCE, "other@example.invalid")
        with self.assertRaises(VerificationError):
            self.flow.send(WorkspaceRelay.RECIPIENT)
        self.transport.deliver.assert_not_called()
        self.ledger.revoke.assert_called_once_with(self.REFERENCE)

    def test_smtp_failure_requests_revocation_and_does_not_retry(self):
        self.transport.deliver.side_effect = RuntimeError(self.TOKEN)
        with self.assertRaises(VerificationError):
            self.flow.send(WorkspaceRelay.RECIPIENT)
        self.ledger.revoke.assert_called_once_with(self.REFERENCE)
        self.transport.deliver.assert_called_once()
        self.ledger.mark_accepted.assert_not_called()

    def test_lost_delivery_transition_requests_revocation(self):
        self.ledger.mark_accepted.return_value = False
        with self.assertRaises(VerificationError):
            self.flow.send(WorkspaceRelay.RECIPIENT)
        self.ledger.revoke.assert_called_once_with(self.REFERENCE)

    def test_provider_and_revocation_errors_are_not_exposed(self):
        self.transport.deliver.side_effect = RuntimeError(self.TOKEN)
        self.ledger.revoke.side_effect = RuntimeError(self.TOKEN)
        try:
            self.flow.send(WorkspaceRelay.RECIPIENT)
        except VerificationError as error:
            self.assertNotIn(self.TOKEN, "".join(traceback.format_exception(error)))
            self.assertIsNone(error.__context__)
        else:
            self.fail("Failure must be explicit")

    def test_malformed_confirmations_never_reach_ledger(self):
        for token in ["", "12345", "1234567", "１２３４５６", "123456\n", "a" * 64, None]:
            with self.assertRaises(VerificationError):
                self.flow.confirm(self.REFERENCE, token)
        self.ledger.consume.assert_not_called()

    def test_atomic_consumption_confirms_only_email(self):
        self.ledger.consume.return_value = True
        receipt = self.flow.confirm(self.REFERENCE, self.TOKEN)
        self.ledger.consume.assert_called_once_with(self.REFERENCE, self.TOKEN)
        self.assertTrue(receipt.email_verified)
        self.assertFalse(receipt.login_enabled)
        self.assertFalse(receipt.business_authorized)

    def test_replay_expiry_attempt_limit_are_enforced_by_ledger_result(self):
        self.ledger.consume.side_effect = [True, False]
        self.flow.confirm(self.REFERENCE, self.TOKEN)
        with self.assertRaises(VerificationError):
            self.flow.confirm(self.REFERENCE, self.TOKEN)

    def test_consume_exception_has_no_secret_traceback(self):
        self.ledger.consume.side_effect = RuntimeError(self.TOKEN)
        try:
            self.flow.confirm(self.REFERENCE, self.TOKEN)
        except VerificationError as error:
            self.assertNotIn(self.TOKEN, "".join(traceback.format_exception(error)))
            self.assertIsNone(error.__context__)
        else:
            self.fail("Ledger failure must be explicit")


if __name__ == "__main__":
    unittest.main()
