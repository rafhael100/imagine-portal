import test from "node:test";
import assert from "node:assert/strict";

import {
  initialState, transition, authorizesCustomerAccess, wouldAllowFixture,
} from "../src/protocol.mjs";
import {
  SyntheticIndependentWitness, digestFixtureZ4, evaluateFixtureWitness,
} from "../src/c03-witness-model.mjs";

const HEX_A = "a".repeat(64);
const HEX_B = "b".repeat(64);
const INITIAL = () => initialState();
const BOOT = { type: "bootstrap-lab", fixture: "NON_OPERATIONAL_LAB" };

async function prepare() {
  const witness = new SyntheticIndependentWitness();
  const before = INITIAL();
  await witness.observeInitial(before);
  const after = transition(before, BOOT);
  await witness.observeNext(before, after, before.sequence, BOOT);
  return { witness, current: after, before };
}
async function revoke(witness, current) {
  const command = {
    type: "revoke", expectedSequence: current.sequence, actor: "actor-a", org: "org-a",
  };
  const after = transition(current, command);
  await witness.observeNext(current, after, current.sequence, command);
  return after;
}
async function evaluate(state, witness) {
  return evaluateFixtureWitness(state, witness.read());
}

test("a new witness is not initialized and cannot authorize synthetic or real access", async () => {
  const witness = new SyntheticIndependentWitness();
  const denied = await evaluateFixtureWitness(INITIAL(), witness.read());
  assert.equal(denied.matchesWitnessInSimulation, false);
  assert.equal(denied.reason, "witness_missing_or_invalid");
  assert.equal(denied.canAuthorizeCustomers, false);
  assert.equal(denied.canApproveRecovery, false);
  assert.equal(authorizesCustomerAccess(), false);
});

test("witness starts with exactly one DENY zero-state, never OPEN", async () => {
  const witness = new SyntheticIndependentWitness();
  await assert.rejects(() => witness.observeInitial(transition(INITIAL(), BOOT)),
    /must_start_denied/);
  const initial = INITIAL();
  const saved = await witness.observeInitial(initial);
  assert.equal(saved.generation, 0);
  assert.equal(saved.sequence, 0);
  assert.match(saved.stateDigest, /^[a-f0-9]{64}$/);
  await assert.rejects(() => witness.observeInitial(initial), /witness_already_initialized/);
  const matched = await evaluate(initial, witness);
  assert.equal(matched.matchesWitnessInSimulation, true);
  assert.equal(matched.canAuthorizeCustomers, false);
});

test("matching an OPEN lab snapshot is never a grant, business approval or production login", async () => {
  const { witness, current } = await prepare();
  assert.equal(wouldAllowFixture(current, "actor-a", "org-a", 1, 1), true);
  const result = await evaluate(current, witness);
  assert.equal(result.matchesWitnessInSimulation, true);
  assert.equal(result.canAuthorizeCustomers, false);
  assert.equal(result.canApproveRecovery, false);
  assert.equal(authorizesCustomerAccess(), false);
});

test("witness rejects an old Z4 snapshot after revocation, including restored old sessions", async () => {
  const { witness, current } = await prepare();
  const oldZ4 = structuredClone(current);
  const latest = await revoke(witness, current);
  assert.equal(wouldAllowFixture(oldZ4, "actor-a", "org-a", 1, 1), true);
  assert.equal(wouldAllowFixture(latest, "actor-a", "org-a", 1, 1), false);
  const rejected = await evaluate(oldZ4, witness);
  assert.equal(rejected.reason, "stale_or_divergent_z4");
  assert.equal(rejected.matchesWitnessInSimulation, false);
  assert.equal(rejected.canAuthorizeCustomers, false);
  assert.equal((await evaluate(latest, witness)).matchesWitnessInSimulation, true);
});

test("witness rejects a stale witness returned with a new Z4 snapshot", async () => {
  const { witness, current } = await prepare();
  const staleWitness = witness.read();
  const latest = await revoke(witness, current);
  const result = await evaluateFixtureWitness(latest, staleWitness);
  assert.equal(result.reason, "stale_or_divergent_z4");
  assert.equal(result.canAuthorizeCustomers, false);
});

test("snapshot digest changes on material authorization differences, not object key order", async () => {
  const { current } = await prepare();
  const reordered = {
    revocations: current.revocations,
    reconciled: current.reconciled,
    manifestDigest: current.manifestDigest,
    ...current,
  };
  assert.equal(await digestFixtureZ4(reordered), await digestFixtureZ4(current));
  const forged = structuredClone(current);
  forged.sessionEpoch += 1;
  assert.notEqual(await digestFixtureZ4(forged), await digestFixtureZ4(current));
});

test("manual permission restoration cannot be witnessed as a valid transition", async () => {
  const { witness, current } = await prepare();
  const latest = await revoke(witness, current);
  const command = { type: "block", expectedSequence: latest.sequence };
  const valid = transition(latest, command);
  const forged = structuredClone(valid);
  forged.revocations = [];
  await assert.rejects(
    () => witness.observeNext(latest, forged, latest.sequence, command),
    /witness_invalid_transition/,
  );
  assert.equal((await evaluate(latest, witness)).matchesWitnessInSimulation, true);
  assert.equal((await evaluate(forged, witness)).matchesWitnessInSimulation, false);
});

test("changing OPEN or revocations without an observed CAS creates a mismatched digest", async () => {
  const { witness, current } = await prepare();
  const modified = structuredClone(current);
  modified.mode = "BLOCKED";
  assert.equal((await evaluate(modified, witness)).reason, "stale_or_divergent_z4");
  modified.mode = "OPEN";
  modified.revocations.push({ actor: "actor-a", org: "org-a" });
  assert.equal((await evaluate(modified, witness)).reason, "stale_or_divergent_z4");
  assert.equal((await evaluate(current, witness)).matchesWitnessInSimulation, true);
});

test("witness outage causes fail-closed comparison, not fallback to locally valid Z4", async () => {
  const { witness, current } = await prepare();
  witness.setAvailableInTest(false);
  assert.equal(witness.read(), null);
  const result = await evaluate(current, witness);
  assert.equal(result.matchesWitnessInSimulation, false);
  assert.equal(result.canAuthorizeCustomers, false);
  const cmd = { type: "block", expectedSequence: current.sequence };
  await assert.rejects(
    () => witness.observeNext(current, transition(current, cmd), current.sequence, cmd),
    /witness_offline/,
  );
  witness.setAvailableInTest(true);
  assert.equal((await evaluate(current, witness)).matchesWitnessInSimulation, true);
});

test("stale CAS cannot overwrite newest witness sequence", async () => {
  const { witness, current } = await prepare();
  const command = { type: "block", expectedSequence: current.sequence };
  const next = transition(current, command);
  await witness.observeNext(current, next, current.sequence, command);
  await assert.rejects(
    () => witness.observeNext(current, next, current.sequence, command),
    /witness_stale_cas/,
  );
  assert.equal(witness.read().sequence, next.sequence);
});

test("two local concurrent CAS attempts have one winner and no last-writer-wins", async () => {
  const { witness, current } = await prepare();
  const command = { type: "block", expectedSequence: current.sequence };
  const next = transition(current, command);
  const outcome = await Promise.allSettled([
    witness.observeNext(current, next, current.sequence, command),
    witness.observeNext(current, next, current.sequence, command),
  ]);
  assert.equal(outcome.filter((o) => o.status === "fulfilled").length, 1);
  assert.equal(outcome.filter((o) => o.status === "rejected").length, 1);
  assert.equal((await evaluate(next, witness)).matchesWitnessInSimulation, true);
});

test("simulated crash between Z4 and witness writes fails closed in both directions", async () => {
  const { witness, current } = await prepare();
  const command = { type: "block", expectedSequence: current.sequence };
  const changedZ4 = transition(current, command);
  // DB state changed before witness: old witness sees the divergence.
  assert.equal((await evaluate(changedZ4, witness)).reason, "stale_or_divergent_z4");
  // Witness advanced before DB state: old DB is rejected.
  await witness.observeNext(current, changedZ4, current.sequence, command);
  assert.equal((await evaluate(current, witness)).reason, "stale_or_divergent_z4");
  assert.equal((await evaluate(changedZ4, witness)).matchesWitnessInSimulation, true);
});

test("witness enforces state-machine transition and refuses fabricated recovery OPEN", async () => {
  const { witness, current } = await prepare();
  const invalidCommand = {
    type: "open-lab",
    expectedSequence: current.sequence,
    fixture: "NON_OPERATIONAL_DIRECTOR_APPROVAL",
  };
  const forged = structuredClone(current);
  forged.sequence += 1;
  await assert.rejects(
    () => witness.observeNext(current, forged, current.sequence, invalidCommand),
    /witness_invalid_transition/,
  );
  assert.equal(witness.read().sequence, current.sequence);
});

test("tampered or malformed witness and missing Z4 never yield customer authorization", async () => {
  const { witness, current } = await prepare();
  for (const bogus of [
    null, {}, { ...witness.read(), stateDigest: "bad" },
    { ...witness.read(), sequence: -1 },
    { ...witness.read(), stateDigest: HEX_A },
  ]) {
    const result = await evaluateFixtureWitness(current, bogus);
    assert.equal(result.matchesWitnessInSimulation, false);
    assert.equal(result.canAuthorizeCustomers, false);
  }
  const badState = await evaluateFixtureWitness(null, witness.read());
  assert.equal(badState.reason, "z4_missing_or_invalid");
  assert.equal(badState.canAuthorizeCustomers, false);
});

test("KNOWN GAP: rollback of BOTH Z4 and its witness still passes a purely local comparison", async () => {
  const { witness, current } = await prepare();
  const staleAuthority = structuredClone(current);
  const staleWitness = witness.read();
  await revoke(witness, current);
  assert.equal((await evaluate(staleAuthority, witness)).matchesWitnessInSimulation, false);
  const incorrectlyMatched = await evaluateFixtureWitness(staleAuthority, staleWitness);
  // This is deliberately an observed VULNERABILITY, not an anti-rollback PASS.
  assert.equal(incorrectlyMatched.matchesWitnessInSimulation, true);
  assert.equal(incorrectlyMatched.reason, "laboratory_state_matches");
  assert.equal(incorrectlyMatched.canAuthorizeCustomers, false);
  assert.equal(incorrectlyMatched.canApproveRecovery, false);
});

test("witness records new generation and global epoch after a simulated recovery cut", async () => {
  const { witness, current } = await prepare();
  const command = {
    type: "begin-recovery",
    expectedSequence: current.sequence,
    snapshotDigest: HEX_A,
    manifestDigest: HEX_B,
  };
  const next = transition(current, command);
  const saved = await witness.observeNext(current, next, current.sequence, command);
  assert.equal(saved.generation, 2);
  assert.equal(next.sessionEpoch, 2);
  assert.equal(next.mode, "DENY");
  assert.equal((await evaluate(current, witness)).matchesWitnessInSimulation, false);
  assert.equal((await evaluate(next, witness)).matchesWitnessInSimulation, true);
  assert.equal((await evaluate(next, witness)).canAuthorizeCustomers, false);
});
