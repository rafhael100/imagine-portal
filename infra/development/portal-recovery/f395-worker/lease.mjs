/**
 * F395 non-serving lease CAS for worker retries of independently witnessed
 * intents. No acknowledgement, delete, "done" state or serving right.
 * Worker and reader use separate 256-bit HMAC keys. The lease identifier
 * is only a SHA256 of a transient random value, never a bearer token.
 */
const POST='/v1/portal/revocation-retry-leases';
const PREFIX=POST+'/';
const PDOMAIN='imagine.portal.f395.retry-lease-claim.v1';
const RDOMAIN='imagine.portal.f395.retry-lease-read.v1';
const SDOMAIN='imagine.portal.f395.signed-retry-lease-domain.v1';
const SCHEMA='imagine.portal.f395.signed-retry-lease.v1';
const te=new TextEncoder();
const scope=x=>typeof x==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(x);
const hex=(x,n)=>typeof x==='string'&&x.length===n&&/^[a-f0-9]+$/.test(x);
const positive=x=>Number.isSafeInteger(x)&&x>=1&&x<=1000000000;
const refusal=status=>new Response(null,{status,headers:{
 'Cache-Control':'no-store, max-age=0','X-Content-Type-Options':'nosniff',
}});
function rawKey(v){
 if(typeof v!=='string'||!/^[A-Za-z0-9+/]{43}=$/.test(v))throw Error('key');
 const a=Uint8Array.from(atob(v),x=>x.charCodeAt(0));
 if(a.length!==32||btoa(String.fromCharCode(...a))!==v)throw Error('key');
 return a;
}
async function signedRequest(req,secret,domain,method,path,nonce,payload,now){
 const ts=req.headers.get('x-imagine-timestamp')??'';
 const sig=req.headers.get('x-imagine-signature')??'';
 if(!/^\d{10}$/.test(ts)||!hex(sig,64)||Math.abs(now-Number(ts))>30)return false;
 const key=await crypto.subtle.importKey('raw',rawKey(secret),
  {name:'HMAC',hash:'SHA-256'},false,['verify']);
 const input=te.encode([domain,method,path,ts,nonce,payload].join('\n'));
 return crypto.subtle.verify('HMAC',key,
  Uint8Array.from(sig.match(/../g),s=>parseInt(s,16)),input);
}
async function boundedBody(req){
 if(!req.body)throw Error('body');
 const length=req.headers.get('content-length');
 if(length!==null&&(!/^\d{1,4}$/.test(length)||Number(length)>900))throw Error('size');
 const reader=req.body.getReader();
 let size=0;const chunks=[];
 while(true){
  const {value,done}=await reader.read();
  if(done)break;
  size+=value.byteLength;
  if(size>900){await reader.cancel();throw Error('size');}
  chunks.push(value);
 }
 const bytes=new Uint8Array(size);let at=0;
 for(const c of chunks){bytes.set(c,at);at+=c.byteLength;}
 return new TextDecoder('utf-8',{fatal:true}).decode(bytes);
}
function canonical(r){
 return [
   SDOMAIN,SCHEMA,r.organization,r.instance,r.intent_id,
   r.session_sha256,r.lease_sha256,String(r.intent_sequence),
   String(r.attempt_count),String(r.acquired_at),String(r.lease_until),
   String(r.observed_sequence),String(r.issued_at),
   String(r.expires_at),r.nonce,r.status,
 ].join('\n');
}
async function sign(env,r){
 const encoded=env.WITNESS_SIGNING_PKCS8_B64;
 if(typeof encoded!=='string'||encoded.length>400
   ||!/^[A-Za-z0-9+/]+={0,2}$/.test(encoded))throw Error('key');
 const b=Uint8Array.from(atob(encoded),x=>x.charCodeAt(0));
 if(b.length<48||b.length>256)throw Error('key');
 const k=await crypto.subtle.importKey('pkcs8',b,{name:'Ed25519'},false,['sign']);
 const sig=await crypto.subtle.sign('Ed25519',k,te.encode(canonical(r)));
 return [...new Uint8Array(sig)].map(x=>x.toString(16).padStart(2,'0')).join('');
}
export async function acquireRetryLease(req,env,org,inst,now){
 if(req.method!=='POST')return refusal(404);
 if(!env?.DB||!scope(org)||!scope(inst)
   ||typeof env.LEASE_WORKER_HMAC_B64!=='string')return refusal(503);
 if(!/^application\/json(?:;\s*charset=utf-8)?$/i.test(
   req.headers.get('content-type')??''))return refusal(415);
 if(!Number.isSafeInteger(now)||now<=0||now>9999999900)return refusal(503);
 try{
  const raw=await boundedBody(req);
  if(!await signedRequest(req,env.LEASE_WORKER_HMAC_B64,
    PDOMAIN,'POST',POST,'',raw,now))return refusal(403);
  const body=JSON.parse(raw);
  const fields=['organization','instance','intent_id','session_sha256',
    'intent_sequence','lease_sha256'];
  if(!body||typeof body!=='object'||Array.isArray(body)
    ||JSON.stringify(Object.keys(body))!==JSON.stringify(fields)
    ||body.organization!==org||body.instance!==inst
    ||!hex(body.intent_id,32)||!hex(body.session_sha256,64)
    ||!hex(body.lease_sha256,64)
    ||!positive(body.intent_sequence)
    ||body.lease_sha256===body.session_sha256)return refusal(422);
  const stmt=env.DB.prepare(
   'INSERT INTO revocation_retry_leases '
   +'(organization,instance,intent_id,session_sha256,intent_sequence,'
   +'lease_sha256,acquired_at,lease_until,attempt_count) '
   +'VALUES(?,?,?,?,?,?,?,?,1) '
   +'ON CONFLICT(organization,instance,intent_id) DO UPDATE SET '
   +'lease_sha256=excluded.lease_sha256,'
   +'acquired_at=excluded.acquired_at,'
   +'lease_until=excluded.lease_until,'
   +'attempt_count=revocation_retry_leases.attempt_count+1 '
   +'WHERE revocation_retry_leases.session_sha256=excluded.session_sha256 '
   +'AND revocation_retry_leases.intent_sequence=excluded.intent_sequence '
   +'AND revocation_retry_leases.lease_until<=excluded.acquired_at '
   +'AND revocation_retry_leases.attempt_count<5 '
   +'AND revocation_retry_leases.lease_sha256<>excluded.lease_sha256',
  ).bind(org,inst,body.intent_id,body.session_sha256,
    body.intent_sequence,body.lease_sha256,now,now+30);
  const result=await stmt.run();
  return result?.success===true&&result?.meta?.changes===1
   ?refusal(204):refusal(409);
 }catch{return refusal(409);}
}
export async function readRetryLease(req,env,org,inst,now){
 if(req.method!=='GET')return refusal(404);
 if(!env?.DB||!scope(org)||!scope(inst)
   ||typeof env.LEASE_READ_HMAC_B64!=='string'
   ||typeof env.WITNESS_SIGNING_PKCS8_B64!=='string')return refusal(503);
 const path=new URL(req.url).pathname;
 if(!path.startsWith(PREFIX))return refusal(404);
 const id=path.slice(PREFIX.length);
 if(!hex(id,32))return refusal(404);
 const nonce=req.headers.get('x-imagine-witness-nonce')??'';
 if(!hex(nonce,64))return refusal(400);
 try{
  if(!await signedRequest(req,env.LEASE_READ_HMAC_B64,
    RDOMAIN,'GET',path,nonce,'',now))return refusal(403);
  const row=await env.DB.prepare(
    'SELECT organization,instance,intent_id,session_sha256,intent_sequence,'
    +'lease_sha256,attempt_count,acquired_at,lease_until '
    +'FROM revocation_retry_leases '
    +'WHERE organization=? AND instance=? AND intent_id=?',
  ).bind(org,inst,id).first();
  if(!row)return refusal(404);
  const intent=await env.DB.prepare(
    'SELECT session_sha256,to_sequence,session_floor '
    +'FROM revocation_intents '
    +'WHERE organization=? AND instance=? AND intent_id=?',
  ).bind(org,inst,id).first();
  const witness=await env.DB.prepare(
    'SELECT sequence,session_floor FROM witness_epochs '
    +'WHERE organization=? AND instance=?',
  ).bind(org,inst).first();
  if(row.organization!==org||row.instance!==inst||row.intent_id!==id
    ||!hex(row.session_sha256,64)||!hex(row.lease_sha256,64)
    ||!positive(row.intent_sequence)
    ||!Number.isSafeInteger(row.attempt_count)
    ||row.attempt_count<1||row.attempt_count>5
    ||!Number.isSafeInteger(row.acquired_at)||row.acquired_at<1
    ||!Number.isSafeInteger(row.lease_until)
    ||row.lease_until!==row.acquired_at+30
    ||!intent||intent.session_sha256!==row.session_sha256
    ||intent.to_sequence!==row.intent_sequence
    ||!positive(intent.session_floor)
    ||!witness||!positive(witness.sequence)
    ||!positive(witness.session_floor)
    ||witness.sequence<intent.to_sequence
    ||witness.session_floor<intent.session_floor)return refusal(503);
  const receipt={
    schema:SCHEMA,organization:org,instance:inst,
    intent_id:id,session_sha256:row.session_sha256,
    lease_sha256:row.lease_sha256,
    intent_sequence:row.intent_sequence,attempt_count:row.attempt_count,
    acquired_at:row.acquired_at,lease_until:row.lease_until,
    observed_sequence:witness.sequence,issued_at:now,expires_at:now+30,
    nonce,status:now<row.lease_until
      ?'retry_lease_observed_not_completion'
      :'retry_lease_expired_not_completion',
    signature:'',
  };
  receipt.signature=await sign(env,receipt);
  return new Response(JSON.stringify(receipt),{status:200,headers:{
    'Content-Type':'application/json; charset=utf-8',
    'Cache-Control':'no-store, max-age=0','Pragma':'no-cache',
    'X-Content-Type-Options':'nosniff',
  }});
 }catch{return refusal(503);}
}
