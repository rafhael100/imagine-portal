# F376 — Isolated Workers + D1 recovery witness prototype (NO DEPLOY)

This standalone module is **not part of Laravel serving**. It is a potential separate recovery-witness service using Cloudflare Workers and D1. No Cloudflare account, D1 database, Worker route, custom domain, recurring billing or cloud resources are created by the PR or CI.

## Trust boundaries

The candidate D1 table `witness_epochs` must exist outside the Portal PostgreSQL server and backup. A SQLite trigger refuses decreasing floors, sequence gaps and deletion. A privileged D1 administrator can still drop the trigger or restore a D1 backup; this is not a tamper-proof monotonic witness.

`GET /v1/portal/recovery-witness` returns a 60-second F369 Ed25519 signed receipt with a fixed organization and recovery instance controlled by Worker environment bindings (not query arguments). Signing keys must never reside in the Laravel VPS. F369 in the Portal separately verifies pinned public key, signature, scope and expiration. **Neither a correct signature nor a matching epoch authorizes serving.**

`POST /v1/portal/witness-advance` accepts a strictly ordered, bounded JSON object signed with independently held 32-byte HMAC-SHA256 publisher secret. The signature covers exact domain-separated method, path, Unix timestamp (±30 seconds) and body bytes. A single conditional D1 SQL UPDATE performs compare-and-swap on `from_sequence`, with nondecreasing floors; replay and conflicting updates are rejected. No reset or deletion API and no implicit witness row seed exists. The actual Portal publisher and F370 witness-first transaction integration do **not** exist yet.

This proof is single-organization / single-instance per deployment only. Production multi-tenant isolation, publisher identity and independent authorization require separate design.

## Exact write shape

```json
{"organization":"org_f376","instance":"instance_f376","from_sequence":12,"generation_floor":5,"session_floor":4,"membership_floor":7}
```

Use `Content-Type: application/json`, `X-Imagine-Timestamp: <UnixSeconds>`, `X-Imagine-Signature: <lowercase hex HMAC_SHA256>`. HMAC input is UTF-8 domain, POST, `/v1/portal/witness-advance`, timestamp and **exact JSON body**, separated by newlines. Never expose these credentials to a browser.

## CI scope

Node.js 24 runs a real in-memory SQLite database against the candidate D1 schema and a small D1-compatible adapter, with WebCrypto Ed25519 signing, HMAC-authenticated publisher, concurrent CAS, replay/rollback prevention, invalid requests, scope and missing-service gates. A separate PHP 8.4 process uses the real F369 Portal verifier on the same synthetic witness receipt and demonstrates refusal of stale and forged values. All CI credentials are ephemeral and synthetic. Neither a remote Cloudflare Worker nor remote D1 is tested or deployed.

## NO-GO before production

External deployment identity, authoritative seeding, protected publisher channel, TLS configuration, independent signing key custody and rotation, D1 admin/backup rollback protection, network failure semantics, high availability, multi-tenant isolation, auditability and mandatory serving fences on all authentication and permission paths are still absent. F375's static TLS SPKI pin cannot be assumed safe with rotating managed edge certificates without further review. No CLI or Wrangler deploy config is provided. Portal issue #64 remains OPEN and public serving is **NO-GO**.
