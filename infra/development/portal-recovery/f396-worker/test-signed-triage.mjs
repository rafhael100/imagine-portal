/**
 * F396: real Worker F376/F386/F392/F393/F395 issues independent Ed25519
 * cursor/lease/ACK receipts for PHP triage in a separate process.
 * All data and SQL statuses are synthetic; "ACK" is intentionally
 * forged while the PHP fake PostgreSQL says the session is active.
 */
import assert from 'node:assert/strict';
import {createHash,createHmac,generateKeyPairSync,randomBytes} from 'node:crypto';
import {readFileSync,writeFileSync} from 'node:fs';
import {DatabaseSync} from 'node:sqlite';
import worker from '../f376-worker/src/witness.mjs';
import {acquireRetryLease,readRetryLease} from '../f395-worker/lease.mjs';
import {nextRevocationIntent} from '../f392-worker/cursor.mjs';
import {readAckClaim} from '../f393-worker/ack.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
    ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
    ||process.env.PORTAL_F396_CI!=='DISPOSABLE_ONLY')process.exit(64);
const folder=process.env.F396_SIGNED_FIXTURE_DIR;
if(!folder)throw new Error('missing private fixture directory');
const db=new DatabaseSync(':memory:');db.exec('PRAGMA foreign_keys=ON');
for(const file of [
    '../f376-worker/schema.sql','../f386-worker/intents.sql',
    '../f393-worker/ack-claims.sql','../f395-worker/retry-leases.sql',
]){
    db.exec(readFileSync(new URL(file,import.meta.url),'utf8'));
}
const org='org_f396',instance='instance_f396',base=Math.floor(Date.now()/1000);
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
    .run(org,instance,5,4,7,12,base);
const adapter={
    prepare(sql){const p=db.prepare(sql);return {bind(...a){return {
        async first(){return p.get(...a)??null;},
        async run(){const r=p.run(...a);return {success:true,meta:{changes:Number(r.changes)}};},
        runSync(){const r=p.run(...a);return {success:true,meta:{changes:Number(r.changes)}};},
    };}};},
    async batch(items){
        db.exec('BEGIN IMMEDIATE');
        try{const r=items.map(x=>x.runSync());db.exec('COMMIT');return r;}
        catch(error){db.exec('ROLLBACK');throw error;}
    },
};
const key=()=>randomBytes(32).toString('base64');
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const env={
    DB:adapter,
    WITNESS_ORGANIZATION:org,WITNESS_INSTANCE:instance,
    WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
    PUBLISHER_HMAC_B64:key(),INTENT_PUBLISHER_HMAC_B64:key(),
    INTENT_CURSOR_HMAC_B64:key(),ACK_CLAIM_HMAC_B64:key(),
    ACK_READ_HMAC_B64:key(),LEASE_WORKER_HMAC_B64:key(),
    LEASE_READ_HMAC_B64:key(),
};
const nonce=()=>randomBytes(32).toString('hex');
const leaseHash=()=>createHash('sha256').update(randomBytes(32)).digest('hex');
const orgPath='https://f396-ci.test.invalid';
const paths={
    intent:'/v1/portal/revocation-intents',
    cursor:'/v1/portal/revocation-intents/next',
    lease:'/v1/portal/revocation-retry-leases',
    ack:'/v1/portal/revocation-acks',
};
const id=randomBytes(16).toString('hex');
const digest=createHash('sha256').update(randomBytes(32)).digest('hex');
const intent={organization:org,instance,intent_id:id,session_sha256:digest,
    from_sequence:12,generation_floor:5,session_floor:5,membership_floor:7};
function mac(rawKey,items){
    return createHmac('sha256',Buffer.from(rawKey,'base64'))
        .update(items.join('\n')).digest('hex');
}
function post(path,body,keyName,domain,at,emptyNonce=true){
    const raw=JSON.stringify(body),timestamp=String(at);
    const canonical=emptyNonce?
        [domain,'POST',path,timestamp,'',raw]:
        [domain,'POST',path,timestamp,raw];
    return new Request(orgPath+path,{method:'POST',headers:{
        'content-type':'application/json',
        'x-imagine-timestamp':timestamp,
        'x-imagine-signature':mac(env[keyName],canonical),
    },body:raw});
}
function get(path,keyName,domain,challenge,at,after=null){
    const timestamp=String(at);
    const canonical=after===null?
        [domain,'GET',path,timestamp,challenge,'']:
        [domain,'GET',path,timestamp,challenge,String(after)];
    const headers={
        'x-imagine-timestamp':timestamp,
        'x-imagine-signature':mac(env[keyName],canonical),
        'x-imagine-witness-nonce':challenge,
    };
    if(after!==null)headers['x-imagine-after-sequence']=String(after);
    return new Request(orgPath+path,{headers});
}
const leaseBody=h=>({
    organization:org,instance,intent_id:id,
    session_sha256:digest,intent_sequence:13,lease_sha256:h,
});
async function getSignedReceipts(at,hash){
    const cursorNonce=nonce(),leaseNonce=nonce(),ackNonce=nonce(),emptyNonce=nonce();
    const cursorRequest=get(paths.cursor,'INTENT_CURSOR_HMAC_B64',
        'imagine.portal.witness.revocation-cursor.v1',cursorNonce,at,0);
    const leaseRequest=get(paths.lease+'/'+id,'LEASE_READ_HMAC_B64',
        'imagine.portal.f395.retry-lease-read.v1',leaseNonce,at);
    const ackRequest=get(paths.ack+'/'+id,'ACK_READ_HMAC_B64',
        'imagine.portal.f393.ack-claim-read.v1',ackNonce,at);
    const emptyRequest=get(paths.cursor,'INTENT_CURSOR_HMAC_B64',
        'imagine.portal.witness.revocation-cursor.v1',emptyNonce,at,13);
    const out=await Promise.all([
        nextRevocationIntent(cursorRequest,env,org,instance,at),
        readRetryLease(leaseRequest,env,org,instance,at),
        readAckClaim(ackRequest,env,org,instance,at),
        nextRevocationIntent(emptyRequest,env,org,instance,at),
    ]);
    for(const response of out)assert.equal(response.status,200);
    const [cursor,lease,ack,empty]=await Promise.all(out.map(x=>x.json()));
    assert.equal(cursor.status,'pending_local_reconciliation');
    assert.equal(cursor.intent_id,id);assert.equal(cursor.session_sha256,digest);
    assert.equal(lease.lease_sha256,hash);
    assert.equal(ack.status,'ack_recorded_local_claim_unverified');
    assert.equal(empty.status,'no_intent_after_cursor');
    return {cursor,lease,ack,empty,nonces:{
        cursor:cursorNonce,lease:leaseNonce,ack:ackNonce,empty:emptyNonce,
    },at};
}

const first=leaseHash();
const initial=post(paths.intent,intent,'INTENT_PUBLISHER_HMAC_B64',
    'imagine.portal.witness.revocation-intent.v1',base,false);
assert.equal((await worker.fetch(initial,env)).status,204);
assert.equal((await acquireRetryLease(post(paths.lease,leaseBody(first),
    'LEASE_WORKER_HMAC_B64','imagine.portal.f395.retry-lease-claim.v1',base),
    env,org,instance,base)).status,204);
// Intentionally publish an ACK even though no PostgreSQL was consulted:
const forgedAck={organization:org,instance,intent_id:id,
    session_sha256:digest,intent_sequence:13,local_status:'revoked_claimed'};
assert.equal((await worker.fetch(post(paths.ack,forgedAck,
    'ACK_CLAIM_HMAC_B64','imagine.portal.f393.ack-claim-publish.v1',base),env)).status,204);
const active=await getSignedReceipts(base,first);
assert.equal(active.lease.attempt_count,1);
assert.equal(active.lease.status,'retry_lease_observed_not_completion');
const expired=await getSignedReceipts(base+30,first);
assert.equal(expired.lease.status,'retry_lease_expired_not_completion');

let latest=null;
for(let i=2;i<=5;i++){
    const t=base+31+31*(i-2);
    const h=leaseHash();
    assert.equal((await acquireRetryLease(post(paths.lease,leaseBody(h),
        'LEASE_WORKER_HMAC_B64','imagine.portal.f395.retry-lease-claim.v1',t),
      env,org,instance,t)).status,204);
    latest=await getSignedReceipts(t,h);
    assert.equal(latest.lease.attempt_count,i);
}
assert.equal(latest.lease.attempt_count,5);
const publicHex=publicKey.export({format:'der',type:'spki'})
    .subarray(-32).toString('hex');
for(const [name,obj] of Object.entries({
    'active.json':active,'expired.json':expired,'exhausted.json':latest,
    'expected.json':{organization:org,instance,intent_id:id,
        session_sha256:digest,
        active_lease_hash:first,
        exhausted_lease_hash:latest.lease.lease_sha256},
    'public.json':{hex:publicHex},
})){
    writeFileSync(folder+'/'+name,JSON.stringify(obj),{mode:0o600});
}
db.close();
console.log('PASS F396 Worker fixture: real signed cursor + active/expired/exhausted lease + deliberately untrusted forged ACK, no serving.');
