import assert from 'node:assert/strict';
import { readFileSync, writeFileSync } from 'node:fs';
import { createHmac, generateKeyPairSync, verify as verifyEd25519 } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import worker from '../src/witness.mjs';

const origin = 'https://witness.example.net';
const getUrl = origin + '/v1/portal/recovery-witness';
const publishUrl = origin + '/v1/portal/witness-advance';
const scope = 'org_f376';
const instance = 'instance_f376';
const domain = 'imagine.portal.f369.ed25519-witness-domain.v1';
const schema = 'imagine.portal.f369.signed-witness.v1';
const publisherDomain = 'imagine.portal.witness.publish.v1';

function sqliteBinding(db) {
  return {
    prepare(sql) {
      const prepared = db.prepare(sql);
      return {
        bind(...args) {
          return {
            async first() {
              const row = prepared.get(...args);
              return row ?? null;
            },
            async run() {
              const result = prepared.run(...args);
              return { success: true, meta: { changes: Number(result.changes) } };
            },
          };
        },
      };
    },
  };
}

function request(body, key, ts = String(Math.floor(Date.now()/1000))) {
  const raw = JSON.stringify(body);
  const canonical = [
    publisherDomain, 'POST', '/v1/portal/witness-advance', ts, raw,
  ].join('\n');
  const signature = createHmac('sha256',key).update(canonical).digest('hex');
  return new Request(publishUrl,{
    method:'POST',
    headers:{
      'content-type':'application/json',
      'x-imagine-timestamp':ts,
      'x-imagine-signature':signature,
    },
    body:raw,
  });
}

function canonicalReceipt(receipt) {
  return [
    domain,schema,receipt.organization,receipt.instance,
    String(receipt.generation_floor),String(receipt.session_floor),
    String(receipt.membership_floor),String(receipt.sequence),
    String(receipt.issued_at),String(receipt.expires_at),
  ].join('\n');
}

test('F376: real SQLite CAS + detached Ed25519, replay and scope never grant serving', async()=>{
  const db = new DatabaseSync(':memory:');
  db.exec(readFileSync(new URL('../schema.sql',import.meta.url),'utf8'));
  db.prepare(
    'INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) '
    + 'VALUES(?,?,?,?,?,?,?)',
  ).run(scope,instance,5,3,7,12,Math.floor(Date.now()/1000));
  const publisherKey = Buffer.from(Array.from({length:32},(_,i)=>i+23));
  const { privateKey, publicKey } = generateKeyPairSync('ed25519');
  const env = {
    DB:sqliteBinding(db),
    WITNESS_ORGANIZATION:scope,
    WITNESS_INSTANCE:instance,
    WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
    PUBLISHER_HMAC_B64:publisherKey.toString('base64'),
  };
  const rawPublic=publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex');
  const getter=async()=>worker.fetch(new Request(getUrl),env);
  const old=await getter();
  assert.equal(old.status,200);
  assert.match(old.headers.get('cache-control'),/no-store/);
  const original=await old.json();
  assert.deepEqual(Object.keys(original),[
    'schema','organization','instance','generation_floor','session_floor',
    'membership_floor','sequence','issued_at','expires_at','signature',
  ]);
  assert.equal(original.sequence,12);
  assert.equal(original.session_floor,3);
  assert.equal(
    verifyEd25519(null,Buffer.from(canonicalReceipt(original)),
      publicKey,Buffer.from(original.signature,'hex')),
    true
  );
  assert.equal(original.expires_at-original.issued_at,60);

  const update = {
    organization:scope,instance,from_sequence:12,
    generation_floor:5,session_floor:4,membership_floor:7,
  };
  const denied = await worker.fetch(new Request(publishUrl,{
    method:'POST',headers:{'content-type':'application/json'},
    body:JSON.stringify(update),
  }),env);
  assert.equal(denied.status,403);
  assert.equal((await getter()).status,200);
  const conflict=await worker.fetch(request({
    ...update,session_floor:2,
  },publisherKey),env);
  assert.equal(conflict.status,409);

  const published=await worker.fetch(request(update,publisherKey),env);
  assert.equal(published.status,204);
  assert.match(published.headers.get('cache-control'),/no-store/);
  const advanced=await (await getter()).json();
  assert.equal(advanced.sequence,13);
  assert.equal(advanced.session_floor,4);
  assert.equal(advanced.signature.length,128);
  assert.equal(verifyEd25519(null,Buffer.from(canonicalReceipt(advanced)),
    publicKey,Buffer.from(advanced.signature,'hex')),true);
  assert.equal((await worker.fetch(request(update,publisherKey),env)).status,409);
  assert.equal((await worker.fetch(request({...update,organization:'other'},publisherKey),env)).status,422);
  assert.equal((await worker.fetch(request({...update,from_sequence:13,session_floor:3},publisherKey),env)).status,409);
  assert.equal((await worker.fetch(request({...update,from_sequence:13,extra:'x'},publisherKey),env)).status,422);
  assert.equal((await worker.fetch(request({...update,from_sequence:13},publisherKey,
    String(Math.floor(Date.now()/1000)-120)),env)).status,403);

  const tampered=new Request(publishUrl,{
    method:'POST',
    headers:{
      'content-type':'application/json',
      'x-imagine-timestamp':String(Math.floor(Date.now()/1000)),
      'x-imagine-signature':'0'.repeat(64),
    },
    body:JSON.stringify({...update,from_sequence:13}),
  });
  assert.equal((await worker.fetch(tampered,env)).status,403);

  const goodAgain={...update,from_sequence:13,session_floor:5};
  const [one,two]=await Promise.all([
    worker.fetch(request(goodAgain,publisherKey),env),
    worker.fetch(request(goodAgain,publisherKey),env),
  ]);
  assert.deepEqual([one.status,two.status].sort((a,b)=>a-b),[204,409]);
  const final=await (await getter()).json();
  assert.equal(final.sequence,14);
  assert.equal(final.session_floor,5);
  assert.equal(verifyEd25519(null,Buffer.from(canonicalReceipt(final)),
    publicKey,Buffer.from(final.signature,'hex')),true);

  assert.throws(()=>db.prepare("UPDATE witness_epochs SET sequence=12").run(),/monotonicity/);
  assert.throws(()=>db.prepare("DELETE FROM witness_epochs").run(),/deletion/);
  assert.equal((await worker.fetch(new Request(getUrl+'?org=other'),env)).status,404);
  assert.equal((await worker.fetch(new Request(origin+'/other'),env)).status,404);
  assert.equal((await worker.fetch(new Request(getUrl,{method:'POST'}),env)).status,404);
  assert.equal((await worker.fetch(new Request(getUrl),{...env,DB:null})).status,503);
  assert.equal((await worker.fetch(new Request(getUrl),{...env,WITNESS_SIGNING_PKCS8_B64:'bad'})).status,503);
  assert.equal((await worker.fetch(new Request(getUrl),{...env,WITNESS_ORGANIZATION:'bad/scope'})).status,503);
  assert.equal((await worker.fetch(new Request(publishUrl,{
    method:'POST',
    headers:{'content-type':'text/plain'},
    body:JSON.stringify(goodAgain),
  }),env)).status,415);

  const emptyDB=new DatabaseSync(':memory:');
  emptyDB.exec(readFileSync(new URL('../schema.sql',import.meta.url),'utf8'));
  assert.equal((await worker.fetch(new Request(getUrl),
    {...env,DB:sqliteBinding(emptyDB)})).status,503);
  emptyDB.close();

  const folder=process.env.F376_INTEROP_DIR;
  if(folder){
    writeFileSync(folder+'/witness.json',JSON.stringify(final),{mode:0o600});
    writeFileSync(folder+'/signing-public.hex',rawPublic,{mode:0o600});
    writeFileSync(folder+'/portal-snapshot.json',JSON.stringify({
      organization:scope,instance,
      generation:5,session_revision:5,membership_revision:7,
      witnessed_sequence:14,
    }),{mode:0o600});
  }
  db.close();
  console.log('PASS F376: Ed25519 signed D1-like SQLite witness, atomic CAS, replay, stale, scope, no-authority.');
});
