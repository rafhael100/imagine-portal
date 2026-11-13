"""Private development relay adapter. Import only; no CLI, issuer or activation."""
import smtplib
import ssl
from email.message import EmailMessage
from email.policy import SMTP
from email.utils import formatdate, make_msgid


class DeliveryError(RuntimeError):
    """Fixed receipt only: never include provider exceptions or message content."""


class WorkspaceRelay:
    HOST = "smtp-relay.gmail.com"
    PORT = 587
    SOURCE_IP = "85.17.188.240"
    DOMAIN = "imaginecomunicacao.com.br"
    SENDER = "sistema@imaginecomunicacao.com.br"
    # Initial private pilot only. Expanding recipients is a separate change.
    RECIPIENT = "rafhael@imaginecomunicacao.com.br"

    def deliver(self, *, recipient: str, subject: str, text: str) -> None:
        if (recipient != self.RECIPIENT or not isinstance(subject, str)
                or not subject or len(subject) > 160
                or any(ord(c) < 32 or ord(c) == 127 for c in subject)
                or not isinstance(text, str) or not text
                or len(text.encode("utf-8")) > 16384):
            raise DeliveryError("invalid-message")
        message = EmailMessage(policy=SMTP)
        message["From"] = "Imagine <" + self.SENDER + ">"
        message["To"] = recipient
        message["Subject"] = subject
        message["Date"] = formatdate(localtime=False)
        message["Message-ID"] = make_msgid(domain=self.DOMAIN)
        message.set_content(text)
        client = None
        failed = False
        try:
            client = smtplib.SMTP(self.HOST, self.PORT, timeout=20,
                                 source_address=(self.SOURCE_IP, 0))
            if client.ehlo(self.DOMAIN)[0] != 250:
                raise DeliveryError("delivery-not-confirmed")
            # No opportunistic downgrade, AUTH, credentials, or fallback mailer.
            client.starttls(context=ssl.create_default_context())
            if client.ehlo(self.DOMAIN)[0] != 250:
                raise DeliveryError("delivery-not-confirmed")
            refused = client.send_message(message, from_addr=self.SENDER,
                                          to_addrs=[recipient])
            if refused:
                raise DeliveryError("delivery-not-confirmed")
        except Exception:
            failed = True
        finally:
            if client is not None:
                # SMTP acceptance already happened; QUIT failure must not turn
                # it into a retryable failure and duplicate a verification mail.
                try:
                    client.quit()
                except Exception:
                    try:
                        client.close()
                    except Exception:
                        pass
        # Outside the handler: do not chain provider responses or message data.
        if failed:
            raise DeliveryError("delivery-not-confirmed") from None

