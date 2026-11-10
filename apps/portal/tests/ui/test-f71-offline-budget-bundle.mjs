import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,readFileSync,writeFileSync,lstatSync,existsSync,symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createHash} from 'node:crypto';

const env={...process.env,APP_ENV:'testing',PORTAL_F64_BUDGET_PREVIEW_LAB:'1',
  PORTAL_F65_BUDGET_HTML_LAB:'1',PORTAL_F66_BUDGET_CSV_LAB:'1',
  PORTAL_F69_CSV_INTEGRITY_LAB:'1',PORTAL_F71_REVIEW_BUNDLE_LAB:'1'};
const scenario={
  schema_version:'perf.planning.offline-budget.synthetic.v1',
  scenario_ref:'scenario-demo-f71-a',currency:'BRL',
  start_date:'2028-02-28',end_date:'2028-03-01',number_of_days:3,
  total_centavos:10001,channel_split_bp:{google_ads:5500,meta_ads:4500},
  channel_totals_centavos:{google_ads:5500,meta_ads:4501},
  daily:[
    {date:'2028-02-28',total_centavos:3334,google_ads_centavos:1833,meta_ads_centavos:1501},
    {date:'2028-02-29',total_centavos:3334,google_ads_centavos:1834,meta_ads_centavos:1500},
    {date:'2028-03-01',total_centavos:3333,google_ads_centavos:1833,meta_ads_centavos:1500},
  ],simulation_only:true,advertiser_approval_verified:false,provider_connected:false,
  media_execution_allowed:false,billing_allowed:false,ready_for_production:false,
};
const dto={
  schema_version:'perf.portal.credential-bound-budgets.synthetic.v1',
  status:'credential_scoped_demo_budget_preview_only',
  simulation_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,scenarios:[scenario]
};
const tool='apps/portal/tools/f71-create-offline-budget-review.mjs';
const root=mkdtempSync(join(tmpdir(),'portal-f71-ci-'));
const input=join(root,'budget.json');
const run=(args,environment=env)=>spawnSync('node',[tool,...args],{env:environment,encoding:'utf8'});
let assertions=0;
const check=(v,msg)=>{assert.ok(v,msg);assertions++;};
let workspace;
try {
  writeFileSync(input,JSON.stringify(dto));
  const ok=run([input]);
  check(ok.status===0 && ok.stderr==='', 'valid bundle');
  const response=JSON.parse(ok.stdout);
  workspace=response.workspace;
  check(response.rows===3,'three rows');
  check(response.files.join(',')==='preview.html,budget.csv,manifest.json','only review files advertised');
  const html=readFileSync(join(workspace,'preview.html'),'utf8');
  const csv=readFileSync(join(workspace,'budget.csv'));
  const manifest=JSON.parse(readFileSync(join(workspace,'manifest.json'),'utf8'));
  check(html.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA'),'persistent warning');
  check(!/<script\b|<form\b|<button\b/i.test(html),'noninteractive HTML');
  check(html.includes('R$ 100,01') && html.includes('2028-02-29'),'correct displayed budget');
  check(csv.toString('utf8').startsWith('\ufeff') && csv.toString('utf8').includes('2028-02-29'),'CSV leap day');
  check(csv.toString('utf8').trimEnd().split('\r\n').length===4,'CSV 3 rows');
  check(manifest.simulation_only && !manifest.approved_for_production &&
    !manifest.authorized_to_spend && !manifest.authenticated_customer_session,'NO-GO flags');
  const hash=b=>createHash('sha256').update(b).digest('hex');
  check(manifest.integrity.csv_sha256===hash(csv),'manifest CSV digest');
  check(manifest.html_sha256===hash(Buffer.from(html)),'manifest HTML digest');
  check(manifest.source_snapshot_removed===true,'source removed claim');
  check(!lstatSync(workspace).isSymbolicLink(),'bundle directory not symlink');
  check(manifest.integrity.dto_sha256===hash(readFileSync(input)),'manifest snapshot digest');
  check(!existsSync(join(workspace,'input.json')),'source DTO not retained');
  for(const f of ['preview.html','budget.csv','manifest.json']){
    check(lstatSync(join(workspace,f)).isFile(),f+' physical file');
  }
  rmSync(workspace,{recursive:true,force:true});workspace=undefined;
  for(const [label,payload] of [
    ['operational flag',{...dto,media_execution_allowed:true}],
    ['customer info',{...dto,customer_email:'secret@example.test'}],
    ['invalid cents',{...dto,scenarios:[{...scenario,total_centavos:10002}]}],
  ]) {
    writeFileSync(input,JSON.stringify(payload));
    const denied=run([input]);
    check(denied.status!==0 && denied.stdout==='',label+' fail closed');
  }
  writeFileSync(input,JSON.stringify(dto));
  const linked=join(root,'symlink.json');symlinkSync(input,linked);
  check(run([linked]).status!==0,'symlink rejected');
  check(run([input],{...env,APP_ENV:'production'}).status!==0,'production rejected');
  check(run([input],{...env,PORTAL_F71_REVIEW_BUNDLE_LAB:'0'}).status!==0,'lab toggle rejected');
  check(run([]).status!==0,'required input');
} finally {
  if(workspace)rmSync(workspace,{recursive:true,force:true});
  rmSync(root,{recursive:true,force:true});
}
console.log('PASS PORTAL-F71 '+assertions+' offline review bundle and safety assertions');
