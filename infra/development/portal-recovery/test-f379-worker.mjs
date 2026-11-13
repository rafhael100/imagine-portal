import assert from 'node:assert/strict';
import { createHmac, generateKeyPairSync, randomBytes, verify as edVerify } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { readFileSync, writeFileSync } from 'node:fs';
import worker from './f376-worker/src/witness.mjs';

const database=new DatabaseSync(':memory:');
database.exec(readFileSync(new URL('./f376-worker/schema.sql',import.meta.url),'utf8'));
database.prepare(
 'INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)',
).run('org_f379','instance_f379',5,3,7,12,Math.floor(Date.now()/1000));
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const publisherSecret=randomBytes(32);
const env={
 DB:{
  prepare(sql){
   const statement=database.prepare(sql);
   return {bind(...args){
    return {
     async first(){return statement.get(...args)??null;},
     async run(){const result=statement.run(...args);return {success:true,meta:{changes:Number(result.changes)}};},
    };
   }};
  },
 },
 WITNESS_ORGANIZATION:'org_f379',
 WITNESS_INSTANCE:'instance_f379',
 WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
 PUBLISHER_HMAC_B64:publisherSecret.toString('base64'),
};
const origin='https://witness.f379.test.invalid';
const nonce1=randomBytes(32).toString('hex');
const nonce2=randomBytes(32).toString('hex');
assert.notEqual(nonce1,nonce2);

async function challenged(nonce) {
 const headers=new Headers();
 if(nonce!==null)headers.set('X-Imagine-Witness-Nonce',nonce);
 return worker.fetch(new Request(origin+'/v2/portal/recovery-witness',{headers}),env);
}
function validSignature(receipt){
 const canonical=[
  'imagine.portal.f379.ed25519-witness-domain.v1',
  'imagine.portal.f379.challenged-witness.v1',
  receipt.organization,receipt.instance,
  String(receipt.generation_floor),String(receipt.session_floor),
  String(receipt.membership_floor),String(receipt.sequence),
  String(receipt.issued_at),String(receipt.expires_at),receipt.nonce,
 ].join('\n');
 return edVerify(null,Buffer.from(canonical),publicKey,
  Buffer.from(receipt.signature,'hex'));
}
assert.equal((await challenged(null)).status,400);
assert.equal((await challenged('X'.repeat(64))).status,400);
assert.equal((await challenged('a'.repeat(63))).status,400);
assert.equal((await worker.fetch(new Request(origin+'/v2/portal/recovery-witness?nonce='+nonce1,{
 headers:{'X-Imagine-Witness-Nonce':nonce1}
}),env)).status,404);
const firstResponse=await challenged(nonce1);
assert.equal(firstResponse.status,200);
assert.match(firstResponse.headers.get('cache-control'),/no-store/);
const oldReceipt=await firstResponse.json();
assert.deepEqual(Object.keys(oldReceipt),[
 'schema','organization','instance','generation_floor','session_floor',
 'membership_floor','sequence','issued_at','expires_at','nonce','signature',
]);
assert.equal(oldReceipt.nonce,nonce1);
assert.equal(oldReceipt.sequence,12);
assert.equal(oldReceipt.session_floor,3);
assert.equal(oldReceipt.expires_at-oldReceipt.issued_at,30);
assert.equal(validSignature(oldReceipt),true);
const v1Response=await worker.fetch(new Request(origin+'/v1/portal/recovery-witness'),env);
assert.equal(v1Response.status,200);
const legacyReceipt=await v1Response.json();
assert.notEqual(legacyReceipt.schema,oldReceipt.schema);
assert.equal('nonce' in legacyReceipt,false);

const updateBody={
 organization:'org_f379',
 instance:'instance_f379',
 from_sequence:12,
 generation_floor:5,
 session_floor:4,
 membership_floor:7,
};
const raw=JSON.stringify(updateBody);
const now=String(Math.floor(Date.now()/1000));
const mac=createHmac('sha256',publisherSecret).update([
 'imagine.portal.witness.publish.v1','POST',
 '/v1/portal/witness-advance',now,raw,
].join('\n')).digest('hex');
const post=await worker.fetch(new Request(origin+'/v1/portal/witness-advance',{
 method:'POST',
 headers:{
  'Content-Type':'application/json',
  'X-Imagine-Timestamp':now,
  'X-Imagine-Signature':mac,
 },
 body:raw,
}),env);
assert.equal(post.status,204);
assert.equal((await challenged('not-a-nonce')).status,400);

const secondResponse=await challenged(nonce2);
assert.equal(secondResponse.status,200);
const newReceipt=await secondResponse.json();
assert.equal(newReceipt.sequence,13);
assert.equal(newReceipt.session_floor,4);
assert.equal(newReceipt.nonce,nonce2);
assert.equal(validSignature(newReceipt),true);
assert.notEqual(newReceipt.signature,oldReceipt.signature);
assert.equal(validSignature({...newReceipt,nonce:nonce1}),false);
assert.equal(validSignature({...oldReceipt,nonce:nonce2}),false);
const oldSnapshot={
 organization:'org_f379',instance:'instance_f379',
 generation:5,session_revision:3,membership_revision:7,witnessed_sequence:12,
};
const newSnapshot={...oldSnapshot,session_revision:4,witnessed_sequence:13};
const dir=process.env.F379_INTEROP_DIR;
if(!dir)throw new Error('F379 requires isolated interop directory');
for(const [name,value]of Object.entries({
 'old-receipt.json':oldReceipt,
 'new-receipt.json':newReceipt,
 'legacy-receipt.json':legacyReceipt,
 'old-snapshot.json':oldSnapshot,
 'new-snapshot.json':newSnapshot,
 'nonces.json':{first:nonce1,second:nonce2},
 'public-key.json':{hex:publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex')},
})){
 writeFileSync(dir+'/'+name,JSON.stringify(value),{mode:0o600});
}
database.close();
console.log('PASS F379 JS: fresh 256-bit nonce, signed challenged Ed25519, Worker CAS, legacy and replay negative.');
