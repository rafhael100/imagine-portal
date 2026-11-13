import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {cpSync,mkdirSync,mkdtempSync,readFileSync,writeFileSync,
  symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve,join,dirname} from 'node:path';

const tool='apps/portal/tools/f94-audit-http-ingress-imports.mjs';
const env={...process.env,APP_ENV:'testing',PORTAL_F94_INGRESS_GUARD_LAB:'1'};
const tmp=mkdtempSync(join(tmpdir(),'portal-f94-test-'));
const target=rel=>join(tmp,'apps/portal',rel);
const run=(root=tmp,e=env)=>spawnSync('node',[tool,root],{
  encoding:'utf8',env:e,timeout:15000
});
let assertions=0;
const check=(v,msg)=>{assert.ok(v,msg);assertions++;};
const deny=(msg,e=env)=>{
  const r=run(tmp,e);
  check(r.status!==0 && r.stdout==='',msg);
};
const mutate=(rel,extra,msg)=>{
  const path=target(rel),original=readFileSync(path,'utf8');
  writeFileSync(path,original+extra);
  deny(msg);
  writeFileSync(path,original);
};
try{
  mkdirSync(join(tmp,'apps/portal'),{recursive:true});
  for(const rel of ['routes','bootstrap','public','config']){
    cpSync(resolve('apps/portal',rel),target(rel),{recursive:true});
  }
  const actual=spawnSync('node',[tool],{env,encoding:'utf8'});
  check(actual.status===0,'current F92/F93 ingress clean: '+actual.stderr);
  const copied=run();
  check(copied.status===0,'fixture ingress clean: '+copied.stderr);
  const report=JSON.parse(copied.stdout);
  check(report.production_approved===false &&
    report.runtime_image_examined===false &&
    report.source_files_checked>=10,'accurate non-operational report');

  mutate('config/services.php',"\n// PORTAL_F69_CSV_INTEGRITY_LAB\n",
    'lab switch referenced in Laravel service config');
  mutate('bootstrap/app.php',"\n// use App\\Labs\\IdentityF70\\PortalTotpSecret;\n",
    'PHP bootstrap imports a lab class');
  mutate('public/index.php',
    "\n<?php require __DIR__.'/../tools/f66-export-budget-calendar-csv.php';\n",
    'Laravel public entrypoint imports offline export tool');
  mutate('public/index.php',"\n<?php shell_exec('whoami');\n",
    'unsafe command execution introduced');
  mutate('public/.htaccess',"\n# CredentialBoundBudgetPreview\n",
    'offline DTO referenced in public rewrite config');
  // Only the exact official logo is allowed in the public asset folder.
  const originalLogo=readFileSync(target('public/assets/logo-imagine.png'));
  writeFileSync(target('public/assets/logo-imagine.png'),
    Buffer.concat([originalLogo,Buffer.from('altered')]));
  deny('tampering with original Imagine logo is rejected');
  writeFileSync(target('public/assets/logo-imagine.png'),originalLogo);
  const extraAsset=target('public/assets/other-logo.png');
  writeFileSync(extraAsset,originalLogo);
  deny('a second public image is rejected');
  rmSync(extraAsset);
  const scriptAsset=target('public/assets/injected.js');
  writeFileSync(scriptAsset,'window.location="https://invalid.example";');
  deny('arbitrary public JavaScript asset is rejected');
  rmSync(scriptAsset);
  check(run().status===0,'official pinned Imagine logo remains accepted');
  const badPublic=target('public/admin.php');
  writeFileSync(badPublic,'<?php echo "admin";');
  deny('unexpected public PHP file');
  rmSync(badPublic);
  const link=target('config/link.php');
  symlinkSync(target('config/app.php'),link);
  deny('symlinked ingress config');
  rmSync(link);
  mkdirSync(target('app/Http'),{recursive:true});
  const optional=target('app/Http/UnsafeController.php');
  writeFileSync(optional,'<?php // App\\Labs\\IdentityF71\\PortalDemoIssuer');
  deny('lab reference introduced in HTTP controller');
  rmSync(target('app'),{recursive:true,force:true});
  deny('production must fail closed',{...env,APP_ENV:'production'});
  deny('disabled lab gate must fail closed',{...env,PORTAL_F94_INGRESS_GUARD_LAB:'0'});
  check(run().status===0,'restored ingress remains clean');
}finally{rmSync(tmp,{recursive:true,force:true});}
console.log('PASS PORTAL-F94 '+assertions+' ingress no-lab import and public entrypoint assertions');
