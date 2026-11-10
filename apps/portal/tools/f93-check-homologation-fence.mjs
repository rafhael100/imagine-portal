#!/usr/bin/env node
// PORTAL-F93: pre-homologation release fence for the F92 Docker/HTTP baseline.
// Static fail-closed checks; NOT a runtime/AppSec attestation or deploy approval.
import {lstatSync, readFileSync, readdirSync} from 'node:fs';
import {resolve, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const deny=()=>{process.stderr.write('PORTAL-F93 refused unsafe pre-homologation runtime surface\n');process.exit(2);};
if(process.env.APP_ENV!=='testing' ||
   process.env.PORTAL_F93_RELEASE_FENCE_LAB!=='1' ||
   process.argv.length>3)deny();

const root=resolve(process.argv[2] || join(fileURLToPath(new URL('.',import.meta.url)),'../../../..'));
const path=(name)=>join(root,name);
function read(rel,max=65536){
  const p=path(rel), stat=lstatSync(p);
  if(!stat.isFile() || stat.isSymbolicLink() || stat.size>max || stat.size===0)
    throw Error('Unsafe source file');
  return readFileSync(p,'utf8');
}
function must(condition){if(!condition)throw Error('F93 fence mismatch');}
const count=(value,regex)=>[...value.matchAll(regex)].length;
try{
  const docker=read('infra/homologation/f64/Dockerfile');
  const compose=read('infra/homologation/f64/compose.yaml');
  const nginx=read('infra/homologation/f64/nginx.conf');
  const bootstrap=read('apps/portal/bootstrap/app.php');
  const routeDir=path('apps/portal/routes');
  const entries=readdirSync(routeDir).sort();
  must(entries.join(',')==='console.php,web.php');
  for(const entry of entries) {
    const routes=read('apps/portal/routes/'+entry);
    must(!/\bRoute\s*::/i.test(routes));
    must(!/(?:f(?:60|63|65|66|69|71|72|73)-|PORTAL_F\d+_|CredentialBoundBudgetPreview)/i.test(routes));
  }
  must(/health:\s*['"]\/up['"]/.test(bootstrap));
  must(!/\bapi:\s*['"]/.test(bootstrap));
  must(!/\bRoute\s*::/.test(bootstrap));
  must(!/f(?:65|66|69|71|72|73)-|PORTAL_F\d+_/.test(bootstrap));

  // Docker: tests/labs/tools are never copied; lab classes removed BEFORE
  // Composer's authoritative classmap is rebuilt.
  const copies=[...docker.matchAll(/^\s*COPY\s+(.+)$/gm)].map(m=>m[1]);
  must(copies.length>=7);
  for(const copy of copies){
    must(copy.startsWith('--from=composer:2 ') ||
      /^apps\/portal\/(?:composer\.json apps\/portal\/composer\.lock|app \.\/app|bootstrap \.\/bootstrap|config \.\/config|public \.\/public|resources \.\/resources|routes \.\/routes|artisan \.\/artisan)$/.test(copy));
    must(!/(\btools\b|\btests\b|\blabs\b|\bdatabase\b|\.env\b)/i.test(copy));
  }
  const removal=docker.indexOf('RUN rm -rf app/Labs');
  const autoload=docker.indexOf('composer dump-autoload');
  must(removal>0 && autoload>removal);
  for(const name of [
    'OfflinePerformanceWorkbenchPreview.php',
    'CredentialBoundPerformanceWorkbenchPreview.php',
    'OfflineSeoHandoffPreview.php',
    'CredentialBoundBudgetPreview.php',
  ])must(docker.slice(removal,autoload).includes(name));
  must(docker.slice(autoload).includes('--classmap-authoritative'));
  must(docker.includes('USER www-data') && docker.includes('APP_ENV=staging'));
  must(!/\bUSER\s+root\b/.test(docker.slice(autoload)));

  // Compose: only one loopback binding; no privileged service or DB sidecar.
  const servicesSection=compose.split(/^networks:\s*$/m)[0];
  const services=[...servicesSection.matchAll(/^  ([a-z][a-z0-9_-]*):\s*$/gm)]
    .map(m=>m[1]).sort();
  must(services.join(',')==='gateway,portal');
  const ports=[...compose.matchAll(/^\s*ports:\s*\n\s*-\s*["']([^"']+)["']/gm)]
    .map(m=>m[1]);
  must(count(compose,/^\s*ports:\s*$/gm)===1);
  must(ports.join(',')==='127.0.0.1:18080:8080');
  must(count(compose,/^\s*read_only:\s*true\s*$/gm)===2);
  must(count(compose,/^\s*-\s*no-new-privileges:true\s*$/gm)===2);
  must(count(compose,/^\s*-\s*ALL\s*$/gm)===2);
  must(/private:\s*\n\s+internal:\s*true/.test(compose));
  must(!/(?:network_mode:\s*host|privileged:\s*true|pid:\s*host|ipc:\s*host)/i.test(compose));
  must(compose.includes('APP_DEBUG: "false"') &&
    compose.includes('APP_ENV: staging'));

  // Nginx: EXACT two locations, /up limited to GET/HEAD, all else 404.
  const locations=[...nginx.matchAll(/^\s*location\s+(= )?(\/[^\s{]*)\s*\{/gm)]
    .map(m=>(m[1]||'')+m[2]);
  must(locations.join(',')==='= /up,/');
  const up=nginx.match(/location\s*=\s*\/up\s*\{([\s\S]*?)^\s{4}\}/m)?.[1];
  const other=nginx.match(/location\s+\/\s*\{([\s\S]*?)\}/)?.[1];
  must(!!up && !!other && /limit_except GET HEAD\s*\{\s*deny all;\s*\}/.test(up));
  must(/return 404;/.test(other));
  must(/fastcgi_pass\s+portal:9000;/.test(up));
  must(!/\b(?:proxy_pass|rewrite|alias|try_files|include)\b/.test(nginx));
  must(count(nginx,/^\s*server\s*\{/gm)===1);

  process.stdout.write(JSON.stringify({
    schema_version:'portal.f93.static-release-fence.v1',
    simulation_only:true,production_approved:false,
    runtime_image_examined:false,
    checked_services:services.length,
    allowed_locations:locations.length,
  })+'\n');
}catch{deny();}
