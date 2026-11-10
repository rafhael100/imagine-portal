import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

// PORTAL-F68: deterministic boundary and cross-scenario verification of F66.
// Intentionally standalone, synthetic, offline: no authenticated customers,
// network, Ads accounts, approvals or financial operations.
const header='cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos';
const date=(start,day)=>new Date(Date.parse(start+'T00:00:00Z')+day*86400000).toISOString().slice(0,10);
function scenario(index,days,total,googleBP) {
  const start='2028-01-01';
  const base=Math.floor(total/days),remainder=total%days;
  let cumulative=0,previousGoogle=0;
  const daily=Array.from({length:days},(_,i)=>{
    const dailyTotal=base+(i<remainder?1:0);
    cumulative+=dailyTotal;
    const googleCumulative=Math.floor(cumulative*googleBP/10000);
    const google=googleCumulative-previousGoogle;
    previousGoogle=googleCumulative;
    return {
      date:date(start,i),total_centavos:dailyTotal,
      google_ads_centavos:google,meta_ads_centavos:dailyTotal-google,
    };
  });
  const googleTotal=Math.floor(total*googleBP/10000);
  return {
    schema_version:'perf.planning.offline-budget.synthetic.v1',
    scenario_ref:'scenario-demo-f68-'+index,currency:'BRL',
    start_date:start,end_date:date(start,days-1),
    number_of_days:days,total_centavos:total,
    channel_split_bp:{google_ads:googleBP,meta_ads:10000-googleBP},
    channel_totals_centavos:{google_ads:googleTotal,meta_ads:total-googleTotal},
    daily,simulation_only:true,advertiser_approval_verified:false,
    provider_connected:false,media_execution_allowed:false,billing_allowed:false,
    ready_for_production:false,
  };
}
const dto=(scenarios)=>({
  schema_version:'perf.portal.credential-bound-budgets.synthetic.v1',
  status:'credential_scoped_demo_budget_preview_only',
  simulation_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,scenarios,
});
const dir=mkdtempSync(join(tmpdir(),'portal-f68-'));
const file=join(dir,'scenario.json');
const invoke=(data,override={})=>{
  writeFileSync(file,JSON.stringify(data));
  return spawnSync('php',['apps/portal/tools/f66-export-budget-calendar-csv.php',file],{
    encoding:'utf8',maxBuffer:1024*1024,
    env:{...process.env,APP_ENV:'testing',PORTAL_F64_BUDGET_PREVIEW_LAB:'1',
      PORTAL_F66_BUDGET_CSV_LAB:'1',...override},
  });
};
let assertions=0;
const check=(ok,message)=>{assert.ok(ok,message);assertions++;};
const expected=(scenarios)=>'\ufeff'+[
  header,...scenarios.flatMap(s=>s.daily.map(d=>[
    s.scenario_ref,d.date,d.google_ads_centavos,d.meta_ads_centavos,d.total_centavos,
  ].join(';'))),
].join('\r\n')+'\r\n';
const accept=(label,scenarios)=>{
  const result=invoke(dto(scenarios));
  check(result.status===0,label+': '+result.stderr);
  check(result.stderr==='',label+': unexpected STDERR');
  check(result.stdout===expected(scenarios),label+': noncanonical CSV bytes');
  const lines=result.stdout.slice(1).trimEnd().split('\r\n');
  check(lines.length===1+scenarios.reduce((n,s)=>n+s.number_of_days,0),
    label+': unexpected row count');
  for(const s of scenarios) {
    const rows=lines.slice(1).filter(line=>line.startsWith(s.scenario_ref+';'));
    check(rows.length===s.number_of_days,label+': scenario row bleed');
    const sums=[2,3,4].map(col=>rows.reduce((n,line)=>n+Number(line.split(';')[col]),0));
    check(sums[0]===s.channel_totals_centavos.google_ads,label+': Google totals');
    check(sums[1]===s.channel_totals_centavos.meta_ads,label+': Meta totals');
    check(sums[2]===s.total_centavos,label+': daily totals');
  }
};
const deny=(label,payload,override)=>{
  const result=invoke(payload,override);
  check(result.status!==0,label+': unexpectedly accepted');
  check(result.stdout==='',label+': partial customer-facing output');
};
try {
  accept('empty scenarios',[]);
  accept('single centavo, single day, Google 0%',[scenario('zero',1,1,0)]);
  accept('single centavo, Meta 0%',[scenario('full',1,1,10000)]);
  accept('leap-year 366 days with very small total',[scenario('leap',366,1,5500)]);
  accept('maximum synthetic 20 scenarios',Array.from({length:20},(_,i)=>
    scenario('batch-'+i,i%2===0?3:5,10001+i,i%3===0?0:i%3===1?10000:5500)));
  const s=scenario('one',3,10001,5500);
  deny('duplicated ref',dto([s,{...s}]));
  deny('21 scenarios',dto(Array.from({length:21},(_,i)=>scenario('overflow-'+i,1,100,5000))));
  deny('wrong status', {...dto([s]),status:'approved'});
  deny('unexpected tenant reference', {...dto([s]),tenant_id:'synthetic-other'});
  deny('one cent changed',dto([{...s,daily:s.daily.map((x,i)=>i===0?
    {...x,meta_ads_centavos:x.meta_ads_centavos+1}:x)}]));
  deny('daily date changed',dto([{...s,daily:s.daily.map((x,i)=>i===1?
    {...x,date:'2028-02-29'}:x)}]));
  deny('wrong channel split',dto([{...s,channel_split_bp:{google_ads:5501,meta_ads:4499}}]));
  deny('operational flag',dto([{...s,media_execution_allowed:true}]));
  deny('production mode',dto([s]),{APP_ENV:'production'});
  deny('F66 lab disabled',dto([s]),{PORTAL_F66_BUDGET_CSV_LAB:'0'});
} finally {rmSync(dir,{recursive:true,force:true});}
console.log('PASS PORTAL-F68 '+assertions+' multiscenario/edge/security assertions');
