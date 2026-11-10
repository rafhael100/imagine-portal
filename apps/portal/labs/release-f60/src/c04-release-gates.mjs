/**
 * PORTAL SEC-R03-C04: a CLOSED RELEASE CHECKLIST for a non-operational lab.
 * It does not certify security, validate independent human approval, inspect
 * Cloudflare billing, or grant permissions. A zero-exit CI run explicitly
 * means that all operational gates remain BLOCKED/NOT_RUN, not "go live".
 */
import { readFileSync } from "node:fs";

const X = Array.from({ length: 24 }, (_, i) =>
  "SEC-R03-X" + String(i + 1).padStart(2, "0"));
const EXTRA = [
  "P0-R01-01", "P0-R01-02", "P0-R01-03", "P0-R01-09", "P0-R01-10",
  "G-ID", "G-BB", "D02-SEC", "D03-RLS", "T3", "APPSEC-DBA",
  "EXTERNAL-Z4", "BILLING-ZERO-GATE", "RELEASE-OPEN",
];
const EXPECTED = Object.freeze([...X, ...EXTRA]);
const BOOLEAN_DENIALS = Object.freeze([
  "productionAuthorization", "publishAuthorized", "credentialAuthorization",
  "serverResourcesCreated", "cloudflareAccountReviewed",
  "cloudflareSpendCapVerified", "z4DurableOutsideRollbackDomain",
  "recoveryOpenHumanApproval",
]);
const LAB_PROOF_TYPES = new Set([
  "synthetic_model_not_security_approval",
  "local_workerd_simulator_not_deploy",
  "stale_z4_and_joint_rollback_known_gaps",
]);
const FAILURES = Object.freeze([
  "Z4_AND_WITNESS_ROLLBACK_TOGETHER",
  "RESTORABLE_Z4_WITHOUT_MONOTONIC_ANCHOR",
  "UNLOCKED_R2_EVIDENCE_OVERWRITE",
]);

export class LabGateError extends Error {
  constructor(code) {
    super(code);
    this.name = "LabGateError";
    this.code = code;
  }
}
const reject = (code) => { throw new LabGateError(code); };
const object = (x) => x !== null && typeof x === "object" && !Array.isArray(x);
const SAME_IDS = (a, b) =>
  a.length === b.length && a.every((x, i) => x === b[i]);

export function parseLabManifest(source) {
  let manifest;
  try {
    manifest = JSON.parse(source);
  } catch {
    reject("invalid_json");
  }
  return checkLabOnlyManifest(manifest);
}

export function checkLabOnlyManifest(manifest) {
  if (!object(manifest)
    || manifest.schema !== "imagine.portal.sec-r03-c04.operational-gates.v1"
    || manifest.environment !== "NON_OPERATIONAL_LAB"
    || manifest.assertedOn !== "2026-11-09"
    || typeof manifest.notes !== "string"
    || !manifest.notes.includes("MUST NOT promote")) {
    reject("invalid_scope");
  }
  for (const key of BOOLEAN_DENIALS) {
    if (manifest[key] !== false) reject("unexpected_operational_permission");
  }
  if (!Array.isArray(manifest.gateRecords)
    || !SAME_IDS(manifest.gateRecords.map((gate) => gate?.id), EXPECTED)) {
    reject("incomplete_or_reordered_critical_gates");
  }
  for (const gate of manifest.gateRecords) {
    if (!object(gate)
      || gate.status !== (gate.id.startsWith("SEC-R03-X") ? "NOT_RUN" : "BLOCKED")
      || !Array.isArray(gate.evidence) || gate.evidence.length !== 0
      || gate.reviewer !== null) {
      reject("unreviewed_operational_promotion");
    }
  }
  if (!Array.isArray(manifest.syntheticEvidence)
    || manifest.syntheticEvidence.length !== 3
    || manifest.syntheticEvidence.some((entry) =>
      !object(entry)
      || !LAB_PROOF_TYPES.has(entry.kind)
      || typeof entry.ref !== "string"
      || !/^https:\/\/github\.com\/rafhael100\/imagine-portal\/pull\/(?:116|120|124)$/.test(entry.ref))) {
    reject("invalid_lab_proof_reference");
  }
  return Object.freeze({
    labChecklistValid: true,
    operationalGates: EXPECTED.length,
    notRun: X.length,
    blocked: EXTRA.length,
    productionReady: false,
    mayDeploy: false,
    mayMergeMain: false,
    mayUseCustomerData: false,
    mayOpenRecovery: false,
    residualKnownGaps: FAILURES,
    meaning: "CI PASS means operational security evidence remains INCOMPLETE.",
  });
}

export function checkCloudflareNotPublished(configText) {
  // This lightweight audit is NOT a Wrangler validator or account inventory.
  let config;
  try {
    config = JSON.parse(configText.replace(/^\s*\/\/.*$/gm, ""));
  } catch {
    reject("invalid_candidate_configuration");
  }
  if (!object(config)
    || config.workers_dev !== false
    || config.preview_urls !== false
    || !Array.isArray(config.routes) || config.routes.length !== 0
    || !object(config.triggers)
    || !Array.isArray(config.triggers.crons) || config.triggers.crons.length !== 0
    || config.r2_buckets !== undefined
    || !object(config.exports)
    || config.exports.SyntheticFence?.storage !== "sqlite"
    || config.exports.SyntheticFence?.type !== "durable-object"
    || config.name !== "imagine-portal-r03-c02-local-only") {
    reject("cloudflare_exposure_or_resource_change");
  }
  return Object.freeze({
    candidateConfigurationInert: true,
    providerAccountVerified: false,
    mayDeploy: false,
  });
}

/** Explicitly refuses both public and private deployment and real recovery. */
export function requestOperationalAction(_manifest, action) {
  if (typeof action !== "string" || action.length === 0) {
    reject("invalid_action");
  }
  reject("operational_action_not_authorized");
}

export function inspectCurrentLab() {
  const root = new URL("../", import.meta.url);
  const manifest = parseLabManifest(readFileSync(
    new URL("../C04-OPERATIONAL-GATES.json", import.meta.url), "utf8"));
  const config = checkCloudflareNotPublished(readFileSync(
    new URL("../wrangler.jsonc", import.meta.url), "utf8"));
  return Object.freeze({ manifest, config, source: root.href });
}

if (process.argv[1] && import.meta.url === new URL(
  "file://" + process.argv[1],
).href) {
  const arg = process.argv[2] || "--check-lab";
  if (arg !== "--check-lab") {
    console.error("DENY: this laboratory has no operational authorization.");
    process.exitCode = 2;
  } else {
    const result = inspectCurrentLab();
    console.log(JSON.stringify(result, null, 2));
    console.log("LAB ONLY: PASS = operational release remains BLOCKED.");
  }
}
