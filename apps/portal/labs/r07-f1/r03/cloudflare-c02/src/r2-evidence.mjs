/**
 * Plans a *fictional* R2 evidence object. No network/storage writes.
 * R2 Bucket Locks do not prove independently durable, non-rollback Z4.
 */
const ALLOWED = new Set(["revoke", "begin-recovery", "restore-verification", "open", "blocked"]);
const KEY = /^[a-z][a-z0-9-]{0,39}$/;
const DIGEST = /^[a-f0-9]{64}$/;

export async function sha256Hex(text) {
  const buffer = new TextEncoder().encode(text);
  const bytes = new Uint8Array(await crypto.subtle.digest("SHA-256", buffer));
  return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
}

/**
 * Returns a proposed immutable key, canonical JSON body and SHA-256.
 * Not R2.put(), not a bucket lock and not independent audit attestation.
 */
export async function planEvidence({
  installation,
  generation,
  sequence,
  kind,
  previousDigest = null,
  fixture = "NON_OPERATIONAL_LAB",
}) {
  if (typeof installation !== "string" || !KEY.test(installation)
    || !Number.isSafeInteger(generation) || generation < 1
    || !Number.isSafeInteger(sequence) || sequence < 1
    || !ALLOWED.has(kind)
    || !(previousDigest === null || (typeof previousDigest === "string"
        && DIGEST.test(previousDigest)))
    || fixture !== "NON_OPERATIONAL_LAB") {
    throw new TypeError("Invalid fictional evidence descriptor.");
  }
  const data = {
    schema: "imagine-portal-sec-r03-c02.lab-evidence.v1",
    installation,
    generation,
    sequence,
    kind,
    previousDigest,
    fixture: "NON_OPERATIONAL_LAB",
    includesCustomerData: false,
    containsRealGrant: false,
  };
  const body = JSON.stringify(data);
  const digest = await sha256Hex(body);
  const key = [
    "r03-c02-lab",
    installation,
    String(generation).padStart(12, "0"),
    String(sequence).padStart(12, "0") + "-" + kind + "-" + digest + ".json",
  ].join("/");
  return Object.freeze({
    key,
    body,
    digest,
    metadata: Object.freeze({
      contentType: "application/json",
      sha256: digest,
      customerData: "none",
    }),
    couldAuthorizeRecovery: false,
  });
}
