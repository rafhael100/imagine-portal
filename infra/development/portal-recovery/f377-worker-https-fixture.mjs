/**
 * F377 CI-only localhost HTTPS proxy to the ACTUAL F376 Worker module,
 * backed by a disposable in-memory SQLite adapter for D1.
 * Never run this fixture on a public interface or deploy.
 */
import https from 'node:https';
import { DatabaseSync } from 'node:sqlite';
import { readFileSync, writeFileSync, appendFileSync } from 'node:fs';
import { generateKeyPairSync } from 'node:crypto';
import worker from './f376-worker/src/witness.mjs';

if (process.env.GITHUB_ACTIONS !== 'true'
    || process.env.RUNNER_ENVIRONMENT !== 'github-hosted'
    || process.env.PORTAL_F377_CI !== 'DISPOSABLE_ONLY'
    || process.argv.length !== 5) process.exit(64);

const [root, cert, key] = process.argv.slice(2);
const schema = readFileSync(new URL('./f376-worker/schema.sql', import.meta.url), 'utf8');
const db = new DatabaseSync(':memory:');
db.exec(schema);
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
  .run('org_f377','instance_f377',5,3,7,12,Math.floor(Date.now()/1000));
const privateKey = generateKeyPairSync('ed25519').privateKey;
const publisherSecret = readFileSync(root + '/publisher-key.b64','utf8').trim();

function adapter(db) {
  return {
    prepare(sql) {
      const stmt = db.prepare(sql);
      return {
        bind(...args) {
          return {
            async first() { return stmt.get(...args) ?? null; },
            async run() {
              const result = stmt.run(...args);
              return {success:true,meta:{changes:Number(result.changes)}};
            },
          };
        },
      };
    },
  };
}
const env={
  DB:adapter(db),
  WITNESS_ORGANIZATION:'org_f377',
  WITNESS_INSTANCE:'instance_f377',
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({type:'pkcs8',format:'der'}).toString('base64'),
  PUBLISHER_HMAC_B64:publisherSecret,
};
function snapshot() {
  const row = db.prepare('SELECT organization,instance,generation_floor,session_floor,membership_floor,sequence FROM witness_epochs').get();
  writeFileSync(root + '/state.json', JSON.stringify(row), {mode:0o600});
}
snapshot();

const server=https.createServer({cert:readFileSync(cert),key:readFileSync(key)},async(req,res)=>{
  try {
    if(req.url!== '/v1/portal/witness-advance' || req.method!=='POST') {
      res.writeHead(404,{'Cache-Control':'no-store'});res.end();return;
    }
    const scenario=readFileSync(root+'/scenario','utf8').trim();
    if(scenario==='unavailable'){
      res.writeHead(503,{'Cache-Control':'no-store'});res.end();return;
    }
    if(scenario==='redirect'){
      res.writeHead(302,{'Cache-Control':'no-store','Location':'https://different.invalid/'});res.end();return;
    }
    if(scenario==='timeout') {
      await new Promise(resolve=>setTimeout(resolve,2100));
      res.writeHead(503,{'Cache-Control':'no-store'});res.end();return;
    }
    if(scenario==='unexpected-body'){
      res.writeHead(200,{'Cache-Control':'no-store','Content-Type':'text/plain'});res.end('not empty');return;
    }
    if(scenario==='huge-headers'){
      res.writeHead(204,{'Cache-Control':'no-store','X-Huge-Header':'a'.repeat(12000)});res.end();return;
    }
    let size=0;const parts=[];
    for await(const chunk of req) {
      size+=chunk.length;
      if(size>2048){res.writeHead(413);res.end();return;}
      parts.push(chunk);
    }
    const raw=Buffer.concat(parts).toString('utf8');
    const headers=new Headers();
    for(const name of ['content-type','x-imagine-timestamp','x-imagine-signature']) {
      if(req.headers[name])headers.set(name,String(req.headers[name]));
    }
    const external=new Request('https://witness.f377.test.invalid'+req.url,{
      method:'POST',headers,body:raw,
    });
    const response=await worker.fetch(external,env);
    appendFileSync(root+'/requests.jsonl',JSON.stringify({
      path:req.url,
      method:req.method,
      timestamp:req.headers['x-imagine-timestamp']??null,
      signature:req.headers['x-imagine-signature']??null,
      body:raw,
      status:response.status,
    })+'\n',{mode:0o600});
    snapshot();
    res.writeHead(response.status,{
      'Cache-Control':'no-store',
      'X-Content-Type-Options':'nosniff',
    });
    res.end();
  }catch{
    res.writeHead(503,{'Cache-Control':'no-store'});
    res.end();
  }
});
server.listen(18444,'127.0.0.1',()=>console.log('F377 ephemeral localhost HTTPS fixture ready'));
