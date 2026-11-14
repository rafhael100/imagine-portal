/**
 * F399: disposable-only recovery from ambiguous F393 ACK write outcomes.
 * Stage 1: Worker writes ACK claim and intentionally drops response.
 * Stage 2: different process receives duplicate-ACK 409, performs a new
 * HMAC/nonce-bound signed readback, and confirms the immutable claim exists.
 * Stage 3: repeat stage 2, proving idempotent replay without ACK rewrite.
 *
 * IMPORTANT: ACK claim != independent PG revocation. A restricted PG18
 * status read in PHP is required before/after; all serving stays CLOSED.
 */
import assert from 'node:assert/strict';
import {createHmac,randomBytes,verify} from 'node:crypto';
import {DatabaseSync} from 'node:sqlite';
import {readFileSync,writeFileSync,existsSync} from 'node:fs';
import worker from './f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F399_CI!=='DISPOSABLE_ONLY'
  ||process.argv.length!==4
  ||!['publish-and-drop','recover'].includes(process.argv[2]))process.exit(64);
const phase=process.argv[2],dir=process.argv[3];
const read=name=>JSON.parse(readFileSync(dir+'/'+name,'utf8'));
const seed=read('seed.json'),keys=read('f394-keys.json');
const body=read('ack-body.json'),expected=read('expected.json');
assert.deepEqual(Object.keys(body),[
  'organization','instance','intent_id','session_sha256',
  'intent_sequence','local_status',
]);
assert.equal(seed.organization,body.organization);
assert.equal(seed.instance,body.instance);
assert.equal(seed.session_sha256,body.session_sha256);
assert.equal(expected.intent_id,body.intent_id);
assert.equal(body.local_status,'revoked_claimed');
assert.equal(body.intent_sequence,13);
assert.equal(Object.hasOwn(seed,'session_reference'),false);
const db=new DatabaseSync(dir+'/f394-ledger.sqlite');
db.exec('PRAGMA foreign_keys=ON');
const d1={
  prepare(sql){
    const stmt=db.prepare(sql);
    return {bind(...args){return {
      async first(){return stmt.get(...args)??null;},
      async run(){const r=stmt.run(...args);
        return {success:true,meta:{changes:Number(r.changes)}};},
      runSync(){const r=stmt.run(...args);
        return {success:true,meta:{changes:Number(r.changes)}};},
    };}};
  },
  async batch(items){
    db.exec('BEGIN IMMEDIATE');
    try{const a=items.map(x=>x.runSync());db.exec('COMMIT');return a;}
    catch(e){db.exec('ROLLBACK');throw e;}
  },
};
const env={...keys,DB:d1};
const org=seed.organization,instance=seed.instance;
const origin='https://f399.disposable.test.invalid';
const path='/v1/portal/revocation-acks';
const now=()=>Math.floor(Date.now()/1000);
function mac(secret,lines){
  return createHmac('sha256',Buffer.from(secret,'base64'))
    .update(lines.join('\n')).digest('hex');
}
function publish(){
  const timestamp=String(now()),raw=JSON.stringify(body);
  return new Request(origin+path,{method:'POST',headers:{
    'content-type':'application/json',
    'x-imagine-timestamp':timestamp,
    'x-imagine-signature':mac(keys.ACK_CLAIM_HMAC_B64,[
      'imagine.portal.f393.ack-claim-publish.v1','POST',
      path,timestamp,'',raw,
    ]),
  },body:raw});
}
function query(nonce){
  const lookup=path+'/'+body.intent_id,timestamp=String(now());
  return new Request(origin+lookup,{headers:{
    'x-imagine-timestamp':timestamp,
    'x-imagine-witness-nonce':nonce,
    'x-imagine-signature':mac(keys.ACK_READ_HMAC_B64,[
      'imagine.portal.f393.ack-claim-read.v1',
      'GET',lookup,timestamp,nonce,'',
    ]),
  }});
}
const count=()=>db.prepare(
  'SELECT COUNT(*) AS count FROM revocation_ack_claims').get().count;
function canonical(r){
  return [
    'imagine.portal.f393.ed25519-ack-domain.v1',
    'imagine.portal.f393.signed-ack-claim.v1',
    r.organization,r.instance,r.intent_id,r.session_sha256,
    String(r.intent_sequence),String(r.observed_sequence),
    String(r.observed_session_floor),String(r.claimed_at),
    String(r.issued_at),String(r.expires_at),r.nonce,r.status,
  ].join('\n');
}
function signed(r){
  const hex=read('public.json').hex;
  const pub=Buffer.concat([
    Buffer.from('302a300506032b6570032100','hex'),
    Buffer.from(hex,'hex'),
  ]);
  return verify(null,Buffer.from(canonical(r)),{
    key:pub,format:'der',type:'spki',
  },Buffer.from(r.signature,'hex'));
}
if(phase==='publish-and-drop'){
  assert.equal(count(),0);
  assert.equal(existsSync(dir+'/ack-receipt.json'),false);
  const result=await worker.fetch(publish(),env);
  assert.equal(result.status,204);
  assert.equal(count(),1);
  const claim=db.prepare(
    'SELECT * FROM revocation_ack_claims').get();
  assert.equal(claim.intent_id,body.intent_id);
  assert.equal(claim.session_sha256,body.session_sha256);
  // Simulates losing HTTP response after remote commit: DO NOT persist
  // the 204 response or assume local completion. The next process must
  // make a fresh authenticated independent read.
  assert.equal(existsSync(dir+'/ack-receipt.json'),false);
  db.close();
  console.log('PASS F399 publish: ACK immutable claim committed; original HTTP result deliberately discarded before client persistence.');
}else{
  assert.equal(count(),1);
  const replay=await worker.fetch(publish(),env);
  assert.equal(replay.status,409);
  assert.equal(count(),1);
  const nonce=randomBytes(32).toString('hex');
  const response=await worker.fetch(query(nonce),env);
  assert.equal(response.status,200);
  assert.match(response.headers.get('cache-control'),/no-store/);
  const receipt=await response.json();
  assert.equal(receipt.nonce,nonce);
  assert.equal(receipt.status,'ack_recorded_local_claim_unverified');
  assert.equal(receipt.organization,org);
  assert.equal(receipt.instance,instance);
  assert.equal(receipt.intent_id,body.intent_id);
  assert.equal(receipt.session_sha256,body.session_sha256);
  assert.equal(receipt.intent_sequence,13);
  assert.equal(receipt.expires_at-receipt.issued_at,30);
  assert.ok(signed(receipt));
  assert.ok(!JSON.stringify(receipt).includes('session_reference'));
  assert.ok(!JSON.stringify(receipt).includes('canAuthorizeHttp'));
  const ackFile=dir+'/ack-receipt.json';
  if(!existsSync(ackFile)){
    writeFileSync(ackFile,JSON.stringify(receipt),{
      mode:0o600,flag:'wx',
    });
    writeFileSync(dir+'/ack-expected.json',JSON.stringify({
      organization:org,instance,
      intent_id:body.intent_id,
      session_sha256:body.session_sha256,
      nonce,
    }),{mode:0o600,flag:'wx'});
    console.log('PASS F399 first restart: duplicate ACK 409 reconciled through new signed Ed25519 readback; zero mutation.');
  }else{
    const previous=read('ack-receipt.json');
    assert.ok(signed(previous));
    assert.equal(previous.intent_id,receipt.intent_id);
    assert.equal(previous.session_sha256,receipt.session_sha256);
    assert.notEqual(previous.nonce,receipt.nonce);
    assert.notEqual(previous.signature,receipt.signature);
    assert.equal(read('ack-expected.json').nonce,previous.nonce);
    console.log('PASS F399 second restart: replay-safe signed readback has fresh nonce, original ACK unchanged; NO SERVING.');
  }
  assert.equal(count(),1);
  db.close();
}
