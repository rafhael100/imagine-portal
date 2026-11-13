/**
 * F389: actual F376 Worker + F386 atomic intent + F387 signed readback,
 * with the organization and opaque session reference seeded by actual PG18.
 * Only ephemeral Node24 SQLite and synthetic HMAC/Ed25519 keys are used.
 * This fixture is run in a separate container with NO network access.
 */
import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes,verify} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from './f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F389_CI!=='DISPOSABLE_ONLY'
  ||process.argv.length!==3)process.exit(64);
const root=process.argv[2];
const seed=JSON.parse(readFileSync(root+'/seed.json','utf8'));
if(typeof seed.organization!=='string'
  ||!/^[a-z0-9-]{36}$/.test(seed.organization)
  ||seed.instance!=='f389_ci'
  ||typeof seed.session_reference!=='string'
  ||!/^[a-f0-9]{64}$/.test(seed.session_reference)
  ||seed.session_sha256!==createHash('sha256').update(seed.session_reference).digest('hex')){
  throw Error('F389 signed seed invalid');
}
const db=new DatabaseSync(':memory:');
db.exec('PRAGMA foreign_keys=ON');
db.exec(readFileSync(new URL('./f376-worker/schema.sql',import.meta.url),'utf8'));
db.exec(readFileSync(new URL('./f386-worker/intents.sql',import.meta.url),'utf8'));
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
 .run(seed.organization,seed.instance,5,4,7,12,Math.floor(Date.now()/1000));

const d1={
  prepare(sql){
    const stmt=db.prepare(sql);
    return {bind(...args){return {
      async first(){return stmt.get(...args)??null;},
      async run(){const r=stmt.run(...args);return {success:true,meta:{changes:Number(r.changes)}};},
      runSync(){const r=stmt.run(...args);return {success:true,meta:{changes:Number(r.changes)}};},
    };}};
  },
  async batch(items){
    db.exec('BEGIN IMMEDIATE');
    try{
      const results=items.map(s=>s.runSync());
      db.exec('COMMIT');
      return results;
    }catch(e){db.exec('ROLLBACK');throw e;}
  },
};
const key=randomBytes(32);
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const env={
  DB:d1,WITNESS_ORGANIZATION:seed.organization,
  WITNESS_INSTANCE:seed.instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:randomBytes(32).toString('base64'),
  INTENT_PUBLISHER_HMAC_B64:key.toString('base64'),
};
const origin='https://witness.f389.test.invalid';
const path='/v1/portal/revocation-intents';
const id=randomBytes(16).toString('hex');
const body={
  organization:seed.organization,instance:seed.instance,
  intent_id:id,session_sha256:seed.session_sha256,
  from_sequence:12,generation_floor:5,session_floor:5,membership_floor:7,
};
const raw=JSON.stringify(body);
const ts=String(Math.floor(Date.now()/1000));
const mac=createHmac('sha256',key).update([
  'imagine.portal.witness.revocation-intent.v1',
  'POST',path,ts,raw,
].join('\n')).digest('hex');
const publish=new Request(origin+path,{
  method:'POST',
  headers:{
    'Content-Type':'application/json',
    'X-Imagine-Timestamp':ts,
    'X-Imagine-Signature':mac,
  },
  body:raw,
});
const applied=await worker.fetch(publish,env);
assert.equal(applied.status,204);
assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_intents').get().n,1);
assert.equal(db.prepare('SELECT sequence FROM witness_epochs').get().sequence,13);

// Reconcile an unknown HTTP outcome by a new, authenticated F387 GET
// with a fresh challenge. The answer still says PENDING PG revocation.
const nonce=randomBytes(32).toString('hex');
const readPath=path+'/'+id;
const readTs=String(Math.floor(Date.now()/1000));
const readMac=createHmac('sha256',key).update([
  'imagine.portal.witness.revocation-intent-read.v1',
  'GET',readPath,readTs,nonce,
].join('\n')).digest('hex');
const readReq=new Request(origin+readPath,{headers:{
  'X-Imagine-Timestamp':readTs,
  'X-Imagine-Signature':readMac,
  'X-Imagine-Witness-Nonce':nonce,
}});
const readResult=await worker.fetch(readReq,env);
assert.equal(readResult.status,200);
const receipt=await readResult.json();
assert.equal(receipt.status,'pending_local_reconciliation');
assert.equal(receipt.organization,seed.organization);
assert.equal(receipt.session_sha256,seed.session_sha256);
assert.equal(receipt.intent_id,id);
assert.equal(receipt.nonce,nonce);
assert.equal(receipt.intent_sequence,13);
assert.equal(receipt.observed_sequence,13);
const canonical=[
  'imagine.portal.f387.ed25519-intent-ack-domain.v1',
  'imagine.portal.f387.revocation-intent-ack.v1',
  receipt.organization,receipt.instance,receipt.intent_id,
  receipt.session_sha256,String(receipt.intent_sequence),
  String(receipt.observed_sequence),String(receipt.session_floor),
  String(receipt.issued_at),String(receipt.expires_at),
  receipt.nonce,receipt.status,
].join('\n');
assert.ok(verify(null,Buffer.from(canonical),
  publicKey,Buffer.from(receipt.signature,'hex')));
assert.ok(!JSON.stringify(receipt).includes(seed.session_reference));

for(const [name,obj] of Object.entries({
  'receipt.json':receipt,
  'expected.json':{
    organization:seed.organization,instance:seed.instance,
    intent_id:id,session_sha256:seed.session_sha256,nonce,
  },
  'public.json':{
    hex:publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex'),
  },
})){
  writeFileSync(root+'/'+name,JSON.stringify(obj),{mode:0o600});
}
db.close();
console.log('PASS F389 Worker: actual F376/F386/F387 Ed25519+HMAC intent for real PG18 synthetic tenant and session, pending only.');
