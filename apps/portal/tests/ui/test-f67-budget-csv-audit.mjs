import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

// Independent F67 audit: no spreadsheets, external services, web routes or credentials.
// Read-only cross-check of the canonical F66 CSV against an F64-validated DTO.
const run=(path)=>spawnSync('php',['apps/portal/tools/f66-export-budget-calendar-csv.php',path],{
  env:{...process.env,APP_ENV:'testing',PORTAL_F64_BUDGET_PREVIEW_LAB:'1',PORTAL_F66_BUDGET_CSV_LAB:'1'},
  encoding:'utf8',maxBuffer:512*1024,
});
const daily=[
  {date:'2028-02-28',google_ads_centavos:1833,meta_ads_centavos:1501,total_centavos:3334},
  {date:'2028-02-29',google_ads_centavos:1834,meta_ads_centavos:1500,total_centavos:3334},
  {date:'2028-03-01',google_ads_centavos:1833,meta_ads_centavos:1500,total_centavos:3333},
];
const scenario={
  schema_version:'perf.planning.offline-budget.synthetic.v1',
  scenario_ref:'scenario-demo-f106-a',currency:'BRL',
  start_date:'2028-02-28',end_date:'2028-03-01',
  number_of_days:3,total_centavos:10001,
  channel_split_bp:{google_ads:5500,meta_ads:4500},
  channel_totals_centavos:{google_ads:5500,meta_ads:4501},
  daily,simulation_only:true,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,
};
const dto={
  schema_version:'perf.portal.credential-bound-budgets.synthetic.v1',
  status:'credential_scoped_demo_budget_preview_only',
  simulation_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,scenarios:[scenario],
};
const HEADER='cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos';
const reference=(items)=>'\ufeff'+[HEADER,...items.flatMap(s=>s.daily.map(d=>[
  s.scenario_ref,d.date,d.google_ads_centavos,d.meta_ads_centavos,d.total_centavos,
].join(';')))].join('\r\n')+'\r\n';

function audit(csv,items) {
  assert.equal(csv,reference(items),'CSV must match the canonical validated budget, byte for byte');
  assert.ok(csv.startsWith('\ufeff'));
  const lines=csv.slice(1).split('\r\n');
  assert.equal(lines.pop(),'');
  assert.equal(lines.shift(),HEADER);
  for(const line of lines){
    const cells=line.split(';');
    assert.equal(cells.length,5);
    assert.match(cells[0],/^scenario-demo-[a-z0-9-]{1,50}$/);
    assert.match(cells[1],/^\d{4}-\d{2}-\d{2}$/);
    for(const v of cells.slice(2))assert.match(v,/^(0|[1-9][0-9]*)$/);
    assert.equal(Number(cells[2])+Number(cells[3]),Number(cells[4]));
  }
  return lines.length;
}
const dir=mkdtempSync(join(tmpdir(),'portal-f67-'));
let assertions=0;
const reject=(name,csv,items)=>{
  assert.throws(()=>audit(csv,items),undefined,name);
  assertions++;
};
try{
  const path=join(dir,'synthetic.json');
  writeFileSync(path,JSON.stringify(dto));
  const result=run(path);
  assert.equal(result.status,0,result.stderr);
  assert.equal(result.stderr,'');
  assert.equal(audit(result.stdout,[scenario]),3);
  assertions+=3;
  const good=result.stdout;
  reject('missing BOM',good.slice(1),[scenario]);
  reject('LF instead of CRLF',good.replaceAll('\r\n','\n'),[scenario]);
  reject('changed centavo',good.replace(';1834;1500;3334',';1834;1501;3334'),[scenario]);
  reject('spreadsheet formula',good.replace('scenario-demo-f106-a','=HYPERLINK("https://bad")'),[scenario]);
  reject('new column',good.replace(HEADER,HEADER+';account_id'),[scenario]);
  reject('extra row',good+good.split('\r\n')[1]+'\r\n',[scenario]);
  reject('foreign scenario',good.replaceAll('scenario-demo-f106-a','scenario-demo-other'),[scenario]);
  reject('missing leap day',good.replace(/^scenario-demo-f106-a;2028-02-29;.*\r\n/m,''),[scenario]);
  reject('reordered days',good.replace(
    /scenario-demo-f106-a;2028-02-28;.*\r\nscenario-demo-f106-a;2028-02-29;.*\r\n/,
    'scenario-demo-f106-a;2028-02-29;1834;1500;3334\r\nscenario-demo-f106-a;2028-02-28;1833;1501;3334\r\n'),[scenario]);
  const unsafe={...dto,scenarios:[{...scenario,provider_connected:true}]};
  writeFileSync(path,JSON.stringify(unsafe));
  const refused=run(path);
  assert.notEqual(refused.status,0);
  assert.equal(refused.stdout,'');
  assertions+=2;
} finally {rmSync(dir,{recursive:true,force:true});}
console.log('PASS PORTAL-F67 '+assertions+' independent CSV round-trip/tamper assertions');
