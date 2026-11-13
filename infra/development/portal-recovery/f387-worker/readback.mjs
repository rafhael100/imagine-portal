/**
 * F387: private, HMAC-authenticated readback for ambiguous F386 outcomes.
 * Returns an independently Ed25519-signed NON-AUTHORIZING receipt.
 * The confirmed intent is STILL PENDING LOCAL RECONCILIATION.
 * This candidate has no remote Cloudflare deployment or production bindings.
 */
const PREFIX='/v1/portal/revocation-intents/';
const HMAC_DOMAIN='imagine.portal.witness.revocation-intent-read.v1';
const SIGN_DOMAIN='imagine.portal.f387.ed25519-intent-ack-domain.v1';
const SCHEMA='imagine.portal.f387.revocation-intent-ack.v1';
const scope=v=>typeof v==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(v);
const lowerHex=(v,n)=>typeof v==='string'&&v.length===n&&/^[a-f0-9]+$/.test(v);
const positive=v=>Number.isSafeInteger(v)&&v>=1&&v<=1000000000;
const reply=status=>new Response(null,{status,headers:{
  'Cache-Control':'no-store, max-age=0',
  'X-Content-Type-Options':'nosniff',
}});
function strictDecode(encoded){
  if(typeof encoded!=='string'||!/^[A-Za-z0-9+/]{43}=$/.test(encoded)){
    throw Error('credentials');
  }
  const bytes=Uint8Array.from(atob(encoded),c=>c.charCodeAt(0));
  if(bytes.length!==32||btoa(String.fromCharCode(...bytes))!==encoded) {
    throw Error('credentials');
  }
  return bytes;
}
async function verifyReadbackRequest(request,env,path,now,nonce){
  const ts=request.headers.get('x-imagine-timestamp')??'';
  const signature=request.headers.get('x-imagine-signature')??'';
  if(!/^\d{10}$/.test(ts)||!lowerHex(signature,64)
    ||Math.abs(now-Number(ts))>30)return false;
  const hmacBytes=strictDecode(env.INTENT_PUBLISHER_HMAC_B64);
  const key=await crypto.subtle.importKey('raw',hmacBytes,
    {name:'HMAC',hash:'SHA-256'},false,['verify']);
  const canonical=[HMAC_DOMAIN,'GET',path,ts,nonce].join('\n');
  return crypto.subtle.verify('HMAC',key,
    Uint8Array.from(signature.match(/../g),v=>parseInt(v,16)),
    new TextEncoder().encode(canonical));
}
function canonicalReceipt(r){
  return [
    SIGN_DOMAIN,SCHEMA,r.organization,r.instance,r.intent_id,
    r.session_sha256,String(r.intent_sequence),String(r.observed_sequence),
    String(r.session_floor),String(r.issued_at),String(r.expires_at),
    r.nonce,r.status,
  ].join('\n');
}
async function signReceipt(env,receipt){
  const raw=env.WITNESS_SIGNING_PKCS8_B64;
  if(typeof raw!=='string'||!/^[A-Za-z0-9+/]+={0,2}$/.test(raw))throw Error('key');
  const bytes=Uint8Array.from(atob(raw),c=>c.charCodeAt(0));
  if(bytes.length<48||bytes.length>256)throw Error('key');
  const key=await crypto.subtle.importKey('pkcs8',bytes,
    {name:'Ed25519'},false,['sign']);
  const signature=await crypto.subtle.sign('Ed25519',key,
    new TextEncoder().encode(canonicalReceipt(receipt)));
  return [...new Uint8Array(signature)]
    .map(x=>x.toString(16).padStart(2,'0')).join('');
}
export async function readbackRevocationIntent(request,env,organization,instance,now){
  if(request.method!=='GET')return reply(404);
  if(!env?.DB||!scope(organization)||!scope(instance)
    ||typeof env.INTENT_PUBLISHER_HMAC_B64!=='string'
    ||typeof env.WITNESS_SIGNING_PKCS8_B64!=='string')return reply(503);
  const path=new URL(request.url).pathname;
  if(!path.startsWith(PREFIX))return reply(404);
  const id=path.slice(PREFIX.length);
  if(!lowerHex(id,32))return reply(404);
  const nonce=request.headers.get('x-imagine-witness-nonce')??'';
  if(!lowerHex(nonce,64))return reply(400);
  try{
    if(!await verifyReadbackRequest(request,env,path,now,nonce))return reply(403);
    const row=await env.DB.prepare(
      'SELECT organization,instance,intent_id,session_sha256,to_sequence,session_floor '
      +'FROM revocation_intents WHERE organization=? AND instance=? AND intent_id=?',
    ).bind(organization,instance,id).first();
    if(!row)return reply(404);
    const epoch=await env.DB.prepare(
      'SELECT sequence,session_floor FROM witness_epochs '
      +'WHERE organization=? AND instance=?',
    ).bind(organization,instance).first();
    if(!epoch||row.organization!==organization||row.instance!==instance
      ||row.intent_id!==id||!lowerHex(row.session_sha256,64)
      ||!positive(row.to_sequence)||!positive(row.session_floor)
      ||!positive(epoch.sequence)||!positive(epoch.session_floor)
      ||epoch.sequence<row.to_sequence
      ||epoch.session_floor<row.session_floor)return reply(503);
    const receipt={
      schema:SCHEMA,
      organization,instance,
      intent_id:id,
      session_sha256:row.session_sha256,
      intent_sequence:row.to_sequence,
      observed_sequence:epoch.sequence,
      session_floor:epoch.session_floor,
      issued_at:now,
      expires_at:now+30,
      nonce,
      status:'pending_local_reconciliation',
      signature:'',
    };
    receipt.signature=await signReceipt(env,receipt);
    return new Response(JSON.stringify(receipt),{
      status:200,
      headers:{
        'Content-Type':'application/json; charset=utf-8',
        'Cache-Control':'no-store, max-age=0',
        'Pragma':'no-cache',
        'X-Content-Type-Options':'nosniff',
      },
    });
  }catch{
    return reply(503);
  }
}
