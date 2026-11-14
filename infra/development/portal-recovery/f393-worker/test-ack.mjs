import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes,verify} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from '../f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F393_CI!=='DISPOSABLE_ONLY')process.exit(64);

const org='org_f393',instance='instance_f393';
const db=new DatabaseSync(':memory:');
db.exec('PRAGMA foreign_keys=ON');
for(const file of ['../f376-worker/schema.sql','../f386-worker/intents.sql','./ack-claims.sql']){
  db.exec(readFileSync(new URL(file,import.meta.url),'utf8'));
}
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
 .run(org,instance,5,4,7,12,Math.floor(Date.now()/1000));

const d1={
  prepare(sql){
    const p=db.prepare(sql);
    return {bind(...args){
      return {
        async first(){return p.get(...args)??null;},
        async run(){const x=p.run(...args);return {success:true,meta:{changes:Number(x.changes)}};},
        runSync(){const x=p.run(...args);return {success:true,meta:{changes:Number(x.changes)}};},
      };
    }};
  },
  async batch(items){
    db.exec('BEGIN IMMEDIATE');
    try{const result=items.map(p=>p.runSync());db.exec('COMMIT');return result;}
    catch(e){db.exec('ROLLBACK');throw e;}
  },
};
const now=()=>Math.floor(Date.now()/1000);
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const intentKey=randomBytes(32),ackKey=randomBytes(32),readKey=randomBytes(32);
const env={
  DB:d1,WITNESS_ORGANIZATION:org,WITNESS_INSTANCE:instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:randomBytes(32).toString('base64'),
  INTENT_PUBLISHER_HMAC_B64:intentKey.toString('base64'),
  ACK_CLAIM_HMAC_B64:ackKey.toString('base64'),
  ACK_READ_HMAC_B64:readKey.toString('base64'),
};
const origin='https://f393.test.invalid';
const intentPath='/v1/portal/revocation-intents';
const ackPath='/v1/portal/revocation-acks';
const id=randomBytes(16).toString('hex');
const hash=createHash('sha256').update(randomBytes(32)).digest('hex');
const status='revoked_claimed';
const intent={
  organization:org,instance,intent_id:id,session_sha256:hash,
  from_sequence:12,generation_floor:5,session_floor:5,membership_floor:7,
};
const ack={
  organization:org,instance,intent_id:id,session_sha256:hash,
  intent_sequence:13,local_status:status,
};
function post(path,body,key,domain,override={}){
  const raw=JSON.stringify(body);
  const ts=String(now());
  const sig=createHmac('sha256',key).update([
    domain,'POST',path,ts,'',raw,
  ].join('\n')).digest('hex');
  return new Request(origin+path,{method:'POST',headers:{
    'Content-Type':'application/json',
    'X-Imagine-Timestamp':ts,
    'X-Imagine-Signature':sig,...override,
  },body:raw});
}
function publishIntent(body,intendedKey=intentKey){
  const raw=JSON.stringify(body),ts=String(now());
  const sig=createHmac('sha256',intendedKey).update([
    'imagine.portal.witness.revocation-intent.v1','POST',
    intentPath,ts,raw,
  ].join('\n')).digest('hex');
  return new Request(origin+intentPath,{method:'POST',headers:{
    'content-type':'application/json',
    'x-imagine-timestamp':ts,'x-imagine-signature':sig,
  },body:raw});
}
const ackReq=(b=ack,k=ackKey)=>post(ackPath,b,k,
  'imagine.portal.f393.ack-claim-publish.v1');
function lookup(lookupId=id,nonce=randomBytes(32).toString('hex'),
  key=readKey,ts=String(now()),custom={}){
  const path=ackPath+'/'+lookupId;
  const sig=createHmac('sha256',key).update([
    'imagine.portal.f393.ack-claim-read.v1','GET',
    path,ts,nonce,'',
  ].join('\n')).digest('hex');
  return new Request(origin+path,{headers:{
    'x-imagine-timestamp':ts,
    'x-imagine-signature':sig,
    'x-imagine-witness-nonce':nonce,...custom,
  }});
}
function signedPayload(r){
  return [
    'imagine.portal.f393.ed25519-ack-domain.v1',
    'imagine.portal.f393.signed-ack-claim.v1',
    r.organization,r.instance,r.intent_id,r.session_sha256,
    String(r.intent_sequence),String(r.observed_sequence),
    String(r.observed_session_floor),String(r.claimed_at),
    String(r.issued_at),String(r.expires_at),r.nonce,r.status,
  ].join('\n');
}
const send=req=>worker.fetch(req,env);
const rows=()=>db.prepare('SELECT * FROM revocation_ack_claims').all();
assert.equal((await send(ackReq())).status,409); // no intent can be acknowledged
assert.equal((await send(lookup())).status,404);
assert.equal((await send(ackReq(ack,intentKey))).status,403);
assert.equal((await send(ackReq(ack,readKey))).status,403);
assert.equal((await send(ackReq(ack,Buffer.alloc(32,1)))).status,403);
assert.equal((await send(ackReq(ack,ackKey))).status,409); // valid HMAC alone does not create absent intent

assert.equal((await send(ackReq({...ack,local_status:'revoked'}))).status,422);
assert.equal((await send(ackReq({...ack,extra:'unsafe'}))).status,422);
assert.equal((await send(ackReq({...ack,organization:'other_tenant'}))).status,422);
assert.equal((await send(ackReq({...ack,session_sha256:'invalid'}))).status,422);
assert.equal((await send(ackReq({...ack,intent_sequence:14}))).status,409);
assert.equal(rows().length,0);

assert.equal((await send(publishIntent(intent))).status,204);
assert.equal(db.prepare('SELECT sequence FROM witness_epochs').get().sequence,13);
assert.equal((await send(ackReq({...ack,session_sha256:'0'.repeat(64)}))).status,409);
assert.equal((await send(ackReq({...ack,intent_sequence:12}))).status,409);
assert.equal(rows().length,0);

const accepted=await send(ackReq());
assert.equal(accepted.status,204);
assert.match(accepted.headers.get('cache-control'),/no-store/);
assert.equal(rows().length,1);
assert.equal(rows()[0].intent_id,id);
assert.equal(rows()[0].session_sha256,hash);
assert.equal(rows()[0].local_status,status);
assert.equal((await send(ackReq())).status,409);
assert.equal(rows().length,1);
assert.throws(()=>db.prepare(
  "UPDATE revocation_ack_claims SET local_status='revoked_claimed'",
).run(),/ACK mutation refused/);
assert.throws(()=>db.prepare(
  "DELETE FROM revocation_ack_claims",
).run(),/ACK deletion refused/);

const nonce=randomBytes(32).toString('hex');
const res=await send(lookup(id,nonce));
assert.equal(res.status,200);
assert.match(res.headers.get('cache-control'),/no-store/);
const receipt=await res.json();
assert.deepEqual(Object.keys(receipt),[
  'schema','organization','instance','intent_id','session_sha256',
  'intent_sequence','observed_sequence','observed_session_floor',
  'claimed_at','issued_at','expires_at','nonce','status','signature',
]);
assert.equal(receipt.status,'ack_recorded_local_claim_unverified');
assert.ok(!JSON.stringify(receipt).includes('session_reference'));
assert.ok(!JSON.stringify(receipt).includes('canAuthorizeHttp'));

assert.equal(receipt.session_sha256,hash);
assert.equal(receipt.intent_id,id);
assert.equal(receipt.intent_sequence,13);
assert.equal(receipt.observed_sequence,13);
assert.equal(receipt.nonce,nonce);
assert.equal(receipt.expires_at-receipt.issued_at,30);
assert.ok(verify(null,Buffer.from(signedPayload(receipt)),publicKey,
  Buffer.from(receipt.signature,'hex')));
assert.ok(!verify(null,Buffer.from(signedPayload({...receipt,claimed_at:receipt.claimed_at+1})),
  publicKey,Buffer.from(receipt.signature,'hex')));
assert.equal((await send(lookup(id,randomBytes(32).toString('hex'),ackKey))).status,403);
assert.equal((await send(lookup(id,'bad-nonce'))).status,400);
assert.equal((await send(lookup('0'.repeat(31)))).status,404);
assert.equal((await send(lookup(randomBytes(16).toString('hex')))).status,404);
assert.equal((await send(lookup(id,randomBytes(32).toString('hex'),readKey,String(now()-180)))).status,403);
assert.equal((await worker.fetch(ackReq(),{...env,ACK_CLAIM_HMAC_B64:null})).status,503);
assert.equal((await worker.fetch(lookup(),{...env,ACK_READ_HMAC_B64:null})).status,503);
assert.equal((await worker.fetch(lookup(),{...env,WITNESS_SIGNING_PKCS8_B64:'bad'})).status,503);

const newNonce=randomBytes(32).toString('hex');
const second=await (await send(lookup(id,newNonce))).json();
assert.equal(second.nonce,newNonce);
assert.notEqual(second.signature,receipt.signature);
assert.equal(rows().length,1);

// Simulate DBA rollback after dropping the witness monotonicity guard. A
// signed readback MUST NOT accept an ACK ahead of its observed witness.
db.exec('DROP TRIGGER witness_epoch_monotonic');
db.prepare('UPDATE witness_epochs SET session_floor=4').run();
assert.equal((await send(lookup(id,newNonce))).status,503); // same sequence, floor rollback
db.prepare('UPDATE witness_epochs SET session_floor=5').run();
db.prepare('UPDATE witness_epochs SET sequence=12,session_floor=4').run();
assert.equal((await send(lookup(id,newNonce))).status,503);
db.prepare('UPDATE witness_epochs SET sequence=13,session_floor=5').run();
assert.equal((await send(lookup(id,newNonce))).status,200);

const dir=process.env.F393_INTEROP_DIR;
if(!dir)throw Error('F393 ephemeral interoperability directory missing');
const files={
  'receipt.json':receipt,
  'expected.json':{
    organization:org,instance,intent_id:id,session_sha256:hash,nonce,
  },
  'public.json':{
    hex:publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex'),
  },
};
for(const [file,value] of Object.entries(files)){
  writeFileSync(dir+'/'+file,JSON.stringify(value),{mode:0o600});
}
db.close();
console.log('PASS F393 Worker: append-only ACK claim, HMAC-separated writer/reader, signed Ed25519 readback and rollback/refusal; NO POSTGRES PROOF or SERVING.');
