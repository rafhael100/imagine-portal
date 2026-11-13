/**
 * F376: isolated Cloudflare Workers + D1 WITNESS SERVICE PROTOTYPE.
 *
 * DO NOT DEPLOY OR ENABLE PUBLIC SERVING. This module is never imported by the
 * Portal/Laravel app. Signing secrets and D1 live on a *separate trust host*.
 * A successful GET/POST only returns signed DATA, never access authority.
 *
 * Scope is fixed by operator-controlled deployment bindings (one isolated
 * organization/instance per deployment), not by a caller's query or headers.
 */
const GET_PATH = '/v1/portal/recovery-witness';
const WRITE_PATH = '/v1/portal/witness-advance';
const SCHEMA = 'imagine.portal.f369.signed-witness.v1';
const SIGN_DOMAIN = 'imagine.portal.f369.ed25519-witness-domain.v1';
const PUBLISH_DOMAIN = 'imagine.portal.witness.publish.v1';
const MAX_WRITE_BODY = 1024;
const LIMIT = 1000000000;
const text = new TextEncoder();

function response(status) {
  return new Response(null, {
    status,
    headers: {
      'Cache-Control': 'no-store, max-age=0',
      'X-Content-Type-Options': 'nosniff',
    },
  });
}

function validScope(value) {
  return typeof value === 'string'
    && /^[A-Za-z0-9_-]{1,64}$/.test(value);
}

function positive(value) {
  return Number.isSafeInteger(value) && value >= 1 && value <= LIMIT;
}

function binary(base64) {
  if (typeof base64 !== 'string' || !/^[A-Za-z0-9+/]+={0,2}$/.test(base64)) {
    throw new Error('Invalid secret encoding');
  }
  const bytes = Uint8Array.from(atob(base64), ch => ch.charCodeAt(0));
  if (btoa(String.fromCharCode(...bytes)) !== base64) {
    throw new Error('Noncanonical secret encoding');
  }
  return bytes;
}

function receiptCanonical(receipt) {
  return [
    SIGN_DOMAIN, SCHEMA, receipt.organization, receipt.instance,
    String(receipt.generation_floor), String(receipt.session_floor),
    String(receipt.membership_floor), String(receipt.sequence),
    String(receipt.issued_at), String(receipt.expires_at),
  ].join('\n');
}

function hex(bytes) {
  return Array.from(new Uint8Array(bytes), b => b.toString(16).padStart(2, '0')).join('');
}

async function signReceipt(data, env, now) {
  const signingBytes = binary(env.WITNESS_SIGNING_PKCS8_B64);
  if (signingBytes.length < 48 || signingBytes.length > 256) throw new Error('Signing key missing');
  const key = await crypto.subtle.importKey('pkcs8', signingBytes, { name: 'Ed25519' }, false, ['sign']);
  const receipt = {
    schema: SCHEMA,
    organization: data.organization,
    instance: data.instance,
    generation_floor: data.generation_floor,
    session_floor: data.session_floor,
    membership_floor: data.membership_floor,
    sequence: data.sequence,
    issued_at: now,
    expires_at: now + 60,
    signature: '',
  };
  const signed = await crypto.subtle.sign('Ed25519', key, text.encode(receiptCanonical(receipt)));
  receipt.signature = hex(signed);
  return receipt;
}

async function bodyLimited(request) {
  const expected = request.headers.get('content-length');
  if (expected !== null && (!/^\d{1,4}$/.test(expected) || Number(expected) > MAX_WRITE_BODY)) {
    throw new Error('Request too large');
  }
  if (!request.body) throw new Error('Missing request');
  const reader = request.body.getReader();
  const chunks = [];
  let size = 0;
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    size += value.byteLength;
    if (size > MAX_WRITE_BODY) {
      await reader.cancel();
      throw new Error('Request too large');
    }
    chunks.push(value);
  }
  const buffer = new Uint8Array(size);
  let position = 0;
  for (const chunk of chunks) {
    buffer.set(chunk, position);
    position += chunk.byteLength;
  }
  return new TextDecoder('utf-8', { fatal: true }).decode(buffer);
}

async function verifyPublisher(request, env, raw, now) {
  const ts = request.headers.get('x-imagine-timestamp') ?? '';
  const macHex = request.headers.get('x-imagine-signature') ?? '';
  if (!/^\d{10}$/.test(ts) || !/^[a-f0-9]{64}$/.test(macHex)
    || Math.abs(now - Number(ts)) > 30) return false;
  const shared = binary(env.PUBLISHER_HMAC_B64);
  if (shared.length !== 32) throw new Error('Publisher key missing');
  const key = await crypto.subtle.importKey('raw', shared, { name: 'HMAC', hash: 'SHA-256' }, false, ['verify']);
  const input = [PUBLISH_DOMAIN, 'POST', WRITE_PATH, ts, raw].join('\n');
  const sig = Uint8Array.from(macHex.match(/../g), pair => parseInt(pair, 16));
  return crypto.subtle.verify('HMAC', key, sig, text.encode(input));
}

function validRow(row, org, instance) {
  return row && row.organization === org && row.instance === instance
    && ['generation_floor','session_floor','membership_floor','sequence']
      .every(k => positive(row[k]));
}

async function readCurrent(env, org, instance) {
  const stmt = env.DB.prepare(
    'SELECT organization,instance,generation_floor,session_floor,membership_floor,sequence '
    + 'FROM witness_epochs WHERE organization=? AND instance=?',
  ).bind(org, instance);
  return stmt.first();
}

async function getSigned(env, org, instance, now) {
  const row = await readCurrent(env, org, instance);
  if (!validRow(row, org, instance)) return response(503);
  const receipt = await signReceipt(row, env, now);
  return new Response(JSON.stringify(receipt), {
    status: 200,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store, max-age=0',
      'Pragma': 'no-cache',
      'X-Content-Type-Options': 'nosniff',
    },
  });
}

async function advance(request, env, org, instance, now) {
  if (!/^application\/json(?:;\s*charset=utf-8)?$/i.test(
    request.headers.get('content-type') ?? ''
  )) return response(415);
  const raw = await bodyLimited(request);
  if (!await verifyPublisher(request, env, raw, now)) return response(403);
  const body = JSON.parse(raw);
  const keys = [
    'organization','instance','from_sequence',
    'generation_floor','session_floor','membership_floor',
  ];
  if (!body || typeof body !== 'object' || Array.isArray(body)
    || JSON.stringify(Object.keys(body)) !== JSON.stringify(keys)
    || body.organization !== org || body.instance !== instance
    || !positive(body.from_sequence)
    || !positive(body.generation_floor)
    || !positive(body.session_floor)
    || !positive(body.membership_floor)) return response(422);

  // The D1 UPDATE is atomic compare-and-swap: exact preceding sequence and
  // non-decreasing floors. Only one concurrent caller can advance a floor.
  const statement = env.DB.prepare(
    'UPDATE witness_epochs SET generation_floor=?,session_floor=?,membership_floor=?,'
    + 'sequence=sequence+1,updated_at=? '
    + 'WHERE organization=? AND instance=? AND sequence=? '
    + 'AND generation_floor<=? AND session_floor<=? AND membership_floor<=? '
    + 'AND sequence<1000000000',
  ).bind(
    body.generation_floor, body.session_floor, body.membership_floor, now,
    org, instance, body.from_sequence,
    body.generation_floor, body.session_floor, body.membership_floor,
  );
  const result = await statement.run();
  return result?.success === true && result?.meta?.changes === 1
    ? response(204) : response(409);
}

export default {
  async fetch(request, env) {
    try {
      if (!env?.DB || !validScope(env.WITNESS_ORGANIZATION)
        || !validScope(env.WITNESS_INSTANCE)
        || typeof env.WITNESS_SIGNING_PKCS8_B64 !== 'string'
        || typeof env.PUBLISHER_HMAC_B64 !== 'string') return response(503);
      const url = new URL(request.url);
      if (url.search !== '' || url.hash !== '') return response(404);
      const now = Math.floor(Date.now()/1000);
      if (request.method === 'GET' && url.pathname === GET_PATH) {
        return await getSigned(env, env.WITNESS_ORGANIZATION, env.WITNESS_INSTANCE, now);
      }
      if (request.method === 'POST' && url.pathname === WRITE_PATH) {
        return await advance(request, env, env.WITNESS_ORGANIZATION, env.WITNESS_INSTANCE, now);
      }
      return response(404);
    } catch {
      // No SQL diagnostics, signing material, tenant IDs or auth details.
      return response(503);
    }
  },
};
