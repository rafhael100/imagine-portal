import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes,verify} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from '../f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F387_CI!=='DISPOSABLE_ONLY')process.exit(64);

const db=new DatabaseSync(':memory:');
db.exec('PRAGMA foreign_keys=ON');
db.exec(readFileSync(new URL('../f376-worker/schema.sql',import.meta.url),'utf8'));
db.exec(readFileSync(new URL('../f386-worker/intents.sql',import.meta.url),'utf8'));
const org='org_f387',instance='instance_f387';
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
 .run(org,instance,5,4,7,12,Math.floor(Date.now()/1000));
const dbAdapter={
  prepare(sql){
    const stmt=db.prepare(sql);
    return {bind(...args){
      return {
        async first(){return stmt.get(...args)??null;},
        async run(){const result=stmt.run(...args);return {success:true,meta:{changes:Number(result.changes)}};},
        runSync(){const result=stmt.run(...args);return {success:true,meta:{changes:Number(result.changes)}};},
      };
    }};
  },
  async batch(stmts){
    db.exec('BEGIN IMMEDIATE');
    try{
      const results=stmts.map(s=>s.runSync());
      db.exec('COMMIT');
      return results;
    }catch(e){
      db.exec('ROLLBACK');
      throw e;
    }
  },
};
const publisherKey=randomBytes(32);
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const env={
  DB:dbAdapter,WITNESS_ORGANIZATION:org,WITNESS_INSTANCE:instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:randomBytes(32).toString('base64'),
  INTENT_PUBLISHER_HMAC_B64:publisherKey.toString('base64'),
};
const origin='https://witness.f387.test.invalid';
const postPath='/v1/portal/revocation-intents';
const id=randomBytes(16).toString('hex');
const digest=createHash('sha256').update(randomBytes(32)).digest('hex');
const now=()=>Math.floor(Date.now()/1000);
const mutation={
  organization:org,instance,intent_id:id,session_sha256:digest,
  from_sequence:12,generation_floor:5,session_floor:5,membership_floor:7,
};
const post=(payload,secret=publisherKey)=>{
  const raw=JSON.stringify(payload);
  const ts=String(now());
  const mac=createHmac('sha256',secret).update([
    'imagine.portal.witness.revocation-intent.v1',
    'POST',postPath,ts,raw,
  ].join('\n')).digest('hex');
  return new Request(origin+postPath,{method:'POST',
    headers:{
      'Content-Type':'application/json',
      'X-Imagine-Timestamp':ts,
      'X-Imagine-Signature':mac,
    },body:raw,
  });
};
function readbackRequest(lookupId,nonce=randomBytes(32).toString('hex'),
  secret=publisherKey,ts=String(now())){
  const path=postPath+'/'+lookupId;
  const mac=createHmac('sha256',secret).update([
    'imagine.portal.witness.revocation-intent-read.v1',
    'GET',path,ts,nonce,
  ].join('\n')).digest('hex');
  return new Request(origin+path,{
    headers:{
      'X-Imagine-Witness-Nonce':nonce,
      'X-Imagine-Timestamp':ts,
      'X-Imagine-Signature':mac,
    },
  });
}
function canonical(receipt){
  return [
    'imagine.portal.f387.ed25519-intent-ack-domain.v1',
    'imagine.portal.f387.revocation-intent-ack.v1',
    receipt.organization,receipt.instance,receipt.intent_id,
    receipt.session_sha256,String(receipt.intent_sequence),
    String(receipt.observed_sequence),String(receipt.session_floor),
    String(receipt.issued_at),String(receipt.expires_at),
    receipt.nonce,receipt.status,
  ].join('\n');
}
const v2=async()=>worker.fetch(new Request(origin+'/v2/portal/recovery-witness',{
  headers:{'X-Imagine-Witness-Nonce':randomBytes(32).toString('hex')},
}),env);
const oldReceipt=await (await v2()).json();
assert.equal(oldReceipt.sequence,12);
assert.equal((await worker.fetch(readbackRequest(id),env)).status,404);
assert.equal((await worker.fetch(readbackRequest(id,randomBytes(32).toString('hex'),
  Buffer.alloc(32,1)),env)).status,403);
assert.equal((await worker.fetch(readbackRequest(id,'not-a-nonce'),env)).status,400);
assert.equal((await worker.fetch(readbackRequest(id,randomBytes(32).toString('hex'),
  publisherKey,String(now()-120)),env)).status,403);
assert.equal((await worker.fetch(readbackRequest('a'.repeat(31)),env)).status,404);
assert.equal((await worker.fetch(new Request(origin+postPath+'/'+id,{method:'POST'}),env)).status,404);

// Simulate F386 POST committed on a separate host, but the Laravel client
// lost its HTTP response (timeout). Re-query independent record by its ID.
const response=await worker.fetch(post(mutation),env);
assert.equal(response.status,204);
const committed=db.prepare('SELECT * FROM revocation_intents').get();
assert.equal(committed.intent_id,id);
assert.equal(committed.session_sha256,digest);
const witness=db.prepare('SELECT * FROM witness_epochs').get();
assert.equal(witness.sequence,13);
assert.equal(witness.session_floor,5);

// The sender is assumed to have lost "response"; it checks an authenticated
// signed fresh-nonce readback and must STILL NOT authorize HTTP/PG revocation.
const nonce=randomBytes(32).toString('hex');
const found=await worker.fetch(readbackRequest(id,nonce),env);
assert.equal(found.status,200);
assert.match(found.headers.get('cache-control'),/no-store/);
const receipt=await found.json();
assert.deepEqual(Object.keys(receipt),[
  'schema','organization','instance','intent_id','session_sha256',
  'intent_sequence','observed_sequence','session_floor',
  'issued_at','expires_at','nonce','status','signature',
]);
assert.equal(receipt.status,'pending_local_reconciliation');
assert.equal(receipt.intent_id,id);
assert.equal(receipt.session_sha256,digest);
assert.equal(receipt.intent_sequence,13);
assert.equal(receipt.observed_sequence,13);
assert.equal(receipt.session_floor,5);
assert.equal(receipt.nonce,nonce);
assert.equal(receipt.expires_at-receipt.issued_at,30);
assert.ok(verify(null,Buffer.from(canonical(receipt)),
  publicKey,Buffer.from(receipt.signature,'hex')));

const second=await worker.fetch(readbackRequest(id),env);
assert.equal(second.status,200);
const secondReceipt=await second.json();
assert.notEqual(secondReceipt.nonce,receipt.nonce);
assert.notEqual(secondReceipt.signature,receipt.signature);
assert.ok(!verify(null,Buffer.from(canonical({...receipt,nonce:secondReceipt.nonce})),
  publicKey,Buffer.from(receipt.signature,'hex')));

assert.equal((await worker.fetch(readbackRequest(id,randomBytes(32).toString('hex'),
  Buffer.alloc(32,2)),env)).status,403);
assert.equal((await worker.fetch(readbackRequest(randomBytes(16).toString('hex')),env)).status,404);
assert.equal((await worker.fetch(readbackRequest(id),{...env,
  INTENT_PUBLISHER_HMAC_B64:null})).status,503);
assert.equal((await worker.fetch(readbackRequest(id),{...env,
  WITNESS_SIGNING_PKCS8_B64:'invalid'})).status,503);
assert.equal((await worker.fetch(new Request(origin+postPath+'/'+id+'?user=other'),env)).status,404);

// Simulate privileged witness DB rollback: SQL trigger protection alone
// does not prevent a DBA from dropping the trigger. The signed readback
// must refuse intent whose sequence is ahead of the witnessed epoch.
db.exec('DROP TRIGGER witness_epoch_monotonic');
db.prepare('UPDATE witness_epochs SET sequence=12,session_floor=4').run();
assert.equal((await worker.fetch(readbackRequest(id),env)).status,503);
db.prepare('UPDATE witness_epochs SET sequence=13,session_floor=5').run();
assert.equal((await worker.fetch(readbackRequest(id),env)).status,200);

// The original caller's OLD PG snapshot is still at seq12; the separate
// Ed25519 F379 challenge will reject it until local revocation is verified.
const dir=process.env.F387_INTEROP_DIR;
if(!dir)throw Error('F387 requires private interop directory');
for(const [name,obj] of Object.entries({
  'intent-readback.json':receipt,
  'public-key.json':{hex:publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex')},
  'expected.json':{organization:org,instance,intent_id:id,session_sha256:digest,nonce},
})){
  writeFileSync(dir+'/'+name,JSON.stringify(obj),{mode:0o600});
}
db.close();
console.log('PASS F387: authenticated readback resolves ambiguous F386 commit, fresh Ed25519 signature, stale/replay/HMAC/rollback denied; pending PG reconciliation, NO SERVING.');
