/**
 * F392: READ-ONLY, authenticated recovery cursor candidate for independent
 * append-only revocation intents. It never authorizes serving or claims that
 * Portal PostgreSQL applied a revocation.
 *
 * A crash-safe worker can restart from sequence 0 and re-read all intents.
 * No durable processing checkpoint, ack, lease, or production service exists.
 */
const PATH='/v1/portal/revocation-intents/next';
const HMAC_DOMAIN='imagine.portal.witness.revocation-cursor.v1';
const SIGN_DOMAIN='imagine.portal.f392.ed25519-cursor-domain.v1';
const SCHEMA='imagine.portal.f392.signed-intent-cursor.v1';
const bytes=new TextEncoder();
const refuse=status=>new Response(null,{status,headers:{
  'Cache-Control':'no-store, max-age=0','X-Content-Type-Options':'nosniff',
}});
const scope=v=>typeof v==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(v);
const hex=(v,n)=>typeof v==='string'&&v.length===n&&/^[a-f0-9]+$/.test(v);
const count=v=>Number.isSafeInteger(v)&&v>=1&&v<=1000000000;

function readKey(value,length){
  if(typeof value!=='string'||!(/^[A-Za-z0-9+/]+={0,2}$/).test(value)) {
    throw new Error('unavailable');
  }
  const raw=Uint8Array.from(atob(value),c=>c.charCodeAt(0));
  if(raw.length!==length || btoa(String.fromCharCode(...raw))!==value){
    throw new Error('unavailable');
  }
  return raw;
}
async function verifyRequest(request,env,now,after,nonce){
  const timestamp=request.headers.get('x-imagine-timestamp')??'';
  const mac=request.headers.get('x-imagine-signature')??'';
  if(!/^\d{10}$/.test(timestamp)||!hex(mac,64)
    ||Math.abs(now-Number(timestamp))>30)return false;
  const keyBytes=readKey(env.INTENT_CURSOR_HMAC_B64,32);
  const key=await crypto.subtle.importKey('raw',keyBytes,
    {name:'HMAC',hash:'SHA-256'},false,['verify']);
  const signed=[HMAC_DOMAIN,'GET',PATH,timestamp,nonce,String(after)].join('\n');
  const received=Uint8Array.from(mac.match(/../g),x=>parseInt(x,16));
  return crypto.subtle.verify('HMAC',key,received,bytes.encode(signed));
}
function canonical(r){
  return [
    SIGN_DOMAIN,SCHEMA,r.organization,r.instance,
    String(r.after_sequence),String(r.observed_sequence),
    String(r.observed_session_floor),
    r.intent_id,r.session_sha256,
    String(r.intent_sequence),String(r.intent_session_floor),
    String(r.issued_at),String(r.expires_at),r.nonce,r.status,
  ].join('\n');
}
async function edSign(env,receipt){
  const encoded=env.WITNESS_SIGNING_PKCS8_B64;
  if(typeof encoded!=='string'||encoded.length>400
    ||!(/^[A-Za-z0-9+/]+={0,2}$/).test(encoded))throw new Error('signer');
  const raw=Uint8Array.from(atob(encoded),x=>x.charCodeAt(0));
  if(raw.length<48||raw.length>256)throw new Error('signer');
  const signer=await crypto.subtle.importKey('pkcs8',raw,
    {name:'Ed25519'},false,['sign']);
  const signed=await crypto.subtle.sign('Ed25519',signer,
    bytes.encode(canonical(receipt)));
  return [...new Uint8Array(signed)].map(x=>x.toString(16).padStart(2,'0')).join('');
}
export async function nextRevocationIntent(request,env,org,instance,now){
  if(request.method!=='GET')return refuse(404);
  if(!env?.DB||!scope(org)||!scope(instance)
    ||typeof env.INTENT_CURSOR_HMAC_B64!=='string'
    ||typeof env.WITNESS_SIGNING_PKCS8_B64!=='string')return refuse(503);
  const rawAfter=request.headers.get('x-imagine-after-sequence')??'';
  const nonce=request.headers.get('x-imagine-witness-nonce')??'';
  if(!(/^(0|[1-9]\d{0,9})$/).test(rawAfter)
    ||Number(rawAfter)>1000000000||!hex(nonce,64))return refuse(400);
  const after=Number(rawAfter);
  try{
    if(!await verifyRequest(request,env,now,after,nonce))return refuse(403);
    const witness=await env.DB.prepare(
      'SELECT sequence,session_floor FROM witness_epochs '
      +'WHERE organization=? AND instance=?',
    ).bind(org,instance).first();
    if(!witness||!count(witness.sequence)||!count(witness.session_floor))return refuse(503);
    if(after>witness.sequence)return refuse(409);
    const row=await env.DB.prepare(
      'SELECT intent_id,session_sha256,to_sequence,session_floor '
      +'FROM revocation_intents WHERE organization=? AND instance=? '
      +'AND to_sequence>? ORDER BY to_sequence ASC LIMIT 1',
    ).bind(org,instance,after).first();
    if(row&&(!hex(row.intent_id,32)||!hex(row.session_sha256,64)
      ||!count(row.to_sequence)||row.to_sequence<=after
      ||row.to_sequence>witness.sequence
      ||!count(row.session_floor)||row.session_floor>witness.session_floor)){
      return refuse(503);
    }
    const receipt={
      schema:SCHEMA,
      organization:org,instance,
      after_sequence:after,
      observed_sequence:witness.sequence,
      observed_session_floor:witness.session_floor,
      intent_id:row?row.intent_id:'',
      session_sha256:row?row.session_sha256:'',
      intent_sequence:row?row.to_sequence:0,
      intent_session_floor:row?row.session_floor:0,
      issued_at:now,
      expires_at:now+30,
      nonce,
      status:row?'pending_local_reconciliation':'no_intent_after_cursor',
      signature:'',
    };
    receipt.signature=await edSign(env,receipt);
    return new Response(JSON.stringify(receipt),{status:200,headers:{
      'Content-Type':'application/json; charset=utf-8',
      'Cache-Control':'no-store, max-age=0',
      'Pragma':'no-cache',
      'X-Content-Type-Options':'nosniff',
    }});
  }catch{
    return refuse(503);
  }
}
