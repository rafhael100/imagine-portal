import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import {
  checkLabOnlyManifest, checkCloudflareNotPublished, inspectCurrentLab,
  parseLabManifest, requestOperationalAction, LabGateError,
} from "../src/c04-release-gates.mjs";

const file = new URL("../C04-OPERATIONAL-GATES.json", import.meta.url);
const configFile = new URL("../wrangler.jsonc", import.meta.url);
const manifest = JSON.parse(readFileSync(file, "utf8"));
const config = readFileSync(configFile, "utf8");
const mutate = () => structuredClone(manifest);
const rejected = (fn, code) => assert.throws(fn, (e) =>
  e instanceof LabGateError && e.code === code);

test("baseline is valid only as a strictly non-operational release BLOCKED checklist", () => {
  const result = checkLabOnlyManifest(manifest);
  assert.equal(result.labChecklistValid, true);
  assert.equal(result.operationalGates, 38);
  assert.equal(result.notRun, 24);
  assert.equal(result.blocked, 14);
  for (const property of [
    "productionReady", "mayDeploy", "mayMergeMain",
    "mayUseCustomerData", "mayOpenRecovery",
  ]) assert.equal(result[property], false);
  assert.equal(result.residualKnownGaps.length, 3);
  assert.match(result.meaning, /INCOMPLETE/);
  assert.equal(inspectCurrentLab().config.mayDeploy, false);
});

test("any attempt to promote an operational security gate to PASS fails closed", () => {
  for (const id of ["SEC-R03-X01", "SEC-R03-X22", "P0-R01-10", "G-ID", "RELEASE-OPEN"]) {
    const bad = mutate();
    bad.gateRecords.find((record) => record.id === id).status = "PASS";
    rejected(() => checkLabOnlyManifest(bad), "unreviewed_operational_promotion");
  }
});

test("self-described review, evidence or partial approval does not bypass no-go", () => {
  const bad = mutate();
  bad.gateRecords[0].reviewer = "fake-reviewer";
  rejected(() => checkLabOnlyManifest(bad), "unreviewed_operational_promotion");
  bad.gateRecords[0].reviewer = null;
  bad.gateRecords[0].evidence = ["https://example.invalid/lab-fake-pass"];
  rejected(() => checkLabOnlyManifest(bad), "unreviewed_operational_promotion");
});

test("missing, reordered, duplicated or invented gates cannot be silently hidden", () => {
  const a = mutate();
  a.gateRecords.pop();
  rejected(() => checkLabOnlyManifest(a), "incomplete_or_reordered_critical_gates");
  const b = mutate();
  [b.gateRecords[0], b.gateRecords[1]] = [b.gateRecords[1], b.gateRecords[0]];
  rejected(() => checkLabOnlyManifest(b), "incomplete_or_reordered_critical_gates");
  const c = mutate();
  c.gateRecords[1].id = c.gateRecords[0].id;
  rejected(() => checkLabOnlyManifest(c), "incomplete_or_reordered_critical_gates");
  const d = mutate();
  d.gateRecords.push({ id: "CLOUD-BILLING-ALREADY-APPROVED", status: "PASS" });
  rejected(() => checkLabOnlyManifest(d), "incomplete_or_reordered_critical_gates");
});

test("no declarative property may assert actual account access, spending or deployment", () => {
  const permissions = [
    "productionAuthorization", "publishAuthorized", "credentialAuthorization",
    "serverResourcesCreated", "cloudflareAccountReviewed",
    "cloudflareSpendCapVerified", "z4DurableOutsideRollbackDomain",
    "recoveryOpenHumanApproval",
  ];
  for (const permission of permissions) {
    const bad = mutate();
    bad[permission] = true;
    rejected(() => checkLabOnlyManifest(bad), "unexpected_operational_permission");
  }
});

test("synthetic CI references cannot be used as a product certificate", () => {
  const bad = mutate();
  bad.syntheticEvidence[0].kind = "external_z4_production_approved";
  rejected(() => checkLabOnlyManifest(bad), "invalid_lab_proof_reference");
  const badUrl = mutate();
  badUrl.syntheticEvidence[0].ref = "https://example.com/release-ready";
  rejected(() => checkLabOnlyManifest(badUrl), "invalid_lab_proof_reference");
});

test("malformed document, unknown schema and production scope are refused", () => {
  rejected(() => parseLabManifest("{"), "invalid_json");
  const prod = mutate();
  prod.environment = "PRODUCTION";
  rejected(() => checkLabOnlyManifest(prod), "invalid_scope");
  const unknown = mutate();
  unknown.schema = "UNREVIEWED-V2";
  rejected(() => checkLabOnlyManifest(unknown), "invalid_scope");
});

test("Cloudflare candidate contains no route, URL, cron or real R2 bucket binding", () => {
  const current = checkCloudflareNotPublished(config);
  assert.equal(current.candidateConfigurationInert, true);
  assert.equal(current.providerAccountVerified, false);
  assert.equal(current.mayDeploy, false);
});

test("public workers.dev, preview URL, route, cron and R2 resource changes fail CI", () => {
  const json = JSON.parse(config.replace(/^\s*\/\/.*$/gm, ""));
  const variants = [
    (c) => { c.workers_dev = true; },
    (c) => { c.preview_urls = true; },
    (c) => { c.routes = ["app.example.org/*"]; },
    (c) => { c.triggers.crons = ["* * * * *"]; },
    (c) => { c.r2_buckets = [{ binding: "LIVE", bucket_name: "live" }]; },
    (c) => { c.exports.SyntheticFence.storage = "legacy"; },
  ];
  for (const transform of variants) {
    const candidate = structuredClone(json);
    transform(candidate);
    rejected(() => checkCloudflareNotPublished(JSON.stringify(candidate)),
      "cloudflare_exposure_or_resource_change");
  }
});

test("unknown or corrupt candidate config fails closed", () => {
  rejected(() => checkCloudflareNotPublished("{invalid"),
    "invalid_candidate_configuration");
  rejected(() => checkCloudflareNotPublished("{}"),
    "cloudflare_exposure_or_resource_change");
});

test("release, deploy, recovery, merge and granting customer access are all refused", () => {
  for (const action of [
    "deploy", "deploy-private", "merge-main", "OPEN", "grant", "issue-token",
    "use-customers", "activate-r2", "run-real-tests",
  ]) rejected(() => requestOperationalAction(manifest, action),
    "operational_action_not_authorized");
  rejected(() => requestOperationalAction(manifest, ""),
    "invalid_action");
});

test("cli --check-lab passes but prints explicit BLOCKED; release request fails", () => {
  const binary = new URL("../src/c04-release-gates.mjs", import.meta.url);
  const ok = spawnSync(process.execPath, [binary.pathname, "--check-lab"],
    { encoding: "utf8" });
  assert.equal(ok.status, 0, ok.stderr);
  assert.match(ok.stdout, /operational release remains BLOCKED/);
  assert.match(ok.stdout, /"productionReady": false/);
  const deny = spawnSync(process.execPath, [binary.pathname, "--deploy"],
    { encoding: "utf8" });
  assert.equal(deny.status, 2);
  assert.match(deny.stderr, /DENY/);
});
