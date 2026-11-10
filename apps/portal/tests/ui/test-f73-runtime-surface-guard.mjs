import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,mkdirSync,readFileSync,writeFileSync,rmSync,symlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const env={...process.env,APP_ENV:'testing',PORTAL_F73_LAB_RUNTIME_GUARD:'1'};
const audit='apps/portal/tools/f73-audit-offline-runtime-surface.mjs';
const run=(root,environment=env)=>spawnSync('node',[audit,...root?[root]:[]],{
  env:environment,encoding:'utf8'
});
let n=0;
const check=(condition,label)=>{assert.ok(condition,label);n++};
const denied=(root,label,environment)=>{
  const result=run(root,environment);
  check(result.status!==0 && result.stdout==='',label+' denied with no report');
};
const root=mkdtempSync(join(tmpdir(),'portal-f73-ci-'));
const path=rel=>join(root,rel);
const write=(rel,content)=>writeFileSync(path(rel),content);
try{
  // Verify the ACTUAL Laravel scaffold at this exact checkout, not only fixtures.
  const current=run();
  check(current.status===0,'current Laravel pre-homologation routes: '+current.stderr);
  const report=JSON.parse(current.stdout);
  check(report.production_approved===false && report.pre_homologation_only===true,
    'audit is not authorization');
  check(report.reviewed_source_files>=3,'real runtime files inspected');
  for(const d of ['routes','bootstrap','public','config'])mkdirSync(path(d));
  write('routes/web.php','<?php // no HTTP business routes\n');
  write('routes/console.php','<?php // no route bindings\n');
  write('bootstrap/app.php',"<?php return Application::configure()->withRouting(health: '/up')->create();\n");
  write('public/index.php','<?php echo "bootstrap placeholder";\n');
  write('config/app.php','<?php return [];\n');
  check(run(root).status===0,'safe minimal runtime allowed');
  const mutate=(rel,source,label)=>{
    const original=readFileSync(path(rel),'utf8');
    write(rel,source);
    denied(root,label);
    write(rel,original);
  };
  mutate('routes/web.php',"<?php Route::get('/login', fn()=> 'demo');\n",
    'HTTP login route before homologation');
  mutate('routes/console.php',"<?php Route::post('/media', fn()=> 'demo');\n",
    'Route in console script');
  mutate('bootstrap/app.php',"<?php return Application::configure()->withRouting(health: '/ready')->create();\n",
    'unexpected health route');
  mutate('bootstrap/app.php',"<?php return Application::configure()->withRouting(api: 'routes/api.php',health: '/up')->create();\n",
    'new API route provider');
  mutate('public/index.php',"<?php require '../tools/f66-export-budget-calendar-csv.php';\n",
    'exposed CSV tool in public');
  mutate('public/index.php',"<?php $x = 'PORTAL_F71_REVIEW_BUNDLE_LAB';\n",
    'laboratory flag exposed in public');
  mutate('config/app.php',"<?php return ['lab'=>'CredentialBoundBudgetPreview'];\n",
    'test-only Domain class in runtime config');
  write('routes/api.php',"<?php Route::get('/billing', fn()=> 'unsafe');\n");
  denied(root,'API business route');
  rmSync(path('routes/api.php'));
  symlinkSync(path('config/app.php'),path('public/config-link.php'));
  denied(root,'symbolic PHP entry under public');
  rmSync(path('public/config-link.php'));
  check(run(root).status===0,'clean fixture still passes');
  denied(root,'production env denied',{...env,APP_ENV:'production'});
  denied(root,'guard disabled denied',{...env,PORTAL_F73_LAB_RUNTIME_GUARD:'0'});
}finally {rmSync(root,{force:true,recursive:true});}
console.log('PASS PORTAL-F73 '+n+' runtime boundary / NO-GO assertions');
