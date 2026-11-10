import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const tool='apps/portal/tools/f66-export-budget-calendar-csv.php';
const env={...process.env,APP_ENV:'testing',
  PORTAL_F64_BUDGET_PREVIEW_LAB:'1',PORTAL_F66_BUDGET_CSV_LAB:'1'};
const invoke=(filename,environment=env)=>spawnSync('php',[tool,filename],{
  encoding:'utf8',env:environment
});
const scenario={
  schema_version:'perf.planning.offline-budget.synthetic.v1',
  scenario_ref:'scenario-demo-f106-a',
  currency:'BRL',
  start_date:'2028-02-28',end_date:'2028-03-01',
  number_of_days:3,total_centavos:10001,
  channel_split_bp:{google_ads:5500,meta_ads:4500},
  channel_totals_centavos:{google_ads:5500,meta_ads:4501},
  daily:[
    {date:'2028-02-28',total_centavos:3334,google_ads_centavos:1833,meta_ads_centavos:1501},
    {date:'2028-02-29',total_centavos:3334,google_ads_centavos:1834,meta_ads_centavos:1500},
    {date:'2028-03-01',total_centavos:3333,google_ads_centavos:1833,meta_ads_centavos:1500},
  ],
  simulation_only:true,advertiser_approval_verified:false,provider_connected:false,
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
let n=0;
const test=(name,bool)=>{assert.equal(bool,true,'PORTAL-F66 '+name);n++};
const dir=mkdtempSync(join(tmpdir(),'portal-f66-'));
const path=join(dir,'budget.json');
const write=(x)=>writeFileSync(path,JSON.stringify(x));
const parse=(csv)=>{
  test('UTF-8 BOM',csv.startsWith('\ufeff'));
  test('CRLF rows',csv.includes('\r\n'));
  const lines=csv.replace(/^\ufeff/,'').trimEnd().split('\r\n');
  test('semicolon delim',lines[0]==='cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos');
  const rows=lines.slice(1).map(line=>line.split(';'));
  for(const row of rows){
    test('five fields',row.length===5);
    test('safe DEMO scenario ref',/^scenario-demo-[a-z0-9-]{1,50}$/.test(row[0]));
    test('UTC canonical date',/^\d{4}-\d{2}-\d{2}$/.test(row[1]));
    for(const value of row.slice(2)) {
      test('integer centavos, no formula',/^(0|[1-9][0-9]*)$/.test(value));
    }
  }
  return rows;
};
try{
  write(dto);
  const r=invoke(path);
  test('valid DTO CSV exit zero',r.status===0);
  test('no STDERR',r.stderr==='');
  const rows=parse(r.stdout);
  test('three inclusive calendar rows',rows.length===3);
  test('leap day row',rows[1][1]==='2028-02-29');
  test('first day values',rows[0].join(';')==='scenario-demo-f106-a;2028-02-28;1833;1501;3334');
  test('middle day values',rows[1].join(';')==='scenario-demo-f106-a;2028-02-29;1834;1500;3334');
  test('last day values',rows[2].join(';')==='scenario-demo-f106-a;2028-03-01;1833;1500;3333');
  test('centavo day sums',rows.every(x=>Number(x[2])+Number(x[3])===Number(x[4])));
  const sums=[2,3,4].map(col=>rows.reduce((sum,x)=>sum+Number(x[col]),0));
  test('Google sum 5500',sums[0]===5500);
  test('Meta sum 4501',sums[1]===4501);
  test('budget sum 10001',sums[2]===10001);
  test('no script or links',!/(<script|http[s]?:\/\/|<a\b)/i.test(r.stdout));
  test('no SQL UUID or secrets',!/(88888888-8888|refresh_token|access_token|oauth_token)/i.test(r.stdout));
  test('no CSV formula prefix',!/(^|[;\r\n])\s*[=+@\t]/.test(r.stdout));

  // Validate the entire payload before any CSV is written to STDOUT.
  const invalids=[
    ['live advertisement connected',{...dto,provider_connected:true}],
    ['dispatch allowed',{...dto,media_execution_allowed:true}],
    ['billing allowed',{...dto,billing_allowed:true}],
    ['user Portal authenticated',{...dto,portal_identity_authenticated:true}],
    ['wrong tenant binding',{...dto,credential_binding_confirmed:false}],
    ['extra provider token',{...dto,access_token:'secret'}],
    ['real scenario ID',{...dto,scenarios:[{...scenario,scenario_ref:'=HYPERLINK("x")'}]}],
    ['one cent mismatch',{...dto,scenarios:[{
      ...scenario,daily:scenario.daily.map((d,i)=>i===2?{...d,meta_ads_centavos:1501}:d)
    }]}],
    ['wrong currency',{...dto,scenarios:[{...scenario,currency:'USD'}]}],
    ['fake approved provider',{...dto,scenarios:[{...scenario,advertiser_approval_verified:true}]}],
    ['unsafe extra field',{...dto,scenarios:[{...scenario,customer_email:'test@example.com'}]}],
  ];
  for(const [label,payload] of invalids){
    write(payload);
    const fail=invoke(path);
    test(label+' rejected with no CSV leak',fail.status!==0 && fail.stdout==='');
  }
  write(dto);
  for(const [label,environment] of [
    ['production',{...env,APP_ENV:'production'}],
    ['F64 validation off',{...env,PORTAL_F64_BUDGET_PREVIEW_LAB:'0'}],
    ['F66 export off',{...env,PORTAL_F66_BUDGET_CSV_LAB:'0'}],
  ]) {
    const fail=invoke(path,environment);
    test(label+' fail closed',fail.status!==0 && fail.stdout==='');
  }
  const linked=join(dir,'symlink.json');
  symlinkSync(path,linked);
  test('symlink rejected',invoke(linked).status!==0);
  test('missing input rejected',invoke(join(dir,'none.json')).status!==0);
  test('missing CLI arg rejected',spawnSync('php',[tool],{encoding:'utf8',env}).status!==0);

  if(process.argv[2]){
    const sql=invoke(process.argv[2]);
    test('actual PostgreSQL F107 JSON accepted',sql.status===0);
    const actual=parse(sql.stdout);
    test('actual DB three rows',actual.length===3);
    test('actual leap day',actual[1][1]==='2028-02-29');
    test('actual DB total 10001',actual.reduce((sum,x)=>sum+Number(x[4]),0)===10001);
    test('actual DB Google 5500',actual.reduce((sum,x)=>sum+Number(x[2]),0)===5500);
    test('actual DB Meta 4501',actual.reduce((sum,x)=>sum+Number(x[3]),0)===4501);
  }
} finally {
  rmSync(dir,{recursive:true,force:true});
}
console.log('PASS PORTAL-F66 '+n+' offline centavos CSV, no-execution and formula-injection assertions');
