#!/usr/bin/env node
// PORTAL-F73: offline static NO-GO check for the current Laravel skeleton.
// Not a substitute for runtime authorization, container/image review or AppSec.
import {existsSync,lstatSync,readFileSync,readdirSync} from 'node:fs';
import {join,resolve} from 'node:path';
import {dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const fail=()=>{process.stderr.write('F73 denied Laravel runtime exposure of offline laboratories\n');process.exit(2);};
if(process.env.APP_ENV!=='testing' ||
  process.env.PORTAL_F73_LAB_RUNTIME_GUARD!=='1' ||
  process.argv.length>3)fail();

const portal=resolve(process.argv[2] || join(dirname(fileURLToPath(import.meta.url)),'..'));
const blocked=/(?:PORTAL_F(?:64|65|66|69|71|72)_|CredentialBoundBudgetPreview|f(?:65|66|69|71|72)-(?:render|export|verify|create)|scenario-demo-)/i;
const executableExt=/\.(?:php|js|mjs|cjs|json|htaccess)$/i;
let checked=0;
function scan(root){
  if(!existsSync(root))return;
  const st=lstatSync(root);
  if(st.isSymbolicLink())throw Error('symbolic runtime path');
  if(st.isDirectory()){
    for(const item of readdirSync(root))scan(join(root,item));
    return;
  }
  if(!st.isFile())throw Error('unrecognized runtime entry');
  const name=root.split(/[\\/]/).pop();
  if(!executableExt.test(name) && name!=='.htaccess')return;
  if(st.size>1024*1024)throw Error('unexpected large runtime file');
  const source=readFileSync(root,'utf8');
  checked++;
  if(blocked.test(source))throw Error('offline lab found in served runtime');
  if(root.split(/[\\\\/]/).includes('routes') && /\\bRoute\\s*::/i.test(source))
    throw Error('HTTP route found before homologation');
}
try{
  const rootStat=lstatSync(portal);
  if(!rootStat.isDirectory() || rootStat.isSymbolicLink())fail();
  const routeFile=join(portal,'routes','web.php');
  const bootstrapFile=join(portal,'bootstrap','app.php');
  if(!existsSync(routeFile) || !existsSync(bootstrapFile))fail();
  const routes=readFileSync(routeFile,'utf8');
  const bootstrap=readFileSync(bootstrapFile,'utf8');
  // For this pre-homologation branch, no HTTP business routes are approved.
  if(/\bRoute\s*::/i.test(routes) ||
    /\bRoute\s*::/i.test(readFileSync(join(portal,'routes','console.php'),'utf8')) ||
    !/health\s*:\s*['"]\/up['"]/.test(bootstrap) ||
    /\bapi\s*:\s*[^,\s]+/i.test(bootstrap))fail();
  for(const rel of ['routes','bootstrap','public','config','app/Http']){
    scan(join(portal,rel));
  }
  process.stdout.write(JSON.stringify({
    schema_version:'portal.f73.no-runtime-labs.audit.v1',
    pre_homologation_only:true,production_approved:false,
    executed_runtime_scan:false,reviewed_source_files:checked
  })+'\n');
}catch{fail();}
