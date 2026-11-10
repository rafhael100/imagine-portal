import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,readFileSync,readdirSync,existsSync,rmSync,symlinkSync,renameSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createHash} from 'node:crypto';

const env={...process.env,APP_ENV:'testing',PORTAL_F64_BUDGET_PREVIEW_LAB:'1',
  PORTAL_F65_BUDGET_HTML_LAB:'1',PORTAL_F66_BUDGET_CSV_LAB:'1',
  PORTAL_F69_CSV_INTEGRITY_LAB:'1',PORTAL_F71_REVIEW_BUNDLE_LAB:'1',
  PORTAL_F72_BUNDLE_VERIFIER_LAB:'1'};
const scenario={
  schema_version:'perf.planning.offline-budget.synthetic.v1',
  scenario_ref:'scenario-demo-f72-a',currency:'BRL',
  start_date:'2028-02-28',end_date:'2028-03-01',number_of_days:3,
  total_centavos:10001,channel_split_bp:{google_ads:5500,meta_ads:4500},
  channel_totals_centavos:{google_ads:5500,meta_ads:4501},
  daily:[
    {date:'2028-02-28',total_centavos:3334,google_ads_centavos:1833,meta_ads_centavos:1501},
    {date:'2028-02-29',total_centavos:3334,google_ads_centavos:1834,meta_ads_centavos:1500},
    {date:'2028-03-01',total_centavos:3333,google_ads_centavos:1833,meta_ads_centavos:1500},
  ],simulation_only:true,advertiser_approval_verified:false,provider_connected:false,
  media_execution_allowed:false,billing_allowed:false,ready_for_production:false
};
const dto={
  schema_version:'perf.portal.credential-bound-budgets.synthetic.v1',
  status:'credential_scoped_demo_budget_preview_only',
  simulation_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,scenarios:[scenario]
};
const root=mkdtempSync(join(tmpdir(),'portal-f72-test-'));
let workspace;
const invoke=(cmd,args,environment=env)=>spawnSync('node',[cmd,...args],{
  encoding:'utf8',env:environment,maxBuffer:1024*1024
});
let checks=0;
const check=(condition,label)=>{assert.ok(condition,label);checks++;};
const deny=(label,environment)=>{
  const result=invoke('apps/portal/tools/f72-verify-offline-review-bundle.mjs',[workspace],environment);
  check(result.status!==0 && result.stdout==='',label+' refused without output');
};
const sha=v=>createHash('sha256').update(v).digest('hex');
try{
  const input=join(root,'dto.json');
  writeFileSync(input,JSON.stringify(dto));
  const generated=invoke('apps/portal/tools/f71-create-offline-budget-review.mjs',[input]);
  check(generated.status===0,'generated: '+generated.stderr);
  workspace=JSON.parse(generated.stdout).workspace;
  check(!existsSync(join(workspace,'input.json')),'F71 must delete copied DTO');
  check(readdirSync(workspace).sort().join(',')==='budget.csv,manifest.json,preview.html',
    'only three non-sensitive output files');
  const original={};
  for(const f of ['budget.csv','manifest.json','preview.html']){
    original[f]=readFileSync(join(workspace,f));
  }
  const receipt=JSON.parse(original['manifest.json'].toString());
  check(receipt.source_snapshot_removed===true,'no persisted input declared');
  check(receipt.html_sha256===sha(original['preview.html']),'HTML SHA-256 receipt');
  check(receipt.integrity.csv_sha256===sha(original['budget.csv']),'CSV SHA-256 receipt');
  const valid=invoke('apps/portal/tools/f72-verify-offline-review-bundle.mjs',[workspace]);
  check(valid.status===0 && valid.stderr==='',valid.stderr);
  const report=JSON.parse(valid.stdout);
  check(report.rows===3 && !report.operational_authority && report.simulation_only,
    'report is synthetic-only');
  check(report.html_sha256===sha(original['preview.html']),'independent HTML digest');
  check(report.csv_sha256===sha(original['budget.csv']),'independent CSV digest');
  const mutate=(name,change)=>{
    const path=join(workspace,name);
    writeFileSync(path,change(original[name]));
    deny('tampered '+name);
    writeFileSync(path,original[name]);
  };
  mutate('preview.html',b=>Buffer.from(b.toString()+'<!-- altered -->'));
  mutate('preview.html',b=>Buffer.from(b.toString().replace(
    "script-src 'none'","script-src 'self'")));
  mutate('budget.csv',b=>Buffer.from(b.toString().replace(';1834;1500;3334',';1834;1501;3334')));
  mutate('budget.csv',b=>Buffer.from(b.toString()+'scenario-demo-f72-a;2028-03-02;1;0;1\r\n'));
  mutate('manifest.json',b=>Buffer.from(b.toString().replace(
    '"authorized_to_spend": false','"authorized_to_spend": true')));
  mutate('manifest.json',b=>Buffer.from(b.toString().replace(
    '"source_snapshot_removed": true','"source_snapshot_removed": false')));
  mutate('manifest.json',b=>Buffer.from(b.toString().replace(
    '"html_file": "preview.html"','"html_file": "../preview.html"')));
  // Extra files and symlinked artifacts are always rejected.
  writeFileSync(join(workspace,'input.json'),JSON.stringify(dto));
  deny('persisted source payload');
  rmSync(join(workspace,'input.json'));
  const source=join(workspace,'preview.html');
  const backed=join(root,'preview-original.html');
  renameSync(source,backed);
  symlinkSync(backed,source);
  deny('symlink output');
  rmSync(source);
  renameSync(backed,source);
  check(invoke('apps/portal/tools/f72-verify-offline-review-bundle.mjs',[workspace]).status===0,
    'restored bundle still valid');
  deny('production environment',{...env,APP_ENV:'production'});
  deny('lab gate off',{...env,PORTAL_F72_BUNDLE_VERIFIER_LAB:'0'});
  const noArg=invoke('apps/portal/tools/f72-verify-offline-review-bundle.mjs',[]);
  check(noArg.status!==0 && noArg.stdout==='','requires directory');
}finally{
  if(workspace)rmSync(workspace,{force:true,recursive:true});
  rmSync(root,{force:true,recursive:true});
}
console.log('PASS PORTAL-F72 '+checks+' privacy, tamper, symlink and fail-closed checks');
