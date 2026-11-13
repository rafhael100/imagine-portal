import assert from 'node:assert/strict';
import { createHash, createHmac, generateKeyPairSync, randomBytes, verify } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { DatabaseSync } from 'node:sqlite';
import worker from '../f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
  ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  ||process.env.PORTAL_F386_CI!=='DISPOSABLE_ONLY')process.exit(64);
const db=new DatabaseSync(':memory:');
db.exec('PRAGMA foreign_keys=ON');
db.exec(readFileSync(new URL('../f376-worker/schema.sql',import.meta.url),'utf8'));
db.exec(readFileSync(new URL('./intents.sql',import.meta.url),'utf8'));
const org='org_f386',instance='instance_f386';
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
 .run(org,instance,5,4,7,12,Math.floor(Date.now()/1000));

function adapter() {
  return {
    prepare(sql){
      const prepared=db.prepare(sql);
      return {bind(...args){
        return {
          async first(){return prepared.get(...args)??null;},
          async run(){const x=prepared.run(...args);return {success:true,meta:{changes:Number(x.changes)}};},
          // D1.batch calls these pre-bound statements as one transaction.
          runSync(){const x=prepared.run(...args);return {success:true,meta:{changes:Number(x.changes)}};},
        };
      }};
    },
    async batch(statements){
      db.exec('BEGIN IMMEDIATE');
      try {
        const results=statements.map(s=>s.runSync());
        db.exec('COMMIT');
        return results;
      }catch(error){
        db.exec('ROLLBACK');
        throw error;
      }
    },
  };
}
const publisherKey=randomBytes(32);
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const env={
  DB:adapter(),
  WITNESS_ORGANIZATION:org,
  WITNESS_INSTANCE:instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:randomBytes(32).toString('base64'),
  INTENT_PUBLISHER_HMAC_B64:publisherKey.toString('base64'),
};
const base='https://witness.f386.test.invalid';
const url=base+'/v1/portal/revocation-intents';
const v2=base+'/v2/portal/recovery-witness';
const intentId=()=>randomBytes(16).toString('hex');
const secretToken=randomBytes(32).toString('hex');
const sessionDigest=createHash('sha256').update(secretToken).digest('hex');
const body=(id,from,session=5)=>({
  organization:org,instance,intent_id:id,session_sha256:sessionDigest,
  from_sequence:from,generation_floor:5,session_floor:session,membership_floor:7,
});
function request(payload,key=publisherKey,ts=String(Math.floor(Date.now()/1000))){
  const raw=JSON.stringify(payload);
  const signed=['imagine.portal.witness.revocation-intent.v1',
    'POST','/v1/portal/revocation-intents',ts,raw].join('\n');
  const mac=createHmac('sha256',key).update(signed).digest('hex');
  return new Request(url,{method:'POST',headers:{
    'Content-Type':'application/json',
    'X-Imagine-Timestamp':ts,
    'X-Imagine-Signature':mac,
  },body:raw});
}
const send=(p,key,ts)=>worker.fetch(request(p,key,ts),env);
const now=()=>Math.floor(Date.now()/1000);
const witness=()=>db.prepare('SELECT * FROM witness_epochs WHERE organization=? AND instance=?').get(org,instance);
const rows=()=>db.prepare('SELECT * FROM revocation_intents ORDER BY to_sequence').all();
const challenge=async()=>{
  const nonce=randomBytes(32).toString('hex');
  const r=await worker.fetch(new Request(v2,{headers:{
    'X-Imagine-Witness-Nonce':nonce,
  }}),env);
  assert.equal(r.status,200);
  assert.match(r.headers.get('cache-control'),/no-store/);
  const receipt=await r.json();
  assert.equal(receipt.nonce,nonce);
  const canonical=['imagine.portal.f379.ed25519-witness-domain.v1',
    'imagine.portal.f379.challenged-witness.v1',
    receipt.organization,receipt.instance,
    String(receipt.generation_floor),String(receipt.session_floor),
    String(receipt.membership_floor),String(receipt.sequence),
    String(receipt.issued_at),String(receipt.expires_at),receipt.nonce,
  ].join('\n');
  assert.ok(verify(null,Buffer.from(canonical),
    publicKey,Buffer.from(receipt.signature,'hex')));
  return receipt;
};

const old=await challenge();
assert.equal(old.sequence,12);
assert.equal(old.session_floor,4);
const firstId=intentId();
const first=body(firstId,12,5);
assert.equal((await worker.fetch(new Request(url,{method:'GET'}),env)).status,404);
assert.equal((await worker.fetch(new Request(url+'?tenant=other'),env)).status,404);
assert.equal((await worker.fetch(new Request(url,{method:'POST',headers:{
  'Content-Type':'application/json',
},body:JSON.stringify(first)}),env)).status,403);
assert.equal((await send(first,Buffer.alloc(32,1))).status,403);
assert.equal((await send(first,publisherKey,String(now()-180))).status,403);
assert.equal((await send({...first,organization:'other_org'})).status,422);
assert.equal((await send({...first,intent_id:'a'.repeat(31)})).status,422);
assert.equal((await send({...first,session_sha256:'not-a-hash'})).status,422);
assert.equal((await send({...first,extra:'unsafe'})).status,422);
assert.equal((await send({...first,from_sequence:true})).status,422);
assert.equal((await send({...first,session_floor:'5'})).status,422);
assert.equal(rows().length,0);
assert.equal(witness().sequence,12);
const disabled=await worker.fetch(request(first),{...env,INTENT_PUBLISHER_HMAC_B64:null});
assert.equal(disabled.status,503);
const batchUnavailable=await worker.fetch(request(first),{...env,DB:{prepare:env.DB.prepare}});
assert.equal(batchUnavailable.status,503);

const published=await send(first);
assert.equal(published.status,204);
assert.equal(published.headers.get('cache-control'),'no-store, max-age=0');
assert.equal(witness().sequence,13);
assert.equal(witness().session_floor,5);
assert.equal(rows().length,1);
assert.equal(rows()[0].intent_id,firstId);
assert.equal(rows()[0].session_sha256,sessionDigest);
assert.equal(rows()[0].to_sequence,13);
assert.ok(!JSON.stringify(rows()[0]).includes(secretToken));
const advanced=await challenge();
assert.equal(advanced.sequence,13);
assert.equal(advanced.session_floor,5);
assert.notEqual(old.signature,advanced.signature);

// F385 injected LOCAL PostgreSQL failure: the old DB record would remain
// active at epoch12 while the independent witness now says epoch13.
// The PHP interoperability test must refuse it despite valid Ed25519.
const oldSnapshot={
  organization:org,instance,generation:5,
  session_revision:4,membership_revision:7,witnessed_sequence:12,
};
const currentSnapshot={...oldSnapshot,session_revision:5,witnessed_sequence:13};

// Replay must not insert/advance; cannot silently claim durable success.
assert.equal((await send(first)).status,409);
assert.equal((await send(body(intentId(),12,6))).status,409);
assert.equal((await send(body(intentId(),13,5))).status,409); // no floor change
assert.equal((await send(body(intentId(),13,4))).status,409); // rollback
assert.equal(rows().length,1);
assert.equal(witness().sequence,13);

// Force the SECOND statement to fail AFTER first INSERT; D1 batch must
// rollback BOTH writes and not leave an orphaned intent.
db.exec("CREATE TRIGGER f386_fault BEFORE UPDATE ON witness_epochs FOR EACH ROW BEGIN SELECT RAISE(ABORT,'synthetic F386 failure'); END");
const nextId=intentId(),second=body(nextId,13,6);
assert.equal((await send(second)).status,409);
assert.equal(witness().sequence,13);
assert.equal(rows().length,1);
assert.equal(db.prepare('SELECT count(*) AS c FROM revocation_intents WHERE intent_id=?').get(nextId).c,0);
db.exec('DROP TRIGGER f386_fault');
assert.equal((await send(second)).status,204);
assert.equal(witness().sequence,14);
assert.equal(witness().session_floor,6);
assert.equal(rows().length,2);
assert.equal((await send(second)).status,409);
assert.equal(rows().length,2);

assert.throws(()=>db.prepare("UPDATE revocation_intents SET session_floor=999").run(),/mutation refused/);
assert.throws(()=>db.prepare("DELETE FROM revocation_intents").run(),/deletion refused/);
assert.throws(()=>db.prepare(
  'INSERT INTO revocation_intents(organization,instance,intent_id,session_sha256,from_sequence,to_sequence,generation_floor,session_floor,membership_floor,created_at) VALUES(?,?,?,?,?,?,?,?,?,?)'
).run(org,instance,intentId(),sessionDigest,12,13,5,8,7,now()),/CAS\/floor refused/);

// A correct Ed25519 response is NOT an HTTP success verdict for an old
// PostgreSQL snapshot; verification performed in actual PHP F379.
const dir=process.env.F386_INTEROP_DIR;
if(!dir)throw Error('F386 needs disposable interop directory');
for(const [name,data] of Object.entries({
  'receipt.json':advanced,
  'old-snapshot.json':oldSnapshot,
  'current-snapshot.json':currentSnapshot,
  'public.json':{hex:publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex')},
})){
  writeFileSync(dir+'/'+name,JSON.stringify(data),{mode:0o600});
}
db.close();
console.log('PASS F386: atomic SQLite D1 batch, HMAC revocation ledger, strict CAS, rollback, replay and signed witness after simulated local PG failure; NO SERVING.');
