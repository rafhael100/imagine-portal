#!/usr/bin/env node
// PORTAL-F99: text-level audit of the fixed, host-only cookie configuration.
// A static gate, not proof of browser-side HTTPS, real authentication or SSO.
import {lstatSync,readFileSync} from 'node:fs';
import {dirname,join,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';

const fail=()=>{process.stderr.write('PORTAL-F99 denied unsafe shared session-cookie configuration\n');process.exit(2);};
if(process.env.APP_ENV!=='testing' ||
  process.env.PORTAL_F99_COOKIE_POLICY_LAB!=='1' ||
  process.argv.length>3)fail();
const source=resolve(process.argv[2] || join(dirname(fileURLToPath(import.meta.url)),'../config/session.php'));
try{
  const stat=lstatSync(source);
  if(!stat.isFile() || stat.isSymbolicLink() || stat.size<1000 ||
    stat.size>32000)fail();
  const text=readFileSync(source,'utf8');
  const safe={
    cookie:"'__Host-imagine-portal-session'",
    path:"'/'",
    domain:'null',
    secure:'true',
    http_only:'true',
    same_site:"'lax'",
    partitioned:'false',
  };
  for(const [key,value] of Object.entries(safe)){
    const pattern=new RegExp("^\\s*'"+key+"'\\s*=>\\s*([^,\\r\\n]+),\\s*$",'gm');
    const found=[...text.matchAll(pattern)];
    if(found.length!==1 || found[0][1].trim()!==value)fail();
  }
  if(/env\s*\(\s*['"]SESSION_(?:COOKIE|PATH|DOMAIN|SECURE_COOKIE|HTTP_ONLY|SAME_SITE|PARTITIONED_COOKIE)\b/.test(text))fail();
  if(!text.includes("return [") || !text.trimEnd().endsWith('];'))fail();
  process.stdout.write(JSON.stringify({
    schema_version:'portal.f99.host-only-cookie-policy.v1',
    policy_only:true,login_enabled:false,
    host_only:true,https_only:true,crm_session_shared:false
  })+'\n');
}catch{fail();}
