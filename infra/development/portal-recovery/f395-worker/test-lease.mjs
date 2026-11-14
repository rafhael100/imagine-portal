/**
 * F395 test -- two separate Node24 process invocations with file-backed
 * SQLite, emulating Worker restart with the independent ledger surviving.
 * Clock advances are injected to test 30s expiration without waiting.
 */
import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes,verify} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from '../f376-worker/src/witness.mjs';
import {acquireRetryLease,readRetryLease} from './lease.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F395_CI!=='DISPOSABLE_ONLY'
  ||process.argv.length!==4
  ||!['seed','restart'].includes(process.argv[2]))process.exit(64);
const phase=process.argv[2],dir=process.argv[3];
const db=new DatabaseSync(dir+'/f395-ledger.sqlite');
db.exec('PRAGMA foreign_keys=ON');
const read=name=>JSON.parse(readFileSync(dir+'/'+name,'utf8'));
const put=(name,value)=>writeFileSync(dir+'/'+name,
  JSON.stringify(value),{mode:0o600,flag:'wx'});
const org='org_f395',instance='instance_f395';
const base=Math.floor(Date.now()/1000);
function adapter(){
  return {
    prepare(sql){
      const stmt=db.prepare(sql);
      return {bind(...args){return {
        async first(){return stmt.get(...args)??null;},
        async run(){const result=stmt.run(...args);
          return {success:true,meta:{changes:Number(result.changes)}};},
        runSync(){const result=stmt.run(...args);
          return {success:true,meta:{changes:Number(result.changes)}};},
      };}};
    },
    async batch(statements){
      db.exec('BEGIN IMMEDIATE');
      try{const out=statements.map(x=>x.runSync());
        db.exec('COMMIT');return out;}
      catch(e){db.exec('ROLLBACK');throw e;}
    },
  };
}
const keys=phase==='seed'?
  (()=>{
    const kp=generateKeyPairSync('ed25519');
    const secret=()=>randomBytes(32).toString('base64');
    const value={
      WITNESS_ORGANIZATION:org,WITNESS_INSTANCE:instance,
      WITNESS_SIGNING_PKCS8_B64:kp.privateKey.export({
        type:'pkcs8',format:'der',
      }).toString('base64'),
      PUBLISHER_HMAC_B64:secret(),
      INTENT_PUBLISHER_HMAC_B64:secret(),
      ACK_CLAIM_HMAC_B64:secret(),
      ACK_READ_HMAC_B64:secret(),
      LEASE_WORKER_HMAC_B64:secret(),
      LEASE_READ_HMAC_B64:secret(),
    };
    put('keys.json',value);
    put('public.json',{
      hex:kp.publicKey.export({type:'spki',format:'der'})
        .subarray(-32).toString('hex'),
    });
    return value;
  })():read('keys.json');
const env={...keys,DB:adapter()};
const origin='https://f395.invalid.test';
const leasePath='/v1/portal/revocation-retry-leases';
const intentPath='/v1/portal/revocation-intents';
const ackPath='/v1/portal/revocation-acks';
const intent=phase==='seed'?
  (()=>{
    const value={
      organization:org,instance,intent_id:randomBytes(16).toString('hex'),
      session_sha256:createHash('sha256').update(randomBytes(32)).digest('hex'),
      from_sequence:12,generation_floor:5,session_floor:5,membership_floor:7,
    };
    put('intent.json',value);
    return value;
  })():read('intent.json');
const nonce=()=>randomBytes(32).toString('hex');
const leaseDigest=()=>createHash('sha256').update(randomBytes(32)).digest('hex');
const mac=(key,items)=>createHmac('sha256',
  Buffer.from(key,'base64')).update(items.join('\n')).digest('hex');
function post(path,body,key,domain,now,withEmptyNonce=true){
  const raw=JSON.stringify(body),ts=String(now);
  const parts=withEmptyNonce
    ?[domain,'POST',path,ts,'',raw]
    :[domain,'POST',path,ts,raw];
  return new Request(origin+path,{method:'POST',headers:{
    'content-type':'application/json',
    'x-imagine-timestamp':ts,
    'x-imagine-signature':mac(key,parts),
  },body:raw});
}
function readLeaseRequest(id,n,now,key=keys.LEASE_READ_HMAC_B64){
  const path=leasePath+'/'+id,ts=String(now);
  return new Request(origin+path,{headers:{
    'x-imagine-witness-nonce':n,
    'x-imagine-timestamp':ts,
    'x-imagine-signature':mac(key,[
      'imagine.portal.f395.retry-lease-read.v1','GET',path,ts,n,'',
    ]),
  }});
}
function leaseBody(token){
  return {
    organization:org,instance,
    intent_id:intent.intent_id,
    session_sha256:intent.session_sha256,
    intent_sequence:13,
    lease_sha256:token,
  };
}
function claim(token,now,key=keys.LEASE_WORKER_HMAC_B64){
  return post(leasePath,leaseBody(token),key,
    'imagine.portal.f395.retry-lease-claim.v1',now);
}
const leaseRow=()=>db.prepare('SELECT * FROM revocation_retry_leases').get();
function canonical(r){
  return [
    'imagine.portal.f395.signed-retry-lease-domain.v1',
    'imagine.portal.f395.signed-retry-lease.v1',
    r.organization,r.instance,r.intent_id,r.session_sha256,
    r.lease_sha256,String(r.intent_sequence),String(r.attempt_count),
    String(r.acquired_at),String(r.lease_until),
    String(r.observed_sequence),String(r.issued_at),
    String(r.expires_at),r.nonce,r.status,
  ].join('\n');
}
function signatureOk(r){
  const der=Buffer.concat([
    Buffer.from('302a300506032b6570032100','hex'),
    Buffer.from(read('public.json').hex,'hex'),
  ]);
  return verify(null,Buffer.from(canonical(r)),
    {key:der,format:'der',type:'spki'},Buffer.from(r.signature,'hex'));
}
async function inspect(at,n=nonce()){
  const r=await readRetryLease(readLeaseRequest(intent.intent_id,n,at),
    env,org,instance,at);
  assert.equal(r.status,200);
  assert.match(r.headers.get('cache-control'),/no-store/);
  const receipt=await r.json();
  assert.equal(receipt.nonce,n);
  assert.ok(signatureOk(receipt));
  assert.equal(receipt.session_sha256,intent.session_sha256);
  assert.equal(receipt.intent_id,intent.intent_id);
  return receipt;
}
if(phase==='seed'){
  for(const name of ['../f376-worker/schema.sql','../f386-worker/intents.sql',
    '../f393-worker/ack-claims.sql','./retry-leases.sql']){
    db.exec(readFileSync(new URL(name,import.meta.url),'utf8'));
  }
  db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
    .run(org,instance,5,4,7,12,base);
  const first=leaseDigest();
  put('first-lease.json',{hash:first});
  assert.equal((await worker.fetch(claim(first,base),env)).status,409);
  assert.equal((await worker.fetch(
    post(intentPath,intent,keys.INTENT_PUBLISHER_HMAC_B64,
      'imagine.portal.witness.revocation-intent.v1',base,false),env,
  )).status,204);
  assert.equal(db.prepare('SELECT sequence FROM witness_epochs').get().sequence,13);
  assert.equal((await worker.fetch(claim(first,base,keys.INTENT_PUBLISHER_HMAC_B64),env)).status,403);
  assert.equal((await worker.fetch(claim(first,base,keys.ACK_CLAIM_HMAC_B64),env)).status,403);
  assert.equal((await worker.fetch(claim(first,base),env)).status,204);
  assert.equal(leaseRow().attempt_count,1);
  assert.equal(leaseRow().lease_until,base+30);
  assert.equal((await worker.fetch(claim(first,base),env)).status,409);
  assert.equal((await worker.fetch(claim(leaseDigest(),base),env)).status,409);
  assert.equal(leaseRow().attempt_count,1);

  // A forged "completion" claim exists even without any PG runtime. It
  // MUST NOT change work availability or allow authorization/queue skip.
  const ack={
    organization:org,instance,intent_id:intent.intent_id,
    session_sha256:intent.session_sha256,
    intent_sequence:13,local_status:'revoked_claimed',
  };
  assert.equal((await worker.fetch(post(ackPath,ack,keys.ACK_CLAIM_HMAC_B64,
    'imagine.portal.f393.ack-claim-publish.v1',base),env)).status,204);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,1);
  assert.equal(leaseRow().attempt_count,1);
  const firstReceipt=await inspect(base);
  assert.equal(firstReceipt.status,'retry_lease_observed_not_completion');
  assert.equal(firstReceipt.attempt_count,1);
  assert.ok(!JSON.stringify(firstReceipt).includes('postgres_revoked'));
  assert.equal((await worker.fetch(readLeaseRequest(
    intent.intent_id,nonce(),base,keys.LEASE_WORKER_HMAC_B64),env)).status,403);
  assert.equal((await worker.fetch(readLeaseRequest(
    intent.intent_id,'wrong',base),env)).status,400);
  assert.equal((await worker.fetch(readLeaseRequest(
    'a'.repeat(31),nonce(),base),env)).status,404);
  assert.equal((await worker.fetch(readLeaseRequest(
    randomBytes(16).toString('hex'),nonce(),base),env)).status,404);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_retry_leases').get().n,1);

  put('first-receipt.json',firstReceipt);
  put('base.json',{time:base});
  db.close();
  console.log('PASS F395 seed: actual Worker persists 1 bounded lease, rejects concurrent claims, forged ACK cannot mark work complete.');
}else{
  const started=read('base.json').time;
  assert.equal(leaseRow().attempt_count,1);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,1);
  const oldHash=leaseRow().lease_sha256;
  // A separate process restarted while the lease was still active.
  assert.equal((await acquireRetryLease(claim(leaseDigest(),started+5),
    env,org,instance,started+5)).status,409);
  assert.equal(leaseRow().attempt_count,1);
  assert.equal((await acquireRetryLease(claim(oldHash,started+31),
    env,org,instance,started+31)).status,409);
  const expired=await inspect(started+30);
  assert.equal(expired.status,'retry_lease_expired_not_completion');
  let last;
  for(let attempt=2;attempt<=5;attempt++){
    const ts=started+31+(attempt-2)*31;
    const hash=leaseDigest();
    assert.equal((await acquireRetryLease(claim(hash,ts),
      env,org,instance,ts)).status,204);
    assert.equal(leaseRow().attempt_count,attempt);
    assert.equal(leaseRow().lease_sha256,hash);
    assert.equal(leaseRow().lease_until,ts+30);
    last=await inspect(ts);
    assert.equal(last.status,'retry_lease_observed_not_completion');
    assert.equal(last.attempt_count,attempt);
    assert.equal((await acquireRetryLease(claim(leaseDigest(),ts),
      env,org,instance,ts)).status,409);
  }
  assert.equal(leaseRow().attempt_count,5);
  assert.equal((await acquireRetryLease(
    claim(leaseDigest(),started+31+4*31),
    env,org,instance,started+31+4*31)).status,409);
  assert.equal(leaseRow().attempt_count,5);
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,1);
  assert.throws(()=>db.prepare(
    'DELETE FROM revocation_retry_leases').run(),/deletion refused/);
  assert.throws(()=>db.prepare(
    "UPDATE revocation_retry_leases SET attempt_count=0",
  ).run(),/unsafe lease re-acquisition/);

  // Signed proof must reject a privileged witness rollback even when
  // the table of retry attempts survived.
  db.exec('DROP TRIGGER witness_epoch_monotonic');
  db.prepare('UPDATE witness_epochs SET sequence=12,session_floor=4').run();
  const afterRollback=await readRetryLease(
    readLeaseRequest(intent.intent_id,nonce(),started+150),env,
    org,instance,started+150);
  assert.equal(afterRollback.status,503);
  db.prepare('UPDATE witness_epochs SET sequence=13,session_floor=5').run();

  assert.ok(!signatureOk({...last,lease_sha256:'f'.repeat(64)}));
  put('receipt.json',last);
  put('expected.json',{
    organization:org,instance,
    intent_id:intent.intent_id,
    session_sha256:intent.session_sha256,
    lease_sha256:last.lease_sha256,
    nonce:last.nonce,at:last.issued_at,
  });
  db.close();
  console.log('PASS F395 restart: persistent ledger renews 5 one-at-a-time leases, prevents ABA/replay, exhaustion, forged ACK skipping and witness rollback; NO SERVING.');
}
