/**
 * SEC-R03/C02: PURE, SYNTHETIC Z4 PROTOCOL. NEVER an authorization service.
 *
 * No I/O, no backend credentials, no integration with real client sessions.
 * The Cloudflare adapter does NOT expose transition() over HTTP or RPC.
 * Even an OPEN synthetic state does not authorize production access.
 */
const SHA256_HEX = /^[0-9a-f]{64}$/;
const IDENTIFIER = /^[a-z][a-z0-9-]{0,39}$/;
const MODES = new Set(["DENY", "READY", "OPEN", "BLOCKED"]);

export class LabDenied extends Error {
  constructor(reason) {
    super(reason);
    this.name = "LabDenied";
    this.reason = reason;
  }
}

const deny = (reason) => { throw new LabDenied(reason); };
export const isDigest = (value) => typeof value === "string" && SHA256_HEX.test(value);

export function initialState() {
  return {
    version: 1,
    generation: 0,
    sequence: 0,
    sessionEpoch: 0,
    mode: "DENY",
    snapshotDigest: null,
    manifestDigest: null,
    restoreReceipt: null,
    reconciled: false,
    revocations: [],
  };
}

export function verifyState(state) {
  if (!state || typeof state !== "object" || Array.isArray(state)
    || state.version !== 1
    || !Number.isSafeInteger(state.generation) || state.generation < 0
    || !Number.isSafeInteger(state.sequence) || state.sequence < 0
    || !Number.isSafeInteger(state.sessionEpoch) || state.sessionEpoch < 0
    || !MODES.has(state.mode) || !Array.isArray(state.revocations)
    || state.revocations.length > 500
    || typeof state.reconciled !== "boolean"
    || !(state.snapshotDigest === null || isDigest(state.snapshotDigest))
    || !(state.manifestDigest === null || isDigest(state.manifestDigest))
    || !(state.restoreReceipt === null || (
      typeof state.restoreReceipt === "object"
      && state.restoreReceipt.digest === state.snapshotDigest
      && state.restoreReceipt.generation === state.generation
    ))
    || state.revocations.some((r) => !r || !IDENTIFIER.test(r.actor)
      || !IDENTIFIER.test(r.org))) {
    deny("corrupt_z4_state");
  }
  return state;
}

function step(state) {
  if (state.sequence === Number.MAX_SAFE_INTEGER) deny("sequence_exhausted");
  return state.sequence + 1;
}
function checkSeq(state, expectedSequence) {
  if (!Number.isSafeInteger(expectedSequence) || expectedSequence !== state.sequence) {
    deny("stale_cas");
  }
}
function copy(state) {
  return structuredClone(state);
}

/**
 * Evaluate one **laboratory** state transition. The caller is assumed to be
 * a trusted test fixture, not an authenticated operator or a real Worker.
 * Real Z4 requires externally durable CAS + independent approval/attestation.
 */
export function transition(current, command) {
  verifyState(current);
  if (!command || typeof command !== "object" || Array.isArray(command)
    || typeof command.type !== "string") deny("invalid_lab_command");
  const next = copy(current);
  switch (command.type) {
    case "bootstrap-lab": {
      if (current.sequence !== 0 || current.generation !== 0
        || current.mode !== "DENY" || command.fixture !== "NON_OPERATIONAL_LAB") {
        deny("bootstrap_denied");
      }
      next.generation = 1;
      next.sequence = step(current);
      next.sessionEpoch = 1;
      next.mode = "OPEN";
      return next;
    }
    case "revoke": {
      checkSeq(current, command.expectedSequence);
      if (current.mode !== "OPEN" || !IDENTIFIER.test(command.actor)
        || !IDENTIFIER.test(command.org)) deny("invalid_revocation");
      if (current.revocations.some((r) => r.actor === command.actor && r.org === command.org)) {
        return next; // idempotent within the synthetic state
      }
      if (next.revocations.length >= 500) deny("lab_capacity_exceeded");
      next.sequence = step(current);
      next.revocations.push({ actor: command.actor, org: command.org });
      return next;
    }
    case "begin-recovery": {
      checkSeq(current, command.expectedSequence);
      if (current.mode !== "OPEN" || !isDigest(command.snapshotDigest)
        || !isDigest(command.manifestDigest)
        || current.generation >= Number.MAX_SAFE_INTEGER
        || current.sessionEpoch >= Number.MAX_SAFE_INTEGER) deny("recovery_denied");
      next.generation += 1;
      next.sessionEpoch += 1;
      next.sequence = step(current);
      next.mode = "DENY";
      next.snapshotDigest = command.snapshotDigest;
      next.manifestDigest = command.manifestDigest;
      next.restoreReceipt = null;
      next.reconciled = false;
      return next;
    }
    case "record-restore": {
      checkSeq(current, command.expectedSequence);
      if (current.mode !== "DENY" || command.generation !== current.generation
        || !isDigest(current.snapshotDigest)
        || command.snapshotDigest !== current.snapshotDigest) deny("restore_receipt_denied");
      next.sequence = step(current);
      next.restoreReceipt = {
        digest: current.snapshotDigest,
        generation: current.generation,
      };
      return next;
    }
    case "mark-ready-lab": {
      checkSeq(current, command.expectedSequence);
      if (current.mode !== "DENY" || !current.restoreReceipt || !current.manifestDigest
        || command.fixture !== "NON_OPERATIONAL_RECONCILIATION"
        || command.evidence?.allSessionsInvalidated !== true
        || command.evidence?.revocationsReapplied !== true) {
        deny("reconciliation_denied");
      }
      // Evidence is caller-supplied in a SIMULATOR. Cannot trust in prod.
      next.sequence = step(current);
      next.mode = "READY";
      next.reconciled = true;
      return next;
    }
    case "open-lab": {
      checkSeq(current, command.expectedSequence);
      if (current.mode !== "READY" || !current.reconciled
        || !current.restoreReceipt
        || command.fixture !== "NON_OPERATIONAL_DIRECTOR_APPROVAL") {
        deny("open_denied");
      }
      next.sequence = step(current);
      next.mode = "OPEN";
      return next;
    }
    case "block": {
      checkSeq(current, command.expectedSequence);
      next.sequence = step(current);
      next.mode = "BLOCKED";
      return next;
    }
    default:
      deny("unknown_lab_command");
  }
}

/** This is an illustrative comparison of *untrusted fixture IDs*, NOT IAM. */
export function wouldAllowFixture(state, actor, org, generation, epoch) {
  try {
    verifyState(state);
  } catch {
    return false;
  }
  return state.mode === "OPEN"
    && state.generation === generation
    && state.sessionEpoch === epoch
    && IDENTIFIER.test(actor)
    && IDENTIFIER.test(org)
    && !state.revocations.some((r) => r.actor === actor && r.org === org);
}

/** Production use of this laboratory protocol is always denied. */
export function authorizesCustomerAccess() {
  return false;
}
