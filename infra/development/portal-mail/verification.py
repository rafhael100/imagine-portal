"""Verification flow boundary. No database implementation, CLI or activation.

The ledger must obtain scope from the persisted invited identity, bind its
revision/organization, store only a keyed digest, and implement atomic CAS.
This module cannot safely be used with an in-memory ledger operationally.
"""
import re
import secrets
from dataclasses import dataclass, field
from typing import Protocol

from relay import WorkspaceRelay


@dataclass(frozen=True)
class IssuedChallenge:
    # Non-secret opaque ledger reference, never a verification bearer.
    reference: str
    recipient: str


class ProofLedger(Protocol):
    def reserve(self, recipient: str, token: str, ttl_seconds: int) -> IssuedChallenge:
        """Atomically validate invited scope and reserve one pending digest.

        Refuse pending challenges, verified accounts, ambiguous membership,
        active sessions, grants or changed revisions. Use authoritative DB time.
        Token must be HMAC-bound to identity/org/email/revision and never logged.
        If reservation fails, it must roll back and leave no usable challenge.
        """
        ...

    def mark_accepted(self, reference: str) -> bool:
        """CAS pending -> delivered; false on expiry/revision/restore mismatch."""
        ...

    def revoke(self, reference: str) -> None:
        """Monotonically revoke without exposing bearer or modifying identity."""
        ...

    def consume(self, reference: str, token: str) -> bool:
        """Atomic attempt limit, expiry, scope/revision and digest verification.

        Single consumption and email_verified_at must commit together. Never
        activate identity/membership, issue sessions or grant business access.
        """
        ...


class VerificationError(RuntimeError):
    pass


@dataclass(frozen=True)
class VerificationReceipt:
    reference: str
    # Email verification does not activate login or authorize business data.
    email_verified: bool = False
    login_enabled: bool = field(default=False, init=False)
    business_authorized: bool = field(default=False, init=False)


class VerificationCoordinator:
    TTL_SECONDS = 540

    def __init__(self, ledger: ProofLedger, transport: WorkspaceRelay):
        self._ledger = ledger
        self._transport = transport

    def send(self, recipient: str) -> VerificationReceipt:
        if recipient != WorkspaceRelay.RECIPIENT:
            raise VerificationError("verification-not-issued")
        token = secrets.token_hex(32)
        issued = None
        accepted = False
        failed = False
        try:
            issued = self._ledger.reserve(recipient, token, self.TTL_SECONDS)
            if (not isinstance(issued, IssuedChallenge)
                    or issued.recipient != recipient
                    or re.fullmatch(r"[a-f0-9]{32}", issued.reference) is None):
                raise VerificationError("verification-not-issued")
            self._transport.deliver(
                recipient=recipient,
                subject="Imagine - Confirme seu e-mail",
                text=("Confirmacao privada do Portal Imagine.\n"
                      "Copie este codigo somente para o console privado de cadastro:\n"
                      + token + "\nValidade: 9 minutos. Uso unico.\n"
                      "Esta confirmacao nao ativa o login nem libera servicos.\n"
                      "Nao compartilhe o codigo no chat.\n"),
            )
            accepted = self._ledger.mark_accepted(issued.reference) is True
            if not accepted:
                raise VerificationError("verification-not-issued")
        except Exception:
            failed = True
        finally:
            token = ""
            if failed and isinstance(issued, IssuedChallenge):
                # SMTP failure can be ambiguous. Request revocation and never
                # retry automatically. The persistent implementation must
                # quarantine uncertain state if revocation is unavailable.
                try:
                    self._ledger.revoke(issued.reference)
                except Exception:
                    pass
        if failed or not accepted:
            raise VerificationError("verification-not-issued") from None
        return VerificationReceipt(issued.reference)

    def confirm(self, reference: str, token: str) -> VerificationReceipt:
        if (not isinstance(reference, str)
                or re.fullmatch(r"[a-f0-9]{32}", reference) is None
                or not isinstance(token, str)
                or re.fullmatch(r"[a-f0-9]{64}", token) is None):
            raise VerificationError("verification-not-confirmed")
        verified = False
        try:
            verified = self._ledger.consume(reference, token) is True
        except Exception:
            pass
        finally:
            token = ""
        if not verified:
            raise VerificationError("verification-not-confirmed") from None
        return VerificationReceipt(reference, email_verified=True)
