import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync, mkdirSync, copyFileSync, readFileSync,
  writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, dirname, resolve} from 'node:path';

// F93 negative mutation suite: rerun the guard against a disposable copy
// of the exact F92 Docker/HTTP configuration from this branch.
const cli='apps/portal/tools/f93-check-homologation-fence.mjs';
const env={...process.env,APP_ENV:'testing',PORTAL_F93_RELEASE_FENCE_LAB:'1'};
const files=[
  'infra/homologation/f64/Dockerfile',
  'infra/homologation/f64/nginx.conf',
  'infra/homologation/f64/compose.yaml',
  'apps/portal/bootstrap/app.php',
  'apps/portal/routes/console.php',
  'apps/portal/routes/web.php',
];
const tmp=mkdtempSync(join(tmpdir(),'portal-f93-'));
const run=(path,override=env)=>spawnSync('node',[cli,...(path?[path]:[])],{
  encoding:'utf8',env:override,timeout:15000
});
let assertions=0;
function check(value,msg){assert.ok(value,msg);assertions++;}
function deny(msg,path=tmp,override=env){
  const result=run(path,override);
  check(result.status!==0 && result.stdout==='',msg);
}
const p=rel=>join(tmp,rel);
function mutate(rel,oldValue,newValue,msg){
  const original=readFileSync(p(rel),'utf8');
  check(original.includes(oldValue),'fixture must contain '+oldValue);
  writeFileSync(p(rel),original.replace(oldValue,newValue));
  deny(msg);
  writeFileSync(p(rel),original);
}
try{
  for(const f of files){
    mkdirSync(dirname(p(f)),{recursive:true});
    copyFileSync(resolve(f),p(f));
  }
  const actual=run();
  check(actual.status===0,'current F92 release fence: '+actual.stderr);
  const report=JSON.parse(actual.stdout);
  check(report.production_approved===false &&
    report.runtime_image_examined===false &&
    report.checked_services===2 && report.allowed_locations===2,
    'static report cannot be treated as a release');
  check(run(tmp).status===0,'copied unmodified F92 baseline must pass');
  mutate('apps/portal/routes/web.php',
    'Apenas /up', 'Route::post("/charge",fn()=>1); Apenas /up',
    'unapproved checkout route');
  mutate('apps/portal/bootstrap/app.php',
    "health: '/up'","health: '/ready'",
    'unapproved health route');
  writeFileSync(p('apps/portal/routes/api.php'),
    '<?php Route::post("/oauth",fn()=>1);');
  deny('unexpected new API routes file');
  rmSync(p('apps/portal/routes/api.php'));
  mutate('infra/homologation/f64/compose.yaml',
    '127.0.0.1:18080:8080','0.0.0.0:18080:8080',
    'gateway exposed on public interfaces');
  mutate('infra/homologation/f64/compose.yaml',
    'cap_drop:', 'privileged: true\n    cap_drop:',
    'new privileged compose service');
  mutate('infra/homologation/f64/compose.yaml',
    'internal: true','internal: false','Docker private network opened');
  mutate('infra/homologation/f64/Dockerfile',
    'COPY apps/portal/routes ./routes',
    'COPY apps/portal/tools ./tools',
    'CLI test tools included in Docker');
  mutate('infra/homologation/f64/Dockerfile',
    'RUN rm -rf app/Labs','RUN echo app/Labs',
    'App/Labs remains in image');
  mutate('infra/homologation/f64/Dockerfile',
    '--no-interaction --no-scripts --no-plugins --classmap-authoritative',
    '--no-interaction --no-scripts --no-plugins --no-autoloader',
    'autoload not authoritative');
  mutate('infra/homologation/f64/nginx.conf',
    'location / {','location /api/ { return 200; }\n    location / {',
    'API location added');
  mutate('infra/homologation/f64/nginx.conf',
    'limit_except GET HEAD { deny all; }',
    'limit_except GET HEAD POST { deny all; }',
    'POST unexpectedly enabled on /up');
  mutate('infra/homologation/f64/nginx.conf',
    'return 404;', 'return 200;', 'fallback no longer 404');
  deny('guard unavailable in production',tmp,{...env,APP_ENV:'production'});
  deny('guard disabled',tmp,{...env,PORTAL_F93_RELEASE_FENCE_LAB:'0'});
  check(run(tmp).status===0,'restored baseline still passes');
}finally{rmSync(tmp,{recursive:true,force:true});}
console.log('PASS PORTAL-F93 '+assertions+' immutable baseline and negative release-fence tests');
