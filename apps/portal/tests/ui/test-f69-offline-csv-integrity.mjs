import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,readFileSync,writeFileSync,symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {createHash} from 'node:crypto';

const env={...process.env,APP_ENV:'testing',PORTAL_F64_BUDGET_PREVIEW_LAB:'1',
  PORTAL_F66_BUDGET_CSV_LAB:'1',PORTAL_F69_CSV_INTEGRITY_LAB:'1'};
const scenario={
  schema_version:'perf.planning.offline-budget.synthetic.v1',
  scenario_ref:'scenario-demo-f69-a',currency:'BRL',
  start_date:'2028-02-28',end_date:'2028-03-01',number_of_days:3,
  total_centavos:10001,channel_split_bp:{google_ads:5500,meta_ads:4500},
  channel_totals_centavos:{google_ads:5500,meta_ads:4501},
  daily:[
    {date:'2028-02-28',total_centavos:3334,google_ads_centavos:1833,meta_ads_centavos:1501},
    {date:'2028-02-29',total_centavos:3334,google_ads_centavos:1834,meta_ads_centavos:1500},
    {date:'2028-03-01',total_centavos:3333,google_ads_centavos:1833,meta_ads_centavos:1500},
  ],
  simulation_only:true,advertiser_approval_verified:false,provider_connected:false,
  media_execution_allowed:false,billing_allowed:false,ready_for_production:false,
};
const dto={
  schema_version:'perf.portal.credential-bound-budgets.synthetic.v1',
  status:'credential_scoped_demo_budget_preview_only',
  simulation_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,scenarios:[scenario],
};
const exporter='apps/portal/tools/f66-export-budget-calendar-csv.php';
const verifier='apps/portal/tools/f69-verify-budget-csv.mjs';
const dir=mkdtempSync(join(tmpdir(),'portal-f69-'));
const json=join(dir,'dto.json'),csv=join(dir,'export.csv');
const run=(args,environment=env)=>spawnSync('node',[verifier,...args],{
  env:environment,encoding:'utf8',maxBuffer:1024*1024,
});
let count=0;
const check=(v,label)=>{assert.ok(v,label);count++;};
const deny=(label,args,environment)=>{
  const r=run(args,environment);
  check(r.status!==0,label+' exit');
  check(r.stdout==='',label+' no attestation leak');
};
try {
  writeFileSync(json,JSON.stringify(dto));
  const source=spawnSync('php',[exporter,json],{env,encoding:'utf8'});
  check(source.status===0,'canonical F66 source');
  writeFileSync(csv,source.stdout);
  const ok=run([json,csv]);
  check(ok.status===0,'verified CSV');
  check(ok.stderr==='', 'no stderr on valid CSV');
  const att=JSON.parse(ok.stdout);
  check(att.schema_version==='portal.f69.offline-csv-integrity.synthetic.v1','schema');
  check(att.simulation_only===true && att.operational_authority===false,'no operational authority');
  check(att.data_rows===3,'three dated rows');
  const sha=buffer=>createHash('sha256').update(buffer).digest('hex');
  check(att.dto_sha256===sha(readFileSync(json)),'DTO checksum');
  check(att.csv_sha256===sha(readFileSync(csv)),'CSV checksum');
  check(!ok.stdout.includes('scenario-demo-f69') && !ok.stdout.includes('access_token'),
    'no scenario or token leak');
  for(const [label,bytes] of [
    ['edited centavo',source.stdout.replace(';1834;1500;3334',';1834;1501;3334')],
    ['changed scenario',source.stdout.replaceAll('scenario-demo-f69-a','scenario-demo-f69-b')],
    ['removed leap day',source.stdout.replace(/^scenario-demo-f69-a;2028-02-29;.*\r\n/m,'')],
    ['spreadsheet formula',source.stdout.replace('scenario-demo-f69-a','=HYPERLINK("bad")')],
    ['modified BOM',source.stdout.slice(1)],
    ['modified line endings',source.stdout.replaceAll('\r\n','\n')],
    ['injected column',source.stdout.replace('total_centavos','total_centavos;private')],
    ['appended row',source.stdout+source.stdout.split('\r\n')[1]+'\r\n'],
  ]) {
    writeFileSync(csv,bytes);
    deny(label,[json,csv]);
  }
  writeFileSync(csv,source.stdout);
  const link=join(dir,'linked.csv');
  symlinkSync(csv,link);
  deny('symlink',[json,link]);
  deny('reversed paths',[csv,json]);
  deny('missing path',[json,join(dir,'missing.csv')]);
  deny('production',[json,csv],{...env,APP_ENV:'production'});
  deny('lab disabled',[json,csv],{...env,PORTAL_F69_CSV_INTEGRITY_LAB:'0'});
  deny('no args',[]);
  writeFileSync(json,JSON.stringify({...dto,provider_connected:true}));
  deny('real provider connection flag',[json,csv]);
} finally {rmSync(dir,{recursive:true,force:true});}
console.log('PASS PORTAL-F69 '+count+' integrity, hash and fail-closed assertions');
