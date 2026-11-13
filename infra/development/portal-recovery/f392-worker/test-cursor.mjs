import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes,verify} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from '../f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F392_CI!=='DISPOSABLE_ONLY')process.exit(64);

const org='org_f392',instance='instance_f392';
const db=new DatabaseSync(':memory:');
db.exec('PRAGMA foreign_keys=ON');
db.exec(readFileSync(new URL('../f376-worker/schema.sql',import.meta.url),'utf8'));
db.exec(readFileSync(new URL('../f386-worker/intents.sql',import.meta.url),'utf8'));
db.exec('CREATE INDEX f392_intent_order ON revocation_intents(organization,instance,to_sequence)');
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
 .run(org,instance,5,4,7,12,Math.floor(Date.now()/1000));
const sqlite={
  prepare(sql){
    const stmt=db.prepare(sql);
    return {bind(...args){return {
      async first(){return stmt.get(...args)??null;},
      async run(){const result=stmt.run(...args);return {success:true,meta:{changes:Number(result.changes)}};},
      runSync(){const result=stmt.run(...args);return {success:true,meta:{changes:Number(result.changes)}};},
    };}};
  },
  async batch(statements){
    db.exec('BEGIN IMMEDIATE');
    try{
      const results=statements.map(s=>s.runSync());
      db.exec('COMMIT');
      return results;
    }catch(e){db.exec('ROLLBACK');throw e;}
  },
};
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const publisherKey=randomBytes(32),cursorKey=randomBytes(32);
const env={
  DB:sqlite,WITNESS_ORGANIZATION:org,WITNESS_INSTANCE:instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:randomBytes(32).toString('base64'),
  INTENT_PUBLISHER_HMAC_B64:publisherKey.toString('base64'),
  INTENT_CURSOR_HMAC_B64:cursorKey.toString('base64'),
};
const origin='https://witness.f392.test.invalid';
const postPath='/v1/portal/revocation-intents';
const nextPath=postPath+'/next';
const now=()=>Math.floor(Date.now()/1000);
const hash=()=>createHash('sha256').update(randomBytes(32)).digest('hex');
const intent=async(from,floor)=>{
  const id=randomBytes(16).toString('hex');
  const digest=hash();
  const b={
    organization:org,instance,intent_id:id,session_sha256:digest,
    from_sequence:from,generation_floor:5,
    session_floor:floor,membership_floor:7,
  };
  const raw=JSON.stringify(b),ts=String(now());
  const mac=createHmac('sha256',publisherKey).update([
    'imagine.portal.witness.revocation-intent.v1',
    'POST',postPath,ts,raw,
  ].join('\n')).digest('hex');
  const r=await worker.fetch(new Request(origin+postPath,{
    method:'POST',headers:{
      'content-type':'application/json',
      'x-imagine-timestamp':ts,
      'x-imagine-signature':mac,
    },body:raw,
  }),env);
  assert.equal(r.status,204);
  return {id,digest,sequence:from+1,session_floor:floor};
};
function makeRead(after,nonce=randomBytes(32).toString('hex'),
  key=cursorKey,ts=String(now()),overrides={}){
  const canonical=[
    'imagine.portal.witness.revocation-cursor.v1',
    'GET',nextPath,ts,nonce,String(after),
  ].join('\n');
  const mac=createHmac('sha256',key).update(canonical).digest('hex');
  return new Request(origin+nextPath,{
    headers:{
      'x-imagine-timestamp':ts,
      'x-imagine-witness-nonce':nonce,
      'x-imagine-after-sequence':String(after),
      'x-imagine-signature':mac,...overrides,
    },
  });
}
function signPayload(p){
  return [
    'imagine.portal.f392.ed25519-cursor-domain.v1',
    'imagine.portal.f392.signed-intent-cursor.v1',
    p.organization,p.instance,String(p.after_sequence),
    String(p.observed_sequence),String(p.observed_session_floor),
    p.intent_id,p.session_sha256,String(p.intent_sequence),
    String(p.intent_session_floor),String(p.issued_at),
    String(p.expires_at),p.nonce,p.status,
  ].join('\n');
}
const verifyReceipt=(p)=>verify(null,Buffer.from(signPayload(p)),
  publicKey,Buffer.from(p.signature,'hex'));
const read=async(a,nonce=randomBytes(32).toString('hex'))=>{
  const r=await worker.fetch(makeRead(a,nonce),env);
  assert.equal(r.status,200);
  assert.match(r.headers.get('cache-control'),/no-store/);
  const value=await r.json();
  assert.equal(value.nonce,nonce);
  assert.ok(verifyReceipt(value));
  assert.equal(value.expires_at-value.issued_at,30);
  return value;
};

assert.equal((await worker.fetch(makeRead(0),env)).status,200);
assert.equal((await worker.fetch(makeRead(0,randomBytes(32).toString('hex'),
  Buffer.alloc(32,9)),env)).status,403);
assert.equal((await worker.fetch(makeRead(0,randomBytes(32).toString('hex'),
  publisherKey),env)).status,403); // separate publisher/cursor keys
assert.equal((await worker.fetch(makeRead(0,'invalid'),env)).status,400);
assert.equal((await worker.fetch(makeRead(0,randomBytes(32).toString('hex'),
  cursorKey,String(now()-180)),env)).status,403);
assert.equal((await worker.fetch(makeRead('01'),env)).status,400);
assert.equal((await worker.fetch(makeRead(-1),env)).status,400);
assert.equal((await worker.fetch(makeRead(1000000001),env)).status,400);
assert.equal((await worker.fetch(makeRead(0),{...env,INTENT_CURSOR_HMAC_B64:null})).status,503);
assert.equal((await worker.fetch(new Request(origin+nextPath+'?organization=other'),env)).status,404);

const emptyInitial=await read(0);
assert.equal(emptyInitial.status,'no_intent_after_cursor');
assert.equal(emptyInitial.intent_id,'');
assert.equal(emptyInitial.session_sha256,'');
assert.equal(emptyInitial.observed_sequence,12);
const first=await intent(12,5);
const second=await intent(13,6);
const page1=await read(0);
assert.equal(page1.intent_id,first.id);
assert.equal(page1.session_sha256,first.digest);
assert.equal(page1.intent_sequence,13);
assert.equal(page1.status,'pending_local_reconciliation');
const page2=await read(page1.intent_sequence);
assert.equal(page2.intent_id,second.id);
assert.equal(page2.session_sha256,second.digest);
assert.equal(page2.intent_sequence,14);
const empty=await read(page2.intent_sequence);
assert.equal(empty.status,'no_intent_after_cursor');
assert.equal(empty.after_sequence,14);
assert.equal(empty.observed_sequence,14);
assert.equal(empty.intent_sequence,0);
assert.equal(empty.intent_session_floor,0);
assert.equal((await worker.fetch(makeRead(15),env)).status,409);

// Model worker crash/queue loss: restart at 0, safely discover first again.
const restart=await read(0);
assert.equal(restart.intent_id,first.id);
assert.notEqual(restart.nonce,page1.nonce);
assert.notEqual(restart.signature,page1.signature);
assert.ok(!verifyReceipt({...restart,nonce:page1.nonce}));
assert.ok(!verifyReceipt({...page1,intent_sequence:14}));
assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_intents').get().n,2);

// Simulate administrative rollback in isolated SQLite after dropping the
// existing monotonicity trigger. The cursor must refuse unsigned progress
// beyond the current witness.
db.exec('DROP TRIGGER witness_epoch_monotonic');
db.prepare('UPDATE witness_epochs SET sequence=13,session_floor=5').run();
assert.equal((await worker.fetch(makeRead(13),env)).status,503);
assert.equal((await worker.fetch(makeRead(14),env)).status,409);
db.prepare('UPDATE witness_epochs SET sequence=14,session_floor=6').run();
assert.equal((await worker.fetch(makeRead(13),env)).status,200);

const dir=process.env.F392_INTEROP_DIR;
if(!dir)throw Error('F392 needs disposable interop directory');
for(const [file,value]of Object.entries({
  'first.json':page1,'second.json':page2,'empty.json':empty,
  'expected.json':{
    org,instance,first_id:first.id,second_id:second.id,
    first_hash:first.digest,second_hash:second.digest,
    first_nonce:page1.nonce,second_nonce:page2.nonce,empty_nonce:empty.nonce,
  },
  'public.json':{
    hex:publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex'),
  },
})){
  writeFileSync(dir+'/'+file,JSON.stringify(value),{mode:0o600});
}
db.close();
console.log('PASS F392 Worker: signed HMAC cursor enumerates append-only intents, replays after crash, Ed25519 nonces and rollback failures; NO SERVING.');
