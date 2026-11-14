/**
 * F394 disposable two-run worker: "seed" signs an independent F387 intent,
 * "complete" publishes F393 ACK only after restricted PG18 F391 produces
 * ack-body.json. Separate invocations share one file-backed SQLite DB, which
 * exercises interruption/restart without any real Cloudflare D1 deployment.
 */
import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes,verify} from 'node:crypto';
import {readFileSync,writeFileSync,existsSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from './f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F394_CI!=='DISPOSABLE_ONLY'
  ||process.argv.length!==4
  ||!['seed','complete'].includes(process.argv[2]))process.exit(64);
const phase=process.argv[2],dir=process.argv[3];
const read=name=>JSON.parse(readFileSync(dir+'/'+name,'utf8'));
const put=(name,obj)=>writeFileSync(dir+'/'+name,JSON.stringify(obj),{mode:0o600,flag:'wx'});
const seed=read('seed.json');
assert.equal(seed.instance,'f390_ci');
assert.match(seed.organization,/^[a-z0-9-]{36}$/);
assert.match(seed.session_sha256,/^[a-f0-9]{64}$/);
assert.equal(Object.hasOwn(seed,'session_reference'),false);
const organization=seed.organization,instance=seed.instance;
const db=new DatabaseSync(dir+'/f394-ledger.sqlite');
db.exec('PRAGMA foreign_keys=ON');
const now=()=>Math.floor(Date.now()/1000);
const origin='https://f394.ci.test.invalid';
const intentPath='/v1/portal/revocation-intents';
const ackPath='/v1/portal/revocation-acks';
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
      const result=items.map(i=>i.runSync());
      db.exec('COMMIT');return result;
    }catch(error){db.exec('ROLLBACK');throw error;}
  },
};
function hmac(secret,input){
  return createHmac('sha256',Buffer.from(secret,'base64'))
    .update(input.join('\n')).digest('hex');
}
function post(path,obj,secret,domain,nonce=''){
  const raw=JSON.stringify(obj),ts=String(now());
  return new Request(origin+path,{method:'POST',headers:{
    'content-type':'application/json',
    'x-imagine-timestamp':ts,
    'x-imagine-signature':hmac(secret,[domain,'POST',path,ts,nonce,raw]),
  },body:raw});
}
function get(path,secret,domain,nonce){
  const ts=String(now());
  return new Request(origin+path,{headers:{
    'x-imagine-timestamp':ts,'x-imagine-witness-nonce':nonce,
    'x-imagine-signature':hmac(secret,[domain,'GET',path,ts,nonce,'']),
  }});
}
const checkSig=(r,pub,domain,schema)=>{
  const fields=schema==='f393'?[
    domain,'imagine.portal.f393.signed-ack-claim.v1',
    r.organization,r.instance,r.intent_id,r.session_sha256,
    String(r.intent_sequence),String(r.observed_sequence),
    String(r.observed_session_floor),String(r.claimed_at),
    String(r.issued_at),String(r.expires_at),r.nonce,r.status,
  ]:[
    domain,'imagine.portal.f387.revocation-intent-ack.v1',
    r.organization,r.instance,r.intent_id,r.session_sha256,
    String(r.intent_sequence),String(r.observed_sequence),
    String(r.session_floor),String(r.issued_at),
    String(r.expires_at),r.nonce,r.status,
  ];
  return verify(null,Buffer.from(fields.join('\n')),pub,
    Buffer.from(r.signature,'hex'));
};

if(phase==='seed'){
  assert.equal(existsSync(dir+'/f394-keys.json'),false);
  for(const path of ['f376-worker/schema.sql','f386-worker/intents.sql',
    'f393-worker/ack-claims.sql']){
    db.exec(readFileSync(new URL('./'+path,import.meta.url),'utf8'));
  }
  db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
    .run(organization,instance,5,4,7,12,now());
  const {privateKey,publicKey}=generateKeyPairSync('ed25519');
  const keyBytes=()=>randomBytes(32).toString('base64');
  const keys={
    WITNESS_ORGANIZATION:organization,WITNESS_INSTANCE:instance,
    WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
    PUBLISHER_HMAC_B64:keyBytes(),
    INTENT_PUBLISHER_HMAC_B64:keyBytes(),
    ACK_CLAIM_HMAC_B64:keyBytes(),
    ACK_READ_HMAC_B64:keyBytes(),
  };
  const env={...keys,DB:d1};
  const id=randomBytes(16).toString('hex');
  const intent={
    organization,instance,intent_id:id,session_sha256:seed.session_sha256,
    from_sequence:12,generation_floor:5,session_floor:5,membership_floor:7,
  };
  const ts=String(now()),raw=JSON.stringify(intent);
  const request=new Request(origin+intentPath,{method:'POST',headers:{
    'content-type':'application/json','x-imagine-timestamp':ts,
    'x-imagine-signature':hmac(keys.INTENT_PUBLISHER_HMAC_B64,
      ['imagine.portal.witness.revocation-intent.v1','POST',
       intentPath,ts,raw]),
  },body:raw});
  assert.equal((await worker.fetch(request,env)).status,204);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_intents').get().n,1);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,0);
  const nonce=randomBytes(32).toString('hex');
  const readPath=intentPath+'/'+id;
  const readTs=String(now());
  const readMac=hmac(keys.INTENT_PUBLISHER_HMAC_B64,
    ['imagine.portal.witness.revocation-intent-read.v1',
     'GET',readPath,readTs,nonce]);
  const response=await worker.fetch(new Request(origin+readPath,{headers:{
    'x-imagine-timestamp':readTs,
    'x-imagine-signature':readMac,
    'x-imagine-witness-nonce':nonce,
  }}),env);
  assert.equal(response.status,200);
  const receipt=await response.json();
  assert.equal(receipt.status,'pending_local_reconciliation');
  assert.equal(receipt.intent_id,id);
  assert.equal(receipt.session_sha256,seed.session_sha256);
  assert.ok(checkSig(receipt,publicKey,
    'imagine.portal.f387.ed25519-intent-ack-domain.v1','f387'));
  put('f394-keys.json',keys);
  put('receipt.json',receipt);
  put('expected.json',{
    organization,instance,intent_id:id,
    session_sha256:seed.session_sha256,nonce,
  });
  put('public.json',{
    hex:publicKey.export({format:'der',type:'spki'})
      .subarray(-32).toString('hex'),
  });
  db.close();
  console.log('PASS F394 seed: real Worker F376/F386/F387 recorded intent; zero ACKs prior to PG18 verification.');
}else{
  const keys=read('f394-keys.json');
  const env={...keys,DB:d1};
  const expected=read('expected.json');
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,0);
  assert.equal(existsSync(dir+'/ack-body.json'),true);
  const body=read('ack-body.json');
  assert.deepEqual(Object.keys(body),[
    'organization','instance','intent_id','session_sha256',
    'intent_sequence','local_status',
  ]);
  assert.equal(body.organization,organization);
  assert.equal(body.instance,instance);
  assert.equal(body.intent_id,expected.intent_id);
  assert.equal(body.session_sha256,seed.session_sha256);
  assert.equal(body.intent_sequence,13);
  assert.equal(body.local_status,'revoked_claimed');
  const ack=await worker.fetch(post(ackPath,body,
    keys.ACK_CLAIM_HMAC_B64,'imagine.portal.f393.ack-claim-publish.v1'),env);
  assert.equal(ack.status,204);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,1);
  const nonce=randomBytes(32).toString('hex'),p=ackPath+'/'+body.intent_id;
  const readback=await worker.fetch(get(p,keys.ACK_READ_HMAC_B64,
    'imagine.portal.f393.ack-claim-read.v1',nonce),env);
  assert.equal(readback.status,200);
  const receipt=await readback.json();
  assert.equal(receipt.status,'ack_recorded_local_claim_unverified');
  assert.equal(receipt.nonce,nonce);
  assert.equal(receipt.session_sha256,seed.session_sha256);
  const publicKeyBytes=Buffer.from(read('public.json').hex,'hex');
  assert.ok(verify(null,Buffer.from([
    'imagine.portal.f393.ed25519-ack-domain.v1',
    'imagine.portal.f393.signed-ack-claim.v1',
    receipt.organization,receipt.instance,receipt.intent_id,
    receipt.session_sha256,String(receipt.intent_sequence),
    String(receipt.observed_sequence),String(receipt.observed_session_floor),
    String(receipt.claimed_at),String(receipt.issued_at),
    String(receipt.expires_at),receipt.nonce,receipt.status,
  ].join('\n')),{
    key:Buffer.concat([
      Buffer.from('302a300506032b6570032100','hex'),publicKeyBytes,
    ]),format:'der',type:'spki',
  },Buffer.from(receipt.signature,'hex')));
  put('ack-receipt.json',receipt);
  put('ack-expected.json',{
    organization,instance,intent_id:body.intent_id,
    session_sha256:body.session_sha256,nonce,
  });
  db.close();
  console.log('PASS F394 completion: Worker F393 append-only ACK after PG18 status proof, Ed25519 receipt still only a local claim.');
}
