import test from "node:test";
import assert from "node:assert/strict";
import { fileURLToPath } from "node:url";
import { Miniflare } from "miniflare";

/**
 * WORKERD/Miniflare ONLY: no Cloudflare account and no network bindings.
 * Real R2, SQL authority, revocation/restore and deployment remain untested.
 */
test("Cloudflare runtime adapter starts synthetic SQLite DO in DENY with zero HTTP access", async () => {
  const workerPath = fileURLToPath(new URL("../src/worker.mjs", import.meta.url));
  const mf = new Miniflare({
    modules: true,
    scriptPath: workerPath,
    durableObjects: {
      FENCE_LAB: { className: "SyntheticFence", useSQLite: true },
    },
    compatibilityDate: "2026-07-30",
    cf: false,
  });

  try {
    await mf.ready;
    const publicResponse = await mf.dispatchFetch("http://localhost:8787/not-a-route");
    assert.equal(publicResponse.status, 404);
    assert.equal(await publicResponse.text(), "Not found");
    assert.equal(publicResponse.headers.get("cache-control"), "no-store");

    const namespace = await mf.getDurableObjectNamespace("FENCE_LAB");
    const stub = namespace.getByName("synthetic-installation");
    const objectResponse = await stub.fetch("http://localhost:8787/nonpublic");
    assert.equal(objectResponse.status, 404);
    assert.equal(await objectResponse.text(), "Not found");

    // This RPC is read-only and accesses nothing except a synthetic DENY record.
    const diagnostics = await stub.labDiagnostics();
    assert.equal(diagnostics.labOnly, true);
    assert.equal(diagnostics.mode, "DENY");
    assert.equal(diagnostics.generation, 0);
    assert.equal(diagnostics.canAuthorizeCustomers, false);
    assert.equal(diagnostics.canIssueGrants, false);
    assert.equal(diagnostics.includesRealAccounts, false);

    // A second, different synthetic install must independently start denied.
    const other = namespace.getByName("second-installation");
    const otherDiagnostics = await other.labDiagnostics();
    assert.equal(otherDiagnostics.mode, "DENY");
    assert.equal(otherDiagnostics.generation, 0);
  } finally {
    await mf.dispose();
  }
});
