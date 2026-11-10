import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFileSync,writeFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const fixture='apps/portal/tests/Fixtures/performance-workbench-offline-v1.json';
const renderer='apps/portal/tools/f60-render-performance-workbench.php';
const env={...process.env,APP_ENV:'testing',PORTAL_F59_WORKBENCH_LAB:'1',PORTAL_F60_HTML_LAB:'1'};
const run=(path=fixture,environment=env)=>spawnSync('php',[renderer,path],{env:environment,encoding:'utf8'});
const result=run();
assert.equal(result.status,0,result.stderr);
const html=result.stdout;
const checks=[
['HTML language',html.includes('<html lang="pt-BR">')],
['CSP deny default network',html.includes("default-src 'none'")],
['DEMO label',html.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA')],
['no external providers',html.includes('Nenhuma conta Google Ads ou Meta foi conectada')],
['Google channel',html.includes('Google Ads')],
['Meta channel',html.includes('Meta (Instagram e/ou Facebook)')],
['paused example',html.includes('Pausada DEMO')],
['approval no action',html.includes('Revisão interna DEMO — sem execução')],
['rejection',html.includes('Solicitação recusada DEMO')],
['no JS',!/<script(?:\s|>)/i.test(html)],
['no links',!/<a\s|<link(?:\s|>)|https?:\/\//i.test(html)],
['no forms',!/<form(?:\s|>)/i.test(html)],
['no images',!/<(?:img|iframe|video)(?:\s|>)/i.test(html)],
['read-only mention',html.includes('Ações, aprovações operacionais e faturamento estão bloqueados')],
];
for(const [name,value] of checks)assert.equal(value,true,'F60 '+name);
for(const wrong of [{...env,APP_ENV:'production'},{...env,PORTAL_F59_WORKBENCH_LAB:'0'},{...env,PORTAL_F60_HTML_LAB:'0'}]){
 const r=run(fixture,wrong);
 assert.notEqual(r.status,0,'F60 unexpected execution outside lab');
}
const dir=mkdtempSync(join(tmpdir(),'perf-f60-'));
try {
 const v=JSON.parse(readFileSync(fixture,'utf8'));
 const malicious={...v,campaigns:v.campaigns.map((x,i)=>i===0?{...x,title:'<script>alert(1)</script>'}:x)};
 const file=join(dir,'malicious.json');
 writeFileSync(file,JSON.stringify(malicious));
 assert.notEqual(run(file).status,0,'F60 failed to deny injected title');
 const wrongSchema=join(dir,'wrong.json');
 writeFileSync(wrongSchema,JSON.stringify({...v,schema_version:'v2'}));
 assert.notEqual(run(wrongSchema).status,0,'F60 failed to deny wrong schema');
}finally{rmSync(dir,{recursive:true,force:true});}
if(process.argv[2]){
 const real=run(process.argv[2]);
 assert.equal(real.status,0,'F60 must consume actual F84 database JSON: '+real.stderr);
 assert.ok(real.stdout.includes('Campanhas gerenciadas'),'real model summary');
 assert.ok(real.stdout.includes('Solicitações de revisão fictícias'),'real model review');
 assert.ok(real.stdout.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA'),'real model remains no-go');
}
console.log('PASS PORTAL-F60 '+(checks.length+3+2+(process.argv[2]?3:0))+' offline HTML and denial assertions');
