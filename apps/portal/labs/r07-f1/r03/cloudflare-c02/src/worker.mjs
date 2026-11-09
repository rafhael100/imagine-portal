/**
 * Inert Cloudflare Durable Object adapter for the isolated C02 experiment.
 * No public entry point, administrative RPC mutator, billing, CRM, Sites,
 * performance or customer session integration is implemented.
 */
import { DurableObject } from "cloudflare:workers";
import { initialState, verifyState } from "./protocol.mjs";

const NO_PUBLIC_ACCESS = Object.freeze({
  status: 404,
  headers: { "cache-control": "no-store", "content-type": "text/plain; charset=utf-8" },
});

export class SyntheticFence extends DurableObject {
  constructor(ctx, env) {
    super(ctx, env);
    this.ctx = ctx;
    this.ctx.blockConcurrencyWhile(async () => {
      // SQLite-backed DO sync KV: initialize only a DENY state, not an
      // operational token, tenant, billing, grant or authorizer.
      ctx.storage.transactionSync(() => {
        if (ctx.storage.kv.get("lab-fence-v1") === undefined) {
          ctx.storage.kv.put("lab-fence-v1", initialState());
        }
      });
    });
  }

  // For local simulator diagnostics, via a same-account DO RPC binding only.
  // No state-changing RPC methods are exported.
  async labDiagnostics() {
    const state = this.ctx.storage.kv.get("lab-fence-v1");
    verifyState(state);
    return {
      labOnly: true,
      mode: state.mode,
      generation: state.generation,
      canAuthorizeCustomers: false,
      canIssueGrants: false,
      includesRealAccounts: false,
    };
  }

  fetch() {
    return new Response("Not found", NO_PUBLIC_ACCESS);
  }
}

export default {
  fetch() {
    return new Response("Not found", NO_PUBLIC_ACCESS);
  },
};
