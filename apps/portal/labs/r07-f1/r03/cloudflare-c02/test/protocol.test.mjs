import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {
  initialState, transition, verifyState, wouldAllowFixture,
  authorizesCustomerAccess, LabDenied,
} from "../src/protocol.mjs";
import { planEvidence, sha256Hex } from "../src/r2-evidence.mjs";

const A = "a".repeat(64);
const B = "b".repeat(64);
const ROOT = new URL("../", import.meta.url);
const labOpen = () => transition(initialState(), {
  type: "bootstrap-lab", fixture: "NON_OPERATIONAL_LAB",
});
const revocation = (s) => transition(s, {
  type: "revoke", expectedSequence: s.sequence, actor: "actor-a", org: "org-a",
});
const startRecovery = (s) => transition(s, {
  type: "begin-recovery", expectedSequence: s.sequence,
  snapshotDigest: A, manifestDigest: B,
});
const recordRestore = (s) => transition(s, {
  type: "record-restore", expectedSequence: s.sequence,
  generation: s.generation, snapshotDigest: A,
});
const ready = (s) => transition(s, {
  type: "mark-ready-lab",
  expectedSequence: s.sequence,
  fixture: "NON_OPERATIONAL_RECONCILIATION",
  evidence: { allSessionsInvalidated: true, revocationsReapplied: true },
});
const open = (s) => transition(s, {
  type: "open-lab", expectedSequence: s.sequence,
  fixture: "NON_OPERATIONAL_DIRECTOR_APPROVAL",
});
const denies = (fn, reason) => assert.throws(fn, (error) =>
  error instanceof LabDenied && error.reason === reason);

test("new Z4 starts fail-closed, no customer authorizer", () => {
  const s = initialState();
  assert.equal(s.mode, "DENY");
  assert.equal(s.generation, 0);
  assert.equal(s.sequence, 0);
  assert.equal(authorizesCustomerAccess(), false);
  assert.equal(wouldAllowFixture(s, "actor-a", "org-a", 0, 0), false);
});

test("non-operational bootstrap is explicit and single-use", () => {
  denies(() => transition(initialState(), { type: "bootstrap-lab" }), "bootstrap_denied");
  const s = labOpen();
  assert.equal(s.mode, "OPEN");
  assert.equal(s.sequence, 1);
  denies(() => transition(s, {
    type: "bootstrap-lab", fixture: "NON_OPERATIONAL_LAB",
  }), "bootstrap_denied");
});

test("revocation monotonic and idempotent only for lab", () => {
  const initial = labOpen();
  const s = revocation(initial);
  assert.deepEqual(s.revocations, [{ actor: "actor-a", org: "org-a" }]);
  assert.equal(s.sequence, initial.sequence + 1);
  assert.equal(initial.revocations.length, 0);
  assert.deepEqual(revocation(s), s);
  assert.equal(wouldAllowFixture(s, "actor-a", "org-a", 1, 1), false);
  assert.equal(wouldAllowFixture(s, "actor-b", "org-b", 1, 1), true);
  assert.equal(authorizesCustomerAccess(), false);
});

test("restoration takes CAS and increments generation plus global epoch", () => {
  const s = revocation(labOpen());
  const recover = startRecovery(s);
  assert.equal(recover.mode, "DENY");
  assert.equal(recover.generation, 2);
  assert.equal(recover.sessionEpoch, 2);
  assert.equal(recover.sequence, s.sequence + 1);
  assert.deepEqual(recover.revocations, s.revocations);
  assert.equal(recover.restoreReceipt, null);
  assert.equal(recover.reconciled, false);
  assert.equal(wouldAllowFixture(recover, "actor-b", "org-b", 2, 2), false);
  denies(() => transition(s, {
    type: "begin-recovery", expectedSequence: s.sequence - 1,
    snapshotDigest: A, manifestDigest: B,
  }), "stale_cas");
  denies(() => transition(recover, {
    type: "begin-recovery", expectedSequence: recover.sequence,
    snapshotDigest: A, manifestDigest: B,
  }), "recovery_denied");
});

test("restore receipt requires exact digest and generation", () => {
  const s = startRecovery(labOpen());
  denies(() => transition(s, {
    type: "record-restore", expectedSequence: s.sequence,
    generation: s.generation, snapshotDigest: B,
  }), "restore_receipt_denied");
  denies(() => transition(s, {
    type: "record-restore", expectedSequence: s.sequence,
    generation: 1, snapshotDigest: A,
  }), "restore_receipt_denied");
  const r = recordRestore(s);
  assert.deepEqual(r.restoreReceipt, { digest: A, generation: 2 });
});

test("can't mark READY without receipt or synthetic reconciliation evidence", () => {
  const s = startRecovery(labOpen());
  denies(() => ready(s), "reconciliation_denied");
  const restored = recordRestore(s);
  denies(() => transition(restored, {
    type: "mark-ready-lab", expectedSequence: restored.sequence,
    fixture: "NON_OPERATIONAL_RECONCILIATION",
    evidence: { allSessionsInvalidated: true, revocationsReapplied: false },
  }), "reconciliation_denied");
  const marked = ready(restored);
  assert.equal(marked.mode, "READY");
  assert.equal(marked.reconciled, true);
});

test("can't OPEN without a dedicated laboratory fixture and matching CAS", () => {
  const restored = recordRestore(startRecovery(labOpen()));
  const marked = ready(restored);
  denies(() => transition(marked, {
    type: "open-lab", expectedSequence: marked.sequence,
  }), "open_denied");
  const s = open(marked);
  assert.equal(s.mode, "OPEN");
  denies(() => transition(s, {
    type: "open-lab", expectedSequence: marked.sequence,
    fixture: "NON_OPERATIONAL_DIRECTOR_APPROVAL",
  }), "stale_cas");
  assert.equal(wouldAllowFixture(s, "actor-b", "org-b", 1, 1), false);
  assert.equal(wouldAllowFixture(s, "actor-b", "org-b", 2, 2), true);
  assert.equal(authorizesCustomerAccess(), false);
});

test("post-restore revoked actor cannot reappear via current lab state", () => {
  const s = open(ready(recordRestore(startRecovery(revocation(labOpen())))));
  assert.equal(wouldAllowFixture(s, "actor-a", "org-a", s.generation, s.sessionEpoch), false);
  assert.equal(wouldAllowFixture(s, "actor-b", "org-b", s.generation, s.sessionEpoch), true);
});

test("KNOWN GAP: stale Z4 copy reauthorizes revoked fixture (NOT a security pass)", () => {
  const staleZ4 = structuredClone(labOpen());
  const currentZ4 = revocation(staleZ4);
  assert.equal(wouldAllowFixture(currentZ4, "actor-a", "org-a", 1, 1), false);
  assert.equal(wouldAllowFixture(staleZ4, "actor-a", "org-a", 1, 1), true);
  assert.equal(authorizesCustomerAccess(), false);
});

test("fail-closed on corrupted/missing Z4 and unrecognized commands", () => {
  assert.equal(wouldAllowFixture(null, "actor-a", "org-a", 1, 1), false);
  assert.equal(wouldAllowFixture({ mode: "OPEN" }, "actor-a", "org-a", 1, 1), false);
  denies(() => verifyState(null), "corrupt_z4_state");
  denies(() => transition(labOpen(), { type: "do-production-payment" }), "unknown_lab_command");
  denies(() => transition(labOpen(), null), "invalid_lab_command");
});

test("blocking and exhaustion both fail closed", () => {
  const s = labOpen();
  const b = transition(s, { type: "block", expectedSequence: s.sequence });
  assert.equal(b.mode, "BLOCKED");
  assert.equal(wouldAllowFixture(b, "actor-a", "org-a", 1, 1), false);
  denies(() => startRecovery(b), "recovery_denied");
  const full = { ...s, sequence: Number.MAX_SAFE_INTEGER };
  denies(() => transition(full, { type: "block",
    expectedSequence: full.sequence }), "sequence_exhausted");
});

test("R2 evidence keys are deterministic and cryptographically check content bytes", async () => {
  const item = await planEvidence({
    installation: "imagine-lab", generation: 2, sequence: 10, kind: "revoke",
  });
  assert.equal(await sha256Hex(item.body), item.digest);
  assert.match(item.key, /^r03-c02-lab\/imagine-lab\/000000000002\/000000000010-revoke-[0-9a-f]{64}\.json$/);
  assert.equal(item.couldAuthorizeRecovery, false);
  assert.equal(JSON.parse(item.body).includesCustomerData, false);
  assert.deepEqual(item, await planEvidence({
    installation: "imagine-lab", generation: 2, sequence: 10, kind: "revoke",
  }));
});

test("R2 evidence chain is illustrative and does not assume retention lock", async () => {
  const first = await planEvidence({
    installation: "imagine-lab", generation: 2, sequence: 10, kind: "revoke",
  });
  const second = await planEvidence({
    installation: "imagine-lab", generation: 2, sequence: 11,
    kind: "restore-verification", previousDigest: first.digest,
  });
  assert.equal(JSON.parse(second.body).previousDigest, first.digest);
  assert.notEqual(second.digest, first.digest);
  assert.equal(second.couldAuthorizeRecovery, false);
  await assert.rejects(() => planEvidence({
    installation: "../unsafe", generation: 2, sequence: 11, kind: "open",
  }), TypeError);
  await assert.rejects(() => planEvidence({
    installation: "imagine-lab", generation: 2, sequence: 12, kind: "payment",
  }), TypeError);
});


test("missing or nonstring actor and organization cannot be coerced to IDs", async () => {
  const opened = labOpen();
  for (const missing of [
    { type: "revoke", expectedSequence: opened.sequence, actor: undefined, org: "org-a" },
    { type: "revoke", expectedSequence: opened.sequence, actor: "actor-a", org: undefined },
    { type: "revoke", expectedSequence: opened.sequence, actor: 55, org: "org-a" },
  ]) {
    denies(() => transition(opened, missing), "invalid_revocation");
  }
  assert.equal(wouldAllowFixture(opened, undefined, "org-a", 1, 1), false);
  assert.equal(wouldAllowFixture(opened, "actor-a", undefined, 1, 1), false);
  await assert.rejects(() => planEvidence({
    installation: undefined, generation: 2, sequence: 1, kind: "revoke",
  }), TypeError);
});

test("Cloudflare candidate configuration disables routes and all preview URLs", () => {
  const config = JSON.parse(
    readFileSync(new URL("../wrangler.jsonc", import.meta.url), "utf8")
      .replace(/^\s*\/\/.*$/gm, ""),
  );
  assert.equal(config.workers_dev, false);
  assert.equal(config.preview_urls, false);
  assert.deepEqual(config.routes, []);
  assert.deepEqual(config.triggers.crons, []);
  assert.equal(config.exports.SyntheticFence.type, "durable-object");
  assert.equal(config.exports.SyntheticFence.storage, "sqlite");
  assert.equal(config.durable_objects.bindings[0].class_name, "SyntheticFence");
  assert.equal(config.r2_buckets, undefined);
});

test("Worker adapter has no mutating RPC method or public API for the lab", () => {
  const source = readFileSync(new URL("../src/worker.mjs", import.meta.url), "utf8");
  assert.match(source, /fetch\(\) \{\s*return new Response\("Not found"/);
  assert.match(source, /labDiagnostics\(\)/);
  assert.doesNotMatch(source, /async\s+(beginRecovery|open|revoke|grant|deploy)\s*\(/);
  assert.doesNotMatch(source, /env\.R2|env\.PSP|env\.CRM/);
  assert.equal(authorizesCustomerAccess(), false);
});
