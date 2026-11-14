/**
 * F393: append-only ACK *claim* for already witnessed revocation intents.
 *
 * SECURITY: an authenticated publisher claim is NOT independent evidence
 * of PostgreSQL row state. Nothing here enables serving or asserts that
 * local revocation finished. No automatic endpoint deployment.
 */
const POST_PATH='/v1/portal/revocation-acks';
const GET_PREFIX=POST_PATH+'/';
const POST_DOMAIN='imagine.portal.f393.ack-claim-publish.v1';
const GET_DOMAIN='imagine.portal.f393.ack-claim-read.v1';
const SIGN_DOMAIN='imagine.portal.f393.ed25519-ack-domain.v1';
const SCHEMA='imagine.portal.f393.signed-ack-claim.v1';
const te=new TextEncoder();
const isScope=v=>typeof v==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(v);
const isHex=(v,n)=>typeof v==='string'&&v.length===n&&/^[a-f0-9]+$/.test(v);
const positive=v=>Number.isSafeInteger(v)&&v>=1&&v<=1000000000;
const refuse=status=>new Response(null,{status,headers:{
  'Cache-Control':'no-store, max-age=0','X-Content-Type-Options':'nosniff',
}});
function rawKey(value){
  if(typeof value!=='string'||!/^[A-Za-z0-9+/]{43}=$/.test(value))throw Error('no key');
  const raw=Uint8Array.from(atob(value),x=>x.charCodeAt(0));
  if(raw.length!==32||btoa(String.fromCharCode(...raw))!==value)throw Error('bad key');
  return raw;
}
async function hmacOk(request,secret,domain,method,path,nonce,payload,now){
  const ts=request.headers.get('x-imagine-timestamp')??'';
  const mac=request.headers.get('x-imagine-signature')??'';
  if(!/^\d{10}$/.test(ts)||!isHex(mac,64)
    ||Math.abs(now-Number(ts))>30)return false;
  const key=await crypto.subtle.importKey('raw',rawKey(secret),
    {name:'HMAC',hash:'SHA-256'},false,['verify']);
  const message=[domain,method,path,ts,nonce,payload].join('\n');
  return crypto.subtle.verify('HMAC',key,
    Uint8Array.from(mac.match(/../g),x=>parseInt(x,16)),
    te.encode(message));
}
async function bodyBounded(request){
  if(!request.body)throw Error('body');
  const len=request.headers.get('content-length');
  if(len!==null&&(!/^\d{1,4}$/.test(len)||Number(len)>1024))throw Error('body');
  const r=request.body.getReader();let size=0;const arr=[];
  while(true){
    const {value,done}=await r.read();
    if(done)break;
    size+=value.byteLength;
    if(size>1024){await r.cancel();throw Error('body');}
    arr.push(value);
  }
  const out=new Uint8Array(size);let at=0;
  for(const piece of arr){out.set(piece,at);at+=piece.byteLength;}
  return new TextDecoder('utf-8',{fatal:true}).decode(out);
}
function canonicalReceipt(r){
  return [
    SIGN_DOMAIN,SCHEMA,r.organization,r.instance,r.intent_id,
    r.session_sha256,String(r.intent_sequence),
    String(r.observed_sequence),String(r.observed_session_floor),
    String(r.claimed_at),String(r.issued_at),String(r.expires_at),
    r.nonce,r.status,
  ].join('\n');
}
async function sign(env,receipt){
  const encoded=env.WITNESS_SIGNING_PKCS8_B64;
  if(typeof encoded!=='string'||encoded.length>400
    ||!/^[A-Za-z0-9+/]+={0,2}$/.test(encoded))throw Error('key');
  const bytes=Uint8Array.from(atob(encoded),x=>x.charCodeAt(0));
  if(bytes.length<48||bytes.length>256)throw Error('key');
  const key=await crypto.subtle.importKey('pkcs8',bytes,
    {name:'Ed25519'},false,['sign']);
  const sig=await crypto.subtle.sign('Ed25519',key,te.encode(canonicalReceipt(receipt)));
  return [...new Uint8Array(sig)].map(x=>x.toString(16).padStart(2,'0')).join('');
}
export async function publishAckClaim(request,env,organization,instance,now){
  if(request.method!=='POST')return refuse(404);
  if(!isScope(organization)||!isScope(instance)
    ||!env?.DB||typeof env.ACK_CLAIM_HMAC_B64!=='string')return refuse(503);
  if(!/^application\/json(?:;\s*charset=utf-8)?$/i.test(
    request.headers.get('content-type')??''))return refuse(415);
  try{
    const raw=await bodyBounded(request);
    if(!await hmacOk(request,env.ACK_CLAIM_HMAC_B64,POST_DOMAIN,
      'POST',POST_PATH,'',raw,now))return refuse(403);
    const b=JSON.parse(raw);
    const keys=['organization','instance','intent_id',
      'session_sha256','intent_sequence','local_status'];
    if(!b||typeof b!=='object'||Array.isArray(b)
      ||JSON.stringify(Object.keys(b))!==JSON.stringify(keys)
      ||b.organization!==organization||b.instance!==instance
      ||!isHex(b.intent_id,32)||!isHex(b.session_sha256,64)
      ||!positive(b.intent_sequence)
      ||b.local_status!=='revoked_claimed')return refuse(422);

    // A single statement is atomic. The SQLite trigger checks the referenced
    // independent intent and its current witness floors/sequence. The ACK
    // itself is immutable and keyed by the exact intent ID.
    const result=await env.DB.prepare(
      'INSERT INTO revocation_ack_claims '
      +'(organization,instance,intent_id,session_sha256,'
      +'intent_sequence,local_status,claimed_at) VALUES(?,?,?,?,?,?,?)',
    ).bind(organization,instance,b.intent_id,b.session_sha256,
      b.intent_sequence,b.local_status,now).run();
    return result?.success===true&&result?.meta?.changes===1
      ?refuse(204):refuse(503);
  }catch{
    return refuse(409);
  }
}
export async function readAckClaim(request,env,org,instance,now){
  if(request.method!=='GET')return refuse(404);
  if(!env?.DB||!isScope(org)||!isScope(instance)
    ||typeof env.ACK_READ_HMAC_B64!=='string'
    ||typeof env.WITNESS_SIGNING_PKCS8_B64!=='string')return refuse(503);
  const pathname=new URL(request.url).pathname;
  if(!pathname.startsWith(GET_PREFIX))return refuse(404);
  const id=pathname.slice(GET_PREFIX.length);
  if(!isHex(id,32))return refuse(404);
  const nonce=request.headers.get('x-imagine-witness-nonce')??'';
  if(!isHex(nonce,64))return refuse(400);
  try{
    if(!await hmacOk(request,env.ACK_READ_HMAC_B64,GET_DOMAIN,
      'GET',pathname,nonce,'',now))return refuse(403);
    const claim=await env.DB.prepare(
      'SELECT organization,instance,intent_id,session_sha256,'
      +'intent_sequence,local_status,claimed_at FROM revocation_ack_claims '
      +'WHERE organization=? AND instance=? AND intent_id=?',
    ).bind(org,instance,id).first();
    if(!claim)return refuse(404);
    const epoch=await env.DB.prepare(
      'SELECT sequence,session_floor FROM witness_epochs '
      +'WHERE organization=? AND instance=?',
    ).bind(org,instance).first();
    const original=await env.DB.prepare(
      'SELECT to_sequence,session_floor,session_sha256 FROM revocation_intents '
      +'WHERE organization=? AND instance=? AND intent_id=?',
    ).bind(org,instance,id).first();
    if(claim.organization!==org||claim.instance!==instance||claim.intent_id!==id
      ||!isHex(claim.session_sha256,64)||!positive(claim.intent_sequence)
      ||!Number.isSafeInteger(claim.claimed_at)
      ||claim.claimed_at<1||claim.claimed_at>9999999999
      ||claim.local_status!=='revoked_claimed'
      ||!original||!positive(original.to_sequence)
      ||!positive(original.session_floor)
      ||original.to_sequence!==claim.intent_sequence
      ||original.session_sha256!==claim.session_sha256
      ||!epoch||!positive(epoch.sequence)||!positive(epoch.session_floor)
      ||epoch.sequence<claim.intent_sequence
      ||epoch.session_floor<original.session_floor)return refuse(503);
    const receipt={
      schema:SCHEMA,organization:org,instance,intent_id:id,
      session_sha256:claim.session_sha256,
      intent_sequence:claim.intent_sequence,
      observed_sequence:epoch.sequence,
      observed_session_floor:epoch.session_floor,
      claimed_at:claim.claimed_at,issued_at:now,expires_at:now+30,
      nonce,status:'ack_recorded_local_claim_unverified',signature:'',
    };
    receipt.signature=await sign(env,receipt);
    return new Response(JSON.stringify(receipt),{status:200,headers:{
      'Content-Type':'application/json; charset=utf-8',
      'Cache-Control':'no-store, max-age=0','Pragma':'no-cache',
      'X-Content-Type-Options':'nosniff',
    }});
  }catch{
    return refuse(503);
  }
}
