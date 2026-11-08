"""R07-F3 synthetic negative checks; not a penetration test or P0 sign-off."""
from concurrent.futures import ThreadPoolExecutor
import sys
from pathlib import Path
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent))
from intent_model import Authority, Denied, VALID_ACTION, canonical


class IntentLabTests(unittest.TestCase):
    def setUp(self):
        self.auth = Authority()
        self.now = 1000

    def issue(self, **updates):
        params = {
            "session": "lab-session-a",
            "resource": "billing-a",
            "action": VALID_ACTION,
            "amount_minor": 12345,
            "currency": "BRL",
            "destination": "account-a",
            "now": self.now,
        }
        params.update(updates)
        return self.auth.issue(**params)

    def approve(self, intent, shown=None, at=None):
        return self.auth.simulated_independent_confirm(
            intent.intent_id,
            intent.meaningful_details() if shown is None else shown,
            now=self.now if at is None else at,
        )

    def execute(self, intent, proof=None, **updates):
        params = {
            "intent_id": intent.intent_id,
            "session": "lab-session-a",
            "proof": self.approve(intent) if proof is None else proof,
            "now": self.now + 1,
        }
        params.update(updates)
        return self.auth.commit(**params)

    def test_01_authority_derives_actor_tenant_from_session_and_resource(self):
        intent = self.issue(claims={"actor": "actor-b", "tenant": "org-b",
                                    "role": "owner", "mfa": True})
        self.assertEqual(intent.actor, "actor-a")
        self.assertEqual(intent.tenant, "org-a")
        self.assertEqual(intent.resource, "billing-a")

    def test_02_cross_tenant_resource_cannot_issue_intent(self):
        with self.assertRaises(Denied):
            self.issue(resource="billing-b")
        self.assertEqual(self.auth.effects, [])

    def test_03_no_session_is_denied(self):
        with self.assertRaises(Denied):
            self.issue(session="crm-session-a")

    def test_04_noncanonical_amount_is_denied(self):
        for value in (True, 12.0, "123", 0, -1, 1000001):
            with self.subTest(value=value), self.assertRaises(Denied):
                self.issue(amount_minor=value)

    def test_05_noncanonical_currency_destination_denied(self):
        for overrides in ({"currency": "USD"},
                          {"destination": "x;DROP TABLE"},
                          {"destination": ""},
                          {"destination": "has unicode ç"}):
            with self.subTest(overrides=overrides), self.assertRaises(Denied):
                self.issue(**overrides)

    def test_06_canonical_digest_is_stable_and_version_bound(self):
        intent = self.issue()
        self.assertEqual(len(intent.digest), 64)
        self.assertEqual(intent.payload()["domain"], "IMAGINE-R07-F3-SYNTHETIC-INTENT-V1")
        self.assertEqual(canonical({"b": 2, "a": 1}), canonical({"a": 1, "b": 2}))

    def test_07_displayed_amount_change_is_rejected_by_mock_channel(self):
        intent = self.issue()
        shown = intent.meaningful_details()
        shown["amount_minor"] = 999
        with self.assertRaises(Denied):
            self.approve(intent, shown)

    def test_08_displayed_destination_change_is_rejected(self):
        intent = self.issue()
        shown = intent.meaningful_details()
        shown["destination"] = "attacker-account"
        with self.assertRaises(Denied):
            self.approve(intent, shown)

    def test_09_unsigned_proof_cannot_commit(self):
        intent = self.issue()
        with self.assertRaises(Denied):
            self.execute(intent, proof="0" * 64)
        self.assertEqual(self.auth.effects, [])

    def test_10_proof_bound_to_unique_intent(self):
        first, second = self.issue(), self.issue()
        proof = self.approve(first)
        with self.assertRaises(Denied):
            self.execute(second, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_11_material_details_cannot_change_during_commit(self):
        intent = self.issue()
        shown = intent.meaningful_details()
        shown["amount_minor"] += 1
        with self.assertRaises(Denied):
            self.execute(intent, proposed_details=shown)
        self.assertEqual(self.auth.effects, [])

    def test_12_session_substitution_denied(self):
        intent = self.issue()
        with self.assertRaises(Denied):
            self.execute(intent, session="lab-session-b")
        self.assertEqual(self.auth.effects, [])

    def test_13_expired_confirmation_cannot_execute(self):
        intent = self.issue()
        proof = self.approve(intent)
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof, now=intent.expires_at)
        self.assertEqual(self.auth.effects, [])

    def test_14_revoked_session_cannot_execute(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.auth.revoke_account("actor-a")
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_15_suspended_account_cannot_execute(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.auth.suspend("actor-a")
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_16_changed_membership_revision_denied(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.auth.change_membership("actor-a", "org-a", active=True, allow_action=True)
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_17_removed_capability_denied(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.auth.change_membership("actor-a", "org-a", allow_action=False)
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_18_changed_policy_revision_denied(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.auth.change_policy()
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_19_reassigned_resource_denied(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.auth.reassign_resource("billing-a", "org-b")
        with self.assertRaises(Denied):
            self.execute(intent, proof=proof)
        self.assertEqual(self.auth.effects, [])

    def test_20_allowed_effect_commits_once(self):
        intent = self.issue()
        self.assertEqual(self.execute(intent), "committed")
        self.assertEqual(len(self.auth.effects), 1)
        self.assertEqual(self.auth.effects[0]["tenant"], "org-a")
        self.assertEqual(self.auth.effects[0]["amount_minor"], 12345)

    def test_21_same_intent_replay_is_idempotent(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.assertEqual(self.execute(intent, proof=proof), "committed")
        self.assertEqual(self.execute(intent, proof=proof), "idempotent_replay")
        self.assertEqual(len(self.auth.effects), 1)

    def test_22_different_proof_replay_denied(self):
        intent = self.issue()
        proof = self.approve(intent)
        self.execute(intent, proof=proof)
        with self.assertRaises(Denied):
            self.execute(intent, proof="f" * 64)
        self.assertEqual(len(self.auth.effects), 1)

    def test_23_concurrent_same_intent_commits_once_in_memory(self):
        intent = self.issue()
        proof = self.approve(intent)
        def submit(_):
            return self.execute(intent, proof=proof)
        with ThreadPoolExecutor(max_workers=8) as pool:
            results = list(pool.map(submit, range(16)))
        self.assertEqual(results.count("committed"), 1)
        self.assertEqual(results.count("idempotent_replay"), 15)
        self.assertEqual(len(self.auth.effects), 1)

    def test_24_untrusted_claims_cannot_escalate_during_commit(self):
        intent = self.issue()
        result = self.execute(intent, claims={"actor": "actor-b",
                          "tenant": "org-b", "is_admin": True, "mfa": True})
        self.assertEqual(result, "committed")
        self.assertEqual(self.auth.effects[0]["tenant"], "org-a")
        self.assertEqual(self.auth.effects[0]["actor"], "actor-a")

    def test_25_known_residual_mock_not_real_user_consent(self):
        # Intentionally prove the LIMIT: if a hostile web/app obtains a
        # valid simulated confirmation channel, the prototype can commit.
        # This is NOT proof of consent independent of a hostile Z1.
        intent = self.issue()
        proof = self.approve(intent)
        self.assertEqual(self.execute(intent, proof=proof), "committed")
        print("[KNOWN RESIDUAL T3] Mock signer is not an independent human channel.")

    def test_26_unsupported_action_denied(self):
        with self.assertRaises(Denied):
            self.issue(action="grant_admin")
        self.assertEqual(self.auth.effects, [])

    def test_27_expired_intent_cannot_be_newly_approved(self):
        intent = self.issue()
        with self.assertRaises(Denied):
            self.approve(intent, at=intent.expires_at)
        self.assertEqual(self.auth.effects, [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
