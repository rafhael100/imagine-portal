"""R07-F3: offline, synthetic-only transaction-intent state machine.

This module is NOT Portal authentication, consent, WebAuthn, PostgreSQL, or
an independently trusted confirmation channel. All identifiers and effects
are fake. It models logical invariants before human AppSec/DBA review.
"""
from __future__ import annotations

from dataclasses import dataclass
import hashlib
import hmac
import json
import secrets
import threading
from typing import Any


DOMAIN = "IMAGINE-R07-F3-SYNTHETIC-INTENT-V1"
VALID_ACTION = "change_billing"


class Denied(Exception):
    """A synthetic authorization failure; never indicates a real HTTP status."""


def canonical(payload: dict[str, Any]) -> bytes:
    return json.dumps(payload, ensure_ascii=True, sort_keys=True,
                      separators=(",", ":"), allow_nan=False).encode("ascii")


@dataclass(frozen=True)
class Intent:
    intent_id: str
    actor: str
    session: str
    session_epoch: int
    tenant: str
    resource: str
    action: str
    amount_minor: int
    currency: str
    destination: str
    membership_revision: int
    policy_revision: int
    nonce: str
    expires_at: int
    digest: str

    def meaningful_details(self) -> dict[str, Any]:
        return {
            "tenant": self.tenant,
            "resource": self.resource,
            "action": self.action,
            "amount_minor": self.amount_minor,
            "currency": self.currency,
            "destination": self.destination,
        }

    def payload(self) -> dict[str, Any]:
        return {
            "domain": DOMAIN,
            "intent_id": self.intent_id,
            "actor": self.actor,
            "session": self.session,
            "session_epoch": self.session_epoch,
            "tenant": self.tenant,
            "resource": self.resource,
            "action": self.action,
            "amount_minor": self.amount_minor,
            "currency": self.currency,
            "destination": self.destination,
            "membership_revision": self.membership_revision,
            "policy_revision": self.policy_revision,
            "nonce": self.nonce,
            "expires_at": self.expires_at,
        }


class Authority:
    """Synthetic Z2 state. No SQL, HTTP, crypto issuer, or real identity here."""

    def __init__(self) -> None:
        self._lock = threading.RLock()
        self.accounts = {
            "actor-a": {"active": True, "epoch": 1},
            "actor-b": {"active": True, "epoch": 1},
        }
        self.sessions = {
            "lab-session-a": {"actor": "actor-a", "epoch": 1},
            "lab-session-b": {"actor": "actor-b", "epoch": 1},
        }
        self.resources = {"billing-a": "org-a", "billing-b": "org-b"}
        self.memberships = {
            ("actor-a", "org-a"): {"active": True, "revision": 1,
                                    "capabilities": {VALID_ACTION}},
            ("actor-b", "org-b"): {"active": True, "revision": 1,
                                    "capabilities": {VALID_ACTION}},
        }
        self.policy_revision = 1
        self.intents: dict[str, dict[str, Any]] = {}
        self.effects: list[dict[str, Any]] = []
        self._confirmation_key = secrets.token_bytes(32)

    def _resolve(self, session: str, resource: str, action: str):
        record = self.sessions.get(session)
        if record is None:
            raise Denied("unknown synthetic session")
        actor = record["actor"]
        account = self.accounts[actor]
        if not account["active"] or account["epoch"] != record["epoch"]:
            raise Denied("session revoked")
        tenant = self.resources.get(resource)
        if tenant is None:
            raise Denied("resource absent")
        membership = self.memberships.get((actor, tenant))
        if membership is None or not membership["active"]:
            raise Denied("membership absent")
        if action not in membership["capabilities"]:
            raise Denied("capability absent")
        return actor, tenant, account, membership

    def issue(self, *, session: str, resource: str, action: str,
              amount_minor: int, currency: str, destination: str,
              now: int, claims: dict[str, Any] | None = None) -> Intent:
        """Claims are intentionally ignored as facts about actor or tenant."""
        del claims
        with self._lock:
            if action != VALID_ACTION:
                raise Denied("action not recognized")
            if isinstance(amount_minor, bool) or not isinstance(amount_minor, int):
                raise Denied("amount must be integer minor units")
            if amount_minor <= 0 or amount_minor > 1_000_000:
                raise Denied("amount outside synthetic bounds")
            if currency != "BRL":
                raise Denied("currency outside synthetic scope")
            if not isinstance(destination, str) or not (
                1 <= len(destination) <= 40 and destination.isascii()
                and destination.replace("-", "").isalnum()
            ):
                raise Denied("destination not canonical")
            actor, tenant, account, membership = self._resolve(
                session, resource, action
            )
            fields = {
                "intent_id": secrets.token_hex(16),
                "actor": actor,
                "session": session,
                "session_epoch": account["epoch"],
                "tenant": tenant,
                "resource": resource,
                "action": action,
                "amount_minor": amount_minor,
                "currency": currency,
                "destination": destination,
                "membership_revision": membership["revision"],
                "policy_revision": self.policy_revision,
                "nonce": secrets.token_hex(16),
                "expires_at": now + 120,
            }
            digest = hashlib.sha256(canonical({"domain": DOMAIN, **fields})).hexdigest()
            intent = Intent(**fields, digest=digest)
            self.intents[intent.intent_id] = {
                "intent": intent, "state": "awaiting_confirmation",
            }
            return intent

    def simulated_independent_confirm(
        self, intent_id: str, displayed: dict[str, Any], *, now: int
    ) -> str:
        """MOCK ONLY. Real users and independent channels are NOT modeled."""
        with self._lock:
            row = self.intents.get(intent_id)
            if row is None or row["state"] != "awaiting_confirmation":
                raise Denied("not awaiting confirmation")
            intent: Intent = row["intent"]
            if now >= intent.expires_at:
                raise Denied("intent expired")
            if displayed != intent.meaningful_details():
                raise Denied("trusted display did not match canonical details")
            message = canonical({
                "domain": "IMAGINE-R07-F3-MOCK-CONFIRMATION-V1",
                "intent_id": intent.intent_id,
                "digest": intent.digest,
                "nonce": intent.nonce,
                "actor": intent.actor,
                "session": intent.session,
            })
            return hmac.new(self._confirmation_key, message, hashlib.sha256).hexdigest()

    def commit(self, *, intent_id: str, session: str, proof: str,
               now: int, proposed_details: dict[str, Any] | None = None,
               claims: dict[str, Any] | None = None) -> str:
        """Atomic only inside THIS Python lock, not across services or PG."""
        del claims
        with self._lock:
            row = self.intents.get(intent_id)
            if row is None:
                raise Denied("unknown intent")
            intent: Intent = row["intent"]
            if session != intent.session:
                raise Denied("session mismatch")
            if not isinstance(proof, str):
                raise Denied("invalid proof")
            if now >= intent.expires_at:
                row["state"] = "expired"
                raise Denied("intent expired")
            if proposed_details is not None and proposed_details != intent.meaningful_details():
                raise Denied("material details changed")
            expected_digest = hashlib.sha256(canonical(intent.payload())).hexdigest()
            if not hmac.compare_digest(intent.digest, expected_digest):
                raise Denied("intent changed")
            expected = self.simulated_independent_confirm(
                intent_id, intent.meaningful_details(), now=now
            ) if row["state"] == "awaiting_confirmation" else None
            # Repeated commit on the same intent uses an immutable retained
            # mock proof, but it cannot replay a different intent.
            if row["state"] == "committed":
                if not hmac.compare_digest(row["proof"], proof):
                    raise Denied("different replay proof")
                return "idempotent_replay"
            if row["state"] != "awaiting_confirmation":
                raise Denied("intent not pending")
            if expected is None or not hmac.compare_digest(expected, proof):
                raise Denied("wrong confirmation or substituted intent")
            actor, tenant, account, membership = self._resolve(
                session, intent.resource, intent.action
            )
            if (actor != intent.actor or tenant != intent.tenant
                or account["epoch"] != intent.session_epoch
                or membership["revision"] != intent.membership_revision
                or self.policy_revision != intent.policy_revision):
                raise Denied("authority revision changed")
            self.effects.append({
                "intent_id": intent.intent_id,
                "actor": actor,
                "tenant": tenant,
                "resource": intent.resource,
                "amount_minor": intent.amount_minor,
                "destination": intent.destination,
            })
            row["proof"] = proof
            row["state"] = "committed"
            return "committed"

    def revoke_account(self, actor: str) -> None:
        with self._lock:
            self.accounts[actor]["epoch"] += 1

    def suspend(self, actor: str) -> None:
        with self._lock:
            self.accounts[actor]["active"] = False

    def change_membership(self, actor: str, tenant: str,
                          *, active: bool = True, allow_action: bool = True) -> None:
        with self._lock:
            record = self.memberships[(actor, tenant)]
            record["active"] = active
            record["revision"] += 1
            record["capabilities"] = {VALID_ACTION} if allow_action else set()

    def change_policy(self) -> None:
        with self._lock:
            self.policy_revision += 1

    def reassign_resource(self, resource: str, tenant: str) -> None:
        with self._lock:
            self.resources[resource] = tenant
