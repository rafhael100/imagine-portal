import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,readFileSync,writeFileSync,rmSync,symlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const tool='apps/portal/tools/f99-audit-session-cookie-policy.mjs';
const env={...process.env,APP_ENV:'testing',PORTAL_F99_COOKIE_POLICY_LAB:'1'};
const dir=mkdtempSync(join(tmpdir(),'portal-f99-cookie-'));
const fixture=join(dir,'session.php');
const baseline=readFileSync('apps/portal/config/session.php','utf8');
const run=(p=fixture,e=env)=>spawnSync('node',[tool,p],{env:e,encoding:'utf8'});
let assertions=0;
const check=(value,label)=>{assert.ok(value,label);assertions++;};
function deny(label,text){
  writeFileSync(fixture,text);
  const r=run();
  check(r.status!==0 && r.stdout==='',label+' fail closed');
}
try{
  writeFileSync(fixture,baseline);
  const actual=run('apps/portal/config/session.php');
  check(actual.status===0,'current Laravel config safe: '+actual.stderr);
  const report=JSON.parse(actual.stdout);
  check(report.host_only===true && report.https_only===true &&
    report.login_enabled===false && report.crm_session_shared===false,
    'audit cannot be mistaken for real login approval');
  const mutations=[
    ["'cookie' => '__Host-imagine-portal-session',","'cookie' => 'imagine_crm_session',",'CRM namespace collision'],
    ["'cookie' => '__Host-imagine-portal-session',","'cookie' => 'imagine_portal_session',",'host prefix removed'],
    ["'path' => '/',","'path' => '/portal',",'non-root cookie path'],
    ["'domain' => null,","'domain' => '.imagine.example',",'cross-subdomain scope'],
    ["'secure' => true,","'secure' => false,",'HTTPS requirement disabled'],
    ["'http_only' => true,","'http_only' => false,",'script-readable cookie'],
    ["'same_site' => 'lax',","'same_site' => 'none',",'cross-site cookie relaxation'],
    ["'partitioned' => false,","'partitioned' => true,",'unexpected cookie partitioning'],
    ["'cookie' => '__Host-imagine-portal-session',",
      "'cookie' => env('SESSION_COOKIE','__Host-imagine-portal-session'),",
      'shared environment can rename cookie'],
    ["'domain' => null,","'domain' => env('SESSION_DOMAIN'),",
      'shared environment can widen domain'],
  ];
  for(const [from,to,label] of mutations){
    check(baseline.includes(from),'baseline contains field '+label);
    deny(label,baseline.replace(from,to));
  }
  deny('duplicate secure option',baseline.replace(
    "'secure' => true,","'secure' => true,\n    'secure' => false,"));
  writeFileSync(fixture,baseline);
  const link=join(dir,'cookie-link.php');
  symlinkSync(fixture,link);
  const linked=run(link);
  check(linked.status!==0 && linked.stdout==='','symlink source blocked');
  check(run(fixture,{...env,APP_ENV:'production'}).status!==0,'production tool not enabled');
  check(run(fixture,{...env,PORTAL_F99_COOKIE_POLICY_LAB:'0'}).status!==0,
    'auditor lab flag required');
  check(run().status===0,'restored secure config passes');
}finally{rmSync(dir,{recursive:true,force:true});}
console.log('PASS PORTAL-F99 '+assertions+' host-only cookie and fail-closed tests');
