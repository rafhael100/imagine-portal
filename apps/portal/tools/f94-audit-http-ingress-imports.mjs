#!/usr/bin/env node
// F94: static ingress-source exposure guard on the F92/F93 review lineage.
// This is only a pre-homologation restriction, not an AppSec/runtime attestation.
import {existsSync,lstatSync,readdirSync,readFileSync} from 'node:fs';
import {createHash} from 'node:crypto';
import {resolve,join,extname,basename,dirname} from 'node:path';
import {fileURLToPath} from 'node:url';

const deny=()=>{process.stderr.write('PORTAL-F94 blocked unsafe ingress source\n');process.exit(2);};
if(process.env.APP_ENV!=='testing' ||
  process.env.PORTAL_F94_INGRESS_GUARD_LAB!=='1' ||
  process.argv.length>3)deny();
const root=resolve(process.argv[2] || join(dirname(fileURLToPath(import.meta.url)),'../../..'));
const portal=join(root,'apps/portal');
const blocked=/(?:App\\Labs\\|\\Labs\\|CredentialBoundBudgetPreview|CredentialBoundPerformanceWorkbenchPreview|OfflinePerformanceWorkbenchPreview|OfflineSeoHandoffPreview|PORTAL_F(?:60|63|64|65|66|69|70|71|72|73|93|94)_[A-Z0-9_]+|f(?:60|63|65|66|69|71|72|73|93|94)-(?:render|export|verify|create|check|audit)[\w.-]*)/i;
let n=0;
function walk(rel){
  const path=join(portal,rel);
  if(!existsSync(path))return;
  const stat=lstatSync(path);
  if(stat.isSymbolicLink())throw Error('symlink in ingress tree');
  if(stat.isDirectory()){
    for(const entry of readdirSync(path))walk(join(rel,entry));
    return;
  }
  if(!stat.isFile() || stat.size>1024*1024)throw Error('unsupported ingress file');
  if(rel==='public/assets/logo-imagine.png'){
    // Exception is limited to the exact original Imagine brand asset.
    // Never turn this into a general permission for arbitrary public files.
    if(stat.size!==5594)throw Error('unexpected original Imagine logo size');
    const bytes=readFileSync(path);
    const digest=createHash('sha1').update('blob '+bytes.length+'\0').update(bytes).digest('hex');
    if(digest!=='678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77')
      throw Error('original Imagine logo integrity mismatch');
    n++;
    return;
  }
  if(basename(path)==='.gitignore')return; // scaffold empty cache folder
  if(!['.php','.js','.mjs','.cjs','.json','.htaccess'].includes(extname(path)) &&
    basename(path)!=='.htaccess')throw Error('unexpected ingress asset');
  const contents=readFileSync(path,'utf8');
  if(blocked.test(contents))throw Error('test-only import/flag');
  if(/\b(?:require|include)(?:_once)?\s*(?:\(|\s)[^\n]{0,180}\btools\b/i.test(contents))
    throw Error('direct CLI tool import');
  if(rel.startsWith('public/') || rel.startsWith('bootstrap/')){
    if(/\b(?:eval|shell_exec|passthru|proc_open|system)\s*\(/i.test(contents))
      throw Error('unexpected dynamic command in ingress');
  }
  n++;
}
try{
  const publicDir=join(portal,'public');
  const listed=readdirSync(publicDir).sort();
  if(listed.join(',')!=='.htaccess,assets,index.php')throw Error('unexpected public entries');
  if(readdirSync(join(publicDir,'assets')).sort().join(',')!=='logo-imagine.png')
    throw Error('unexpected public assets');
  for(const rel of ['routes','bootstrap','public','config','app/Http'])walk(rel);
  if(n<10)throw Error('missing expected ingress baseline');
  process.stdout.write(JSON.stringify({
    schema_version:'portal.f94.static-ingress-no-labs.v1',
    simulation_only:true,production_approved:false,source_files_checked:n,
    runtime_image_examined:false
  })+'\n');
}catch{deny();}
