import smtplib
import ssl
import traceback
import unittest
from unittest.mock import MagicMock, patch

from relay import DeliveryError, WorkspaceRelay


class RelayTests(unittest.TestCase):
    def setUp(self):
        self.client = MagicMock()
        self.client.ehlo.return_value = (250, b"ok")
        self.client.send_message.return_value = {}
        self.factory = patch("relay.smtplib.SMTP", return_value=self.client)
        self.smtp = self.factory.start()
        self.addCleanup(self.factory.stop)

    def deliver(self, **changes):
        args = dict(recipient=WorkspaceRelay.RECIPIENT,
                    subject="Synthetic confirmation", text="synthetic-secret-body")
        args.update(changes)
        WorkspaceRelay().deliver(**args)

    def test_tls_precedes_delivery_and_uses_certificate_validation(self):
        self.deliver()
        self.smtp.assert_called_once_with("smtp-relay.gmail.com", 587,
            timeout=20, source_address=("85.17.188.240", 0))
        self.assertEqual([c[0] for c in self.client.method_calls],
                         ["ehlo", "starttls", "ehlo", "send_message", "quit"])
        context = self.client.starttls.call_args.kwargs["context"]
        self.assertEqual(context.verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(context.check_hostname)
        self.client.login.assert_not_called()
        self.client.set_debuglevel.assert_not_called()

    def test_fixed_sender_and_explicit_single_envelope_recipient(self):
        self.deliver()
        args = self.client.send_message.call_args
        self.assertEqual(args.kwargs, dict(from_addr=WorkspaceRelay.SENDER,
                                          to_addrs=[WorkspaceRelay.RECIPIENT]))
        self.assertEqual(args.args[0]["To"], WorkspaceRelay.RECIPIENT)
        self.assertNotIn("Bcc", args.args[0])

    def test_invalid_messages_never_connect(self):
        for changes in [dict(recipient="other@example.invalid"),
                        dict(subject="bad\r\nBcc: other@example.invalid"),
                        dict(subject=""), dict(text=""), dict(text="x" * 16385)]:
            with self.assertRaises(DeliveryError):
                self.deliver(**changes)
        self.smtp.assert_not_called()

    def test_tls_failure_does_not_send_or_fall_back(self):
        self.client.starttls.side_effect = ssl.SSLCertVerificationError("private")
        with self.assertRaisesRegex(DeliveryError, "delivery-not-confirmed"):
            self.deliver()
        self.client.send_message.assert_not_called()
        self.smtp.assert_called_once()

    def test_ehlo_failure_does_not_send(self):
        self.client.ehlo.side_effect = [(250, b"ok"), (550, b"private")]
        with self.assertRaises(DeliveryError):
            self.deliver()
        self.client.send_message.assert_not_called()

    def test_provider_exception_has_no_sensitive_chain_or_message(self):
        self.client.send_message.side_effect = smtplib.SMTPDataError(
            550, b"synthetic-secret-body")
        try:
            self.deliver()
        except DeliveryError as error:
            rendered = "".join(traceback.format_exception(error))
            self.assertNotIn("synthetic-secret-body", rendered)
            self.assertIsNone(error.__context__)
        else:
            self.fail("Provider rejection must fail closed")
        self.client.send_message.assert_called_once()

    def test_refused_recipient_is_not_reported_as_success(self):
        self.client.send_message.return_value = {WorkspaceRelay.RECIPIENT: (550, b"no")}
        with self.assertRaises(DeliveryError):
            self.deliver()

    def test_quit_failure_after_acceptance_does_not_cause_duplicate_retry(self):
        self.client.quit.side_effect = OSError("disconnect")
        self.deliver()
        self.client.send_message.assert_called_once()
        self.client.close.assert_called_once()


if __name__ == "__main__":
    unittest.main()
