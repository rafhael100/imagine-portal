/**
 * F386 - independently recorded, append-only revocation intent candidate.
 * NO RUNTIME DEPLOY. Only server-to-server HMAC publisher may submit.
 * Stores opaque 16-byte intent ID + SHA256 of a 256-bit random session ref,
 * never raw session refs, user email, credentials or HTTP cookies.
 *
 * Two D1 statements execute as one transaction via env.DB.batch().
 * A successful 204 means the WITNESS advanced and an intent exists; it
 * NEVER means the Portal PostgreSQL session was revoked, or can serve.
 */
const PATH='/v1/portal/revocation-intents';
const DOMAIN='imagine.portal.witness.revocation-intent.v1';
const MAX_BYTES=1024;
const validScope=v=>typeof v==='string'&&/^[A-Za-z0-9_-]{1,64}$/.test(v);
const positive=v=>Number.isSafeInteger(v)&&v>=1&&v<=1000000000;
const hex=(v,length)=>typeof v==='string'&&v.length===length&&/^[a-f0-9]+$/.test(v);
const response=status=>new Response(null,{status,headers:{
  'Cache-Control':'no-store, max-age=0','X-Content-Type-Options':'nosniff',
}});
function decodeSecret(value){
  if(typeof value!=='string'||!/^[A-Za-z0-9+/]{43}=$/.test(value))throw Error('missing');
  const raw=Uint8Array.from(atob(value),c=>c.charCodeAt(0));
  if(raw.length!==32||btoa(String.fromCharCode(...raw))!==value)throw Error('bad');
  return raw;
}
async function readBody(request){
  const length=request.headers.get('content-length');
  if(length!==null&&(!/^\d{1,4}$/.test(length)||Number(length)>MAX_BYTES))throw Error('size');
  if(!request.body)throw Error('body');
  const reader=request.body.getReader();let n=0;const parts=[];
  while(true){
    const {value,done}=await reader.read();
    if(done)break;
    n+=value.byteLength;
    if(n>MAX_BYTES){await reader.cancel();throw Error('size');}
    parts.push(value);
  }
  const bytes=new Uint8Array(n);let offset=0;
  for(const p of parts){bytes.set(p,offset);offset+=p.byteLength;}
  return new TextDecoder('utf-8',{fatal:true}).decode(bytes);
}
async function authenticated(request,env,raw,now){
  const ts=request.headers.get('x-imagine-timestamp')??'';
  const mac=request.headers.get('x-imagine-signature')??'';
  if(!/^\d{10}$/.test(ts)||!hex(mac,64)||Math.abs(now-Number(ts))>30)return false;
  const secret=decodeSecret(env.INTENT_PUBLISHER_HMAC_B64);
  const key=await crypto.subtle.importKey('raw',secret,
    {name:'HMAC',hash:'SHA-256'},false,['verify']);
  const signed=[DOMAIN,'POST',PATH,ts,raw].join('\n');
  return crypto.subtle.verify('HMAC',key,
    Uint8Array.from(mac.match(/../g),b=>parseInt(b,16)),
    new TextEncoder().encode(signed));
}
export async function recordRevocationIntent(request,env,org,instance,now){
  if(request.method!=='POST')return response(404);
  if(!env?.DB||typeof env.DB.batch!=='function'
    ||!validScope(org)||!validScope(instance)
    ||typeof env.INTENT_PUBLISHER_HMAC_B64!=='string')return response(503);
  if(!/^application\/json(?:;\s*charset=utf-8)?$/i.test(
    request.headers.get('content-type')??''))return response(415);
  try {
    const raw=await readBody(request);
    if(!await authenticated(request,env,raw,now))return response(403);
    const body=JSON.parse(raw);
    const keys=[
      'organization','instance','intent_id','session_sha256',
      'from_sequence','generation_floor','session_floor','membership_floor',
    ];
    if(!body||typeof body!=='object'||Array.isArray(body)
      ||JSON.stringify(Object.keys(body))!==JSON.stringify(keys)
      ||body.organization!==org||body.instance!==instance
      ||!hex(body.intent_id,32)||!hex(body.session_sha256,64)
      ||!positive(body.from_sequence)||body.from_sequence>=1000000000
      ||!positive(body.generation_floor)||!positive(body.session_floor)
      ||!positive(body.membership_floor))return response(422);

    // D1 batch is atomic. The guarded INSERT refuses a stale sequence,
    // shrinking floor or duplicate intent. The UPDATE requires that this
    // exact intent was inserted in the SAME transaction.
    const insert=env.DB.prepare(
      'INSERT INTO revocation_intents (organization,instance,intent_id,'
      +'session_sha256,from_sequence,to_sequence,generation_floor,'
      +'session_floor,membership_floor,created_at) '
      +'VALUES(?,?,?,?,?,?,?,?,?,?)',
    ).bind(org,instance,body.intent_id,body.session_sha256,
      body.from_sequence,body.from_sequence+1,body.generation_floor,
      body.session_floor,body.membership_floor,now);
    const advance=env.DB.prepare(
      'UPDATE witness_epochs '
      +'SET generation_floor=?,session_floor=?,membership_floor=?,'
      +'sequence=sequence+1,updated_at=? '
      +'WHERE organization=? AND instance=? AND sequence=? '
      +'AND generation_floor<=? AND session_floor<? AND membership_floor<=? '
      +'AND sequence<1000000000 '
      +'AND EXISTS (SELECT 1 FROM revocation_intents i '
      +'WHERE i.organization=? AND i.instance=? AND i.intent_id=? '
      +'AND i.to_sequence=witness_epochs.sequence+1)',
    ).bind(
      body.generation_floor,body.session_floor,body.membership_floor,now,
      org,instance,body.from_sequence,
      body.generation_floor,body.session_floor,body.membership_floor,
      org,instance,body.intent_id,
    );
    const results=await env.DB.batch([insert,advance]);
    if(Array.isArray(results)&&results.length===2
      &&results.every(x=>x?.success===true&&x?.meta?.changes===1)){
      return response(204);
    }
    // Never interpret an ambiguous partial result as a successful
    // revocation. Production requires a separate authenticated reconciliation.
    return response(503);
  }catch{
    // Duplicate, stale and fault-injected transactions refuse serving.
    return response(409);
  }
}
