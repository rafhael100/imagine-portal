/**
 * SEC-R03-C03: IN-MEMORY, NON-OPERATIONAL independent witness experiment.
 *
 * A witness must live OUTSIDE the whole Z4 restore/admin failure domain.
 * This module does NOT implement independent durability, IAM, split-brain
 * prevention, human approval or distributed commit. It has NO I/O or RPC.
 * Never plug it into real customer authorization.
 */
import { verifyState, transition, authorizesCustomerAccess } from "./protocol.mjs";
import { sha256Hex } from "./r2-evidence.mjs";

const DIGEST = /^[a-f0-9]{64}$/;
const deny = (reason) => { throw new Error(reason); };

function snapshot(state) {
  verifyState(state);
  const revocations = state.revocations.map((r) => ({
    actor: r.actor, org: r.org,
  })).sort((a, b) => a.actor.localeCompare(b.actor) || a.org.localeCompare(b.org));
  // Fixed keys and order avoid relying on incidental user object properties.
  return {
    version: state.version,
    generation: state.generation,
    sequence: state.sequence,
    sessionEpoch: state.sessionEpoch,
    mode: state.mode,
    snapshotDigest: state.snapshotDigest,
    manifestDigest: state.manifestDigest,
    restoreReceipt: state.restoreReceipt === null
      ? null
      : {
          digest: state.restoreReceipt.digest,
          generation: state.restoreReceipt.generation,
        },
    reconciled: state.reconciled,
    revocations,
  };
}

export async function digestFixtureZ4(state) {
  return sha256Hex(JSON.stringify(snapshot(state)));
}

function validWitness(witness) {
  return witness
    && typeof witness === "object"
    && witness.version === 1
    && Number.isSafeInteger(witness.generation)
    && witness.generation >= 0
    && Number.isSafeInteger(witness.sequence)
    && witness.sequence >= 0
    && typeof witness.stateDigest === "string"
    && DIGEST.test(witness.stateDigest);
}

/** A deliberately separate memory object, NOT a cloud ledger. */
export class SyntheticIndependentWitness {
  #value = null;
  #available = true;

  setAvailableInTest(value) {
    this.#available = value === true;
  }

  async observeInitial(state) {
    if (!this.#available) deny("witness_offline");
    if (this.#value !== null) deny("witness_already_initialized");
    verifyState(state);
    if (state.generation !== 0 || state.sequence !== 0 || state.mode !== "DENY") {
      deny("must_start_denied");
    }
    this.#value = Object.freeze({
      version: 1,
      generation: state.generation,
      sequence: state.sequence,
      stateDigest: await digestFixtureZ4(state),
    });
    return this.read();
  }

  read() {
    if (!this.#available || this.#value === null) return null;
    return Object.freeze({ ...this.#value });
  }

  /**
   * A single-process compare-and-swap simulation.
   * Real remote CAS, durable quorum, lock/lease and credential segregation
   * are NOT tested here.
   */
  async observeNext(previousState, nextState, expectedSequence, command) {
    if (!this.#available || this.#value === null) deny("witness_offline");
    verifyState(previousState);
    verifyState(nextState);
    const last = this.#value;
    if (!Number.isSafeInteger(expectedSequence)
      || expectedSequence !== last.sequence
      || previousState.sequence !== last.sequence
      || previousState.generation !== last.generation) {
      deny("witness_stale_cas");
    }
    const previousDigest = await digestFixtureZ4(previousState);
    if (this.#value !== last || previousDigest !== last.stateDigest) {
      deny("witness_stale_cas");
    }
    // The witness validates a lab state-machine transition, not an arbitrary
    // prebuilt OPEN snapshot. The command/fixtures are STILL untrusted for
    // any actual production decision.
    let recomputed;
    try {
      recomputed = transition(previousState, command);
    } catch {
      deny("witness_invalid_transition");
    }
    if (nextState.sequence !== last.sequence + 1
      || nextState.generation < last.generation
      || nextState.generation > last.generation + 1
      || nextState.sessionEpoch < previousState.sessionEpoch) {
      deny("witness_nonmonotonic");
    }
    const [nextDigest, recomputedDigest] = await Promise.all([
      digestFixtureZ4(nextState), digestFixtureZ4(recomputed),
    ]);
    if (nextDigest !== recomputedDigest) deny("witness_invalid_transition");
    // The final check and assignment contain NO await. In this single JS
    // isolate this is a toy CAS; it is NOT durable/distributed atomicity.
    if (!this.#available || this.#value !== last) deny("witness_stale_cas");
    this.#value = Object.freeze({
      version: 1,
      generation: nextState.generation,
      sequence: nextState.sequence,
      stateDigest: nextDigest,
    });
    return this.read();
  }
}

/**
 * In-memory comparison: even a matched lab witness is NOT authenticated
 * externally and NEVER authorizes real customer reads, grants, OPEN or writes.
 *
 * Simulated full-host rollback of both Z4 and witness is a KNOWN GAP:
 * those old snapshots can match without an outside monotonic anchor.
 */
export async function evaluateFixtureWitness(state, witness) {
  const denied = (reason) => ({
    matchesWitnessInSimulation: false,
    reason,
    canAuthorizeCustomers: false,
    canApproveRecovery: false,
  });
  if (!validWitness(witness)) return denied("witness_missing_or_invalid");
  try {
    verifyState(state);
  } catch {
    return denied("z4_missing_or_invalid");
  }
  if (state.sequence !== witness.sequence
    || state.generation !== witness.generation
    || await digestFixtureZ4(state) !== witness.stateDigest) {
    return denied("stale_or_divergent_z4");
  }
  return {
    matchesWitnessInSimulation: true,
    reason: "laboratory_state_matches",
    canAuthorizeCustomers: authorizesCustomerAccess(),
    canApproveRecovery: false,
  };
}
