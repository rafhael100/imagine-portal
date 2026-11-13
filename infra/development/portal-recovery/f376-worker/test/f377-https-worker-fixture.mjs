/**
 * F377 disposable Node TLS endpoint delegating to exact F376 Worker source.
 * This process is test-only: private signing material never persisted in Git.
 */
import https from 'node:https';
import { DatabaseSync } from 'node:sqlite';
import { createHmac, generateKeyPairSync, randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import worker from '../src/witness.mjs';

const [folder, certificate, keyPath] = process.argv.slice(2);
if(!folder || !certificate || !keyPath
  || process.env.GITHUB_ACTIONS!=='true'
  || process.env.RUNNER_ENVIRONMENT!=='github-hosted'
  || process.env.PORTAL_F377_CI!=='DISPOSABLE_ONLY'){
  process.exit(64);
}
const base = new URL('https://witness.f377.test.invalid:18444');
const db=new DatabaseSync(':memory:');
db.exec(readFileSync(new URL('../schema.sql',import.meta.url),'utf8'));
db.prepare('INSERT INTO witness_epochs(organization,instance,generation_floor,session_floor,membership_floor,sequence,updated_at) VALUES(?,?,?,?,?,?,?)')
  .run('org_f377','instance_f377',5,3,7,12,Math.floor(Date.now()/1000));
const {privateKey,publicKey}=generateKeyPairSync('ed25519');
const publisher=randomBytes(32);
const environment={
  DB:{
    prepare(sql){
      const statement=db.prepare(sql);
      return {
        bind(...args){
          return {
            async first(){return statement.get(...args)??null;},
            async run(){
              const result=statement.run(...args);
              return {success:true,meta:{changes:Number(result.changes)}};
            },
          };
        },
      };
    },
  },
  WITNESS_ORGANIZATION:'org_f377',
  WITNESS_INSTANCE:'instance_f377',
  WITNESS_SIGNING_PKCS8_B64:privateKey.export({format:'der',type:'pkcs8'}).toString('base64'),
  PUBLISHER_HMAC_B64:publisher.toString('base64'),
};
const rawPublic=publicKey.export({format:'der',type:'spki'}).subarray(-32).toString('hex');
writeFileSync(folder+'/publisher-hmac.b64',publisher.toString('base64'),{mode:0o600});
writeFileSync(folder+'/signing-public.hex',rawPublic,{mode:0o600});

const server=https.createServer({
  key:readFileSync(keyPath),
  cert:readFileSync(certificate),
},async(req,res)=>{
  try{
    const pieces=[];
    let size=0;
    for await (const chunk of req) {
      size+=chunk.length;
      if(size>2048){res.writeHead(413);res.end();return;}
      pieces.push(chunk);
    }
    const input=new Request(new URL(req.url,base),{
      method:req.method,
      headers:req.headers,
      body:pieces.length>0?Buffer.concat(pieces):undefined,
    });
    const result=await worker.fetch(input,environment);
    const headers={};
    result.headers.forEach((value,name)=>{headers[name]=value;});
    res.writeHead(result.status,headers);
    res.end(Buffer.from(await result.arrayBuffer()));
  }catch{
    res.writeHead(503,{'Cache-Control':'no-store'});
    res.end();
  }
});
server.listen(18444,'127.0.0.1',()=>{
  // Signal readiness via localhost TLS handshake, never print secrets.
  console.log('F377 synthetic worker HTTPS fixture ready');
});
