/**
 * F397 disposable, actual F376/F386/F392/F393/F395 Worker signer.
 * Organization and first digest come from a genuine PG18 test identity.
 * The deliberately forged ACK is published BEFORE any PG18 status read,
 * proving that the independent ledger cannot be trusted as local SQL proof.
 */
import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from './f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F397_CI!=='DISPOSABLE_ONLY'
  ||process.argv.length!==3)process.exit(64);
const dir=process.argv[2];
const seed=JSON.parse(readFileSync(dir+'/seed.json','utf8'));
const org=seed.organization,instance=seed.instance;
assert.match(org,/^[a-f0-9-]{36}$/);
assert.equal(instance,'f390_ci');
assert.match(seed.session_sha256,/^[a-f0-9]{64}$/);
assert.equal(Object.hasOwn(seed,'session_reference'),false);
const db=new DatabaseSync(':memory:');
db.exec('PRAGMA foreign_keys=ON');
for(const file of [
  'f376-worker/schema.sql','f386-worker/intents.sql',
  'f393-worker/ack-claims.sql','f395-worker/retry-leases.sql',
]){
  db.exec(readFileSync(new URL('./'+file,import.meta.url),'utf8'));
}
const now=Math.floor(Date.now()/1000);
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
  .run(org,instance,5,4,7,12,now);
const dbAdapter={
  prepare(sql){
    const q=db.prepare(sql);
    return {bind(...args){return {
      async first(){return q.get(...args)??null;},
      async run(){const r=q.run(...args);return {success:true,meta:{changes:Number(r.changes)}};},
      runSync(){const r=q.run(...args);return {success:true,meta:{changes:Number(r.changes)}};},
    };}};
  },
  async batch(items){
    db.exec('BEGIN IMMEDIATE');
    try{const r=items.map(s=>s.runSync());db.exec('COMMIT');return r;}
    catch(e){db.exec('ROLLBACK');throw e;}
  },
};
const key=()=>randomBytes(32).toString('base64');
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const env={
  DB:dbAdapter,WITNESS_ORGANIZATION:org,WITNESS_INSTANCE:instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:key(),INTENT_PUBLISHER_HMAC_B64:key(),
  INTENT_CURSOR_HMAC_B64:key(),ACK_CLAIM_HMAC_B64:key(),
  ACK_READ_HMAC_B64:key(),LEASE_WORKER_HMAC_B64:key(),
  LEASE_READ_HMAC_B64:key(),
};
const origin='https://f397.pg18.test.invalid';
const paths={
  intent:'/v1/portal/revocation-intents',
  lease:'/v1/portal/revocation-retry-leases',
  ack:'/v1/portal/revocation-acks',
  cursor:'/v1/portal/revocation-intents/next',
};
const hmac=(secret,parts)=>createHmac('sha256',Buffer.from(secret,'base64'))
  .update(parts.join('\n')).digest('hex');
function post(path,body,keyName,domain,emptyNonce=true){
  const raw=JSON.stringify(body),ts=String(now);
  const parts=emptyNonce?[domain,'POST',path,ts,'',raw]:
    [domain,'POST',path,ts,raw];
  return new Request(origin+path,{method:'POST',headers:{
    'content-type':'application/json',
    'x-imagine-timestamp':ts,
    'x-imagine-signature':hmac(env[keyName],parts),
  },body:raw});
}
function get(path,keyName,domain,n,after=null){
  const ts=String(now);
  const parts=after===null?[domain,'GET',path,ts,n,'']:
    [domain,'GET',path,ts,n,String(after)];
  const headers={
    'x-imagine-timestamp':ts,
    'x-imagine-witness-nonce':n,
    'x-imagine-signature':hmac(env[keyName],parts),
  };
  if(after!==null)headers['x-imagine-after-sequence']=String(after);
  return new Request(origin+path,{headers});
}
const nonce=()=>randomBytes(32).toString('hex');
const intentID=()=>randomBytes(16).toString('hex');
const leaseHash=()=>createHash('sha256').update(randomBytes(32)).digest('hex');
async function publishIntent(id,digest,from,floor){
  const data={
    organization:org,instance,intent_id:id,session_sha256:digest,
    from_sequence:from,generation_floor:5,
    session_floor:floor,membership_floor:7,
  };
  assert.equal((await worker.fetch(post(paths.intent,data,'INTENT_PUBLISHER_HMAC_B64',
    'imagine.portal.witness.revocation-intent.v1',false),env)).status,204);
}
async function publishLease(id,digest,seq,hash){
  const data={
    organization:org,instance,intent_id:id,session_sha256:digest,
    intent_sequence:seq,lease_sha256:hash,
  };
  assert.equal((await worker.fetch(post(paths.lease,data,'LEASE_WORKER_HMAC_B64',
    'imagine.portal.f395.retry-lease-claim.v1'),env)).status,204);
}
async function fetchReceipt(path,keyName,domain){
  const n=nonce();
  const response=await worker.fetch(get(path,keyName,domain,n),env);
  assert.equal(response.status,200);
  const receipt=await response.json();
  assert.equal(receipt.nonce,n);
  return {receipt,nonce:n};
}
async function cursor(after,id,digest){
  const n=nonce();
  const r=await worker.fetch(get(paths.cursor,'INTENT_CURSOR_HMAC_B64',
    'imagine.portal.witness.revocation-cursor.v1',n,after),env);
  assert.equal(r.status,200);
  const data=await r.json();
  assert.equal(data.intent_id,id);
  assert.equal(data.session_sha256,digest);
  return {receipt:data,nonce:n,after};
}
const firstID=intentID(),firstHash=seed.session_sha256;
const missingID=intentID();
const missingHash=createHash('sha256').update('f397-does-not-exist-in-postgres')
  .digest('hex');
assert.notEqual(firstHash,missingHash);
await publishIntent(firstID,firstHash,12,5);
await publishIntent(missingID,missingHash,13,6);
assert.equal(db.prepare('SELECT sequence FROM witness_epochs').get().sequence,14);
const firstLease=leaseHash(),missingLease=leaseHash();
await publishLease(firstID,firstHash,13,firstLease);
await publishLease(missingID,missingHash,14,missingLease);

// Deliberate false ACK, using a syntactically legitimate publisher key:
// the Worker has NO PostgreSQL connection, so this is not evidence of SQL.
const fraudulent={
  organization:org,instance,intent_id:firstID,
  session_sha256:firstHash,intent_sequence:13,
  local_status:'revoked_claimed',
};
assert.equal((await worker.fetch(post(paths.ack,fraudulent,'ACK_CLAIM_HMAC_B64',
  'imagine.portal.f393.ack-claim-publish.v1'),env)).status,204);
const first={
  cursor:await cursor(0,firstID,firstHash),
  lease:await fetchReceipt(paths.lease+'/'+firstID,
    'LEASE_READ_HMAC_B64','imagine.portal.f395.retry-lease-read.v1'),
  ack:await fetchReceipt(paths.ack+'/'+firstID,
    'ACK_READ_HMAC_B64','imagine.portal.f393.ack-claim-read.v1'),
};
const missing={
  cursor:await cursor(13,missingID,missingHash),
  lease:await fetchReceipt(paths.lease+'/'+missingID,
    'LEASE_READ_HMAC_B64','imagine.portal.f395.retry-lease-read.v1'),
};
assert.equal(first.lease.receipt.attempt_count,1);
assert.equal(first.lease.receipt.lease_sha256,firstLease);
assert.equal(first.ack.receipt.status,'ack_recorded_local_claim_unverified');
assert.equal(missing.lease.receipt.lease_sha256,missingLease);
assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_intents').get().n,2);
assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_ack_claims').get().n,1);
assert.equal(db.prepare('SELECT COUNT(*) AS n FROM revocation_retry_leases').get().n,2);
const output={
  organization:org,instance,created_at:now,
  first,missing,
  public:publicKey.export({format:'der',type:'spki'})
    .subarray(-32).toString('hex'),
};
assert.ok(!JSON.stringify(output).includes('session_reference'));
writeFileSync(dir+'/f397-receipts.json',JSON.stringify(output),
  {mode:0o600,flag:'wx'});
db.close();
console.log('PASS F397 Worker: real F376/F386/F392/F393/F395 signed 2 PG18-scoped intents, 2 leases and deliberately false ACK before any PG check.');
