/**
 * F380 GitHub-only HTTPS test of actual F376 v2 Worker endpoint.
 * One ephemeral TLS certificate and one disposable Ed25519 signing key.
 * Binds localhost only and never provisions Cloudflare, D1 or the Portal VPS.
 */
import https from 'node:https';
import { DatabaseSync } from 'node:sqlite';
import { readFileSync, writeFileSync, appendFileSync } from 'node:fs';
import { generateKeyPairSync } from 'node:crypto';
import worker from './f376-worker/src/witness.mjs';

if(process.env.GITHUB_ACTIONS!=='true'
    ||process.env.RUNNER_ENVIRONMENT!=='github-hosted'
    ||process.env.PORTAL_F380_CI!=='DISPOSABLE_ONLY'
    ||process.argv.length!==5)process.exit(64);
const [root,cert,key]=process.argv.slice(2);
// F381 only: a trusted local PG18 seed exports its *synthetic* random tenant
// after provisioning. No request can select or override this scope.
const f381 = process.env.PORTAL_F381_CI === 'DISPOSABLE_ONLY'
    && process.env.GITHUB_ACTIONS === 'true'
    && process.env.RUNNER_ENVIRONMENT === 'github-hosted';
const seeded = f381
    ? JSON.parse(readFileSync(root + '/scope.json','utf8'))
    : {organization:'org_f380',instance:'instance_f380'};
if (typeof seeded.organization !== 'string'
    || !/^[a-z0-9_-]{1,64}$/.test(seeded.organization)
    || typeof seeded.instance !== 'string'
    || !/^[a-z0-9_-]{1,64}$/.test(seeded.instance)) {
  throw new Error('F381 synthetic seed scope invalid');
}

const schema=readFileSync(new URL('./f376-worker/schema.sql',import.meta.url),'utf8');
function database(sessionFloor,sequence){
  const db=new DatabaseSync(':memory:');
  db.exec(schema);
  db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
    .run(seeded.organization,seeded.instance,5,sessionFloor,7,sequence,Math.floor(Date.now()/1000));
  return db;
}
function adapter(db) {
  return {
    prepare(sql) {
      const stmt=db.prepare(sql);
      return {bind(...args){
        return {
          async first(){return stmt.get(...args)??null;},
          async run(){const r=stmt.run(...args);return {success:true,meta:{changes:Number(r.changes)}};},
        };
      }};
    },
  };
}
const normal=database(4,13);
const advanced=database(5,14);
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
writeFileSync(root+'/ed-public.hex',
    publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex'),{mode:0o600});
const shared={
  WITNESS_ORGANIZATION:seeded.organization,
  WITNESS_INSTANCE:seeded.instance,
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:Buffer.alloc(32,9).toString('base64'),
};
let lastResponse='';
const server=https.createServer({cert:readFileSync(cert),key:readFileSync(key)},async(req,res)=>{
  try {
    if(req.method!=='GET'||req.url!=='/v2/portal/recovery-witness'){
      res.writeHead(404,{'Cache-Control':'no-store'});res.end();return;
    }
    const mode=readFileSync(root+'/scenario','utf8').trim();
    const nonce=String(req.headers['x-imagine-witness-nonce']??'');
    appendFileSync(root+'/requests.jsonl',JSON.stringify({mode,nonce})+'\n',{mode:0o600});
    if(mode==='unavailable'){
      res.writeHead(503,{'Cache-Control':'no-store'});res.end();return;
    }
    if(mode==='timeout'){
      await new Promise(resolve=>setTimeout(resolve,2100));
      res.writeHead(503,{'Cache-Control':'no-store'});res.end();return;
    }
    if(mode==='redirect'){
      res.writeHead(302,{'Location':'https://other.invalid/no-serving','Cache-Control':'no-store'});res.end();return;
    }
    if(mode==='oversized'){
      res.writeHead(200,{'Content-Type':'application/json','Cache-Control':'no-store'});
      res.end('X'.repeat(5000));return;
    }
    if(mode==='huge-headers'){
      res.writeHead(200,{'Content-Type':'application/json','X-Huge':'a'.repeat(12000)});
      res.end('{}');return;
    }
    const headers=new Headers();
    headers.set('X-Imagine-Witness-Nonce',nonce);
    const env={...shared,DB:adapter(mode==='advanced'?advanced:normal)};
    const upstreamPath=mode==='legacy'?'/v1/portal/recovery-witness':req.url;
    const upstream=await worker.fetch(new Request('https://witness.f380.test.invalid'+upstreamPath,{headers}),env);
    let raw=await upstream.text();
    if(mode==='replay' && lastResponse!=='')raw=lastResponse;
    if(mode==='wrong-mime'){
      res.writeHead(200,{'Content-Type':'text/plain','Cache-Control':'no-store'});res.end(raw);return;
    }
    if(mode==='tamper'){
      const obj=JSON.parse(raw);
      obj.signature=obj.signature.replace(/^./,obj.signature[0]==='0'?'1':'0');
      raw=JSON.stringify(obj);
    }
    if(mode==='ok')lastResponse=raw;
    res.writeHead(upstream.status,{
      'Content-Type':upstream.headers.get('content-type')??'application/json',
      'Cache-Control':'no-store',
      'Content-Length':Buffer.byteLength(raw),
    });
    res.end(raw);
  }catch{
    res.writeHead(503,{'Cache-Control':'no-store'});
    res.end();
  }
});
server.listen(18446,'127.0.0.1',()=>console.log('F380 localhost signed nonce fixture ready'));
