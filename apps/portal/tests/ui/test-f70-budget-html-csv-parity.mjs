import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

// F70 is an offline acceptance check, NOT a web endpoint or real budget view.
const env={...process.env,APP_ENV:'testing',
  PORTAL_F64_BUDGET_PREVIEW_LAB:'1',
  PORTAL_F65_BUDGET_HTML_LAB:'1',
  PORTAL_F66_BUDGET_CSV_LAB:'1',
  PORTAL_F69_CSV_INTEGRITY_LAB:'1',
};
const scenario=(ref,days,total,bp)=>{
  const start='2028-02-28';
  const base=Math.floor(total/days),extra=total%days;
  let cumulative=0,prev=0;
  const daily=Array.from({length:days},(_,i)=>{
    const t=base+(i<extra?1:0);
    cumulative+=t;
    const gCum=Math.floor(cumulative*bp/10000);
    const google=gCum-prev;prev=gCum;
    return {date:new Date(Date.parse(start+'T00:00:00Z')+i*86400000)
      .toISOString().slice(0,10),total_centavos:t,
      google_ads_centavos:google,meta_ads_centavos:t-google};
  });
  const gTotal=Math.floor(total*bp/10000);
  return {schema_version:'perf.planning.offline-budget.synthetic.v1',
    scenario_ref:'scenario-demo-'+ref,currency:'BRL',start_date:start,
    end_date:daily.at(-1).date,number_of_days:days,total_centavos:total,
    channel_split_bp:{google_ads:bp,meta_ads:10000-bp},
    channel_totals_centavos:{google_ads:gTotal,meta_ads:total-gTotal},
    daily,simulation_only:true,advertiser_approval_verified:false,
    provider_connected:false,media_execution_allowed:false,
    billing_allowed:false,ready_for_production:false};
};
const dto=(scenarios)=>({
  schema_version:'perf.portal.credential-bound-budgets.synthetic.v1',
  status:'credential_scoped_demo_budget_preview_only',
  simulation_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,advertiser_approval_verified:false,
  provider_connected:false,media_execution_allowed:false,billing_allowed:false,
  ready_for_production:false,scenarios
});
const htmlTool='apps/portal/tools/f65-render-budget-calendar.php';
const csvTool='apps/portal/tools/f66-export-budget-calendar-csv.php';
const verifier='apps/portal/tools/f69-verify-budget-csv.mjs';
const dir=mkdtempSync(join(tmpdir(),'portal-f70-'));
const json=join(dir,'dto.json'),csvFile=join(dir,'budget.csv');
const exec=(program,args,environment=env)=>spawnSync(program,args,{
  encoding:'utf8',env:environment,maxBuffer:1024*1024
});
const currency=cents=>'R$ '+Math.floor(cents/100)
  .toString().replace(/\B(?=(\d{3})+(?!\d))/g,'.')+
  ','+String(cents%100).padStart(2,'0');
let checks=0;
const check=(value,name)=>{assert.ok(value,name);checks++;};
const accepted=(label,scenarios)=>{
  writeFileSync(json,JSON.stringify(dto(scenarios)));
  const html=exec('php',[htmlTool,json]),csv=exec('php',[csvTool,json]);
  check(html.status===0 && html.stderr==='',label+' HTML success');
  check(csv.status===0 && csv.stderr==='',label+' CSV success');
  check(html.stdout.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA'),label+' warning');
  check(html.stdout.includes("script-src 'none'"),label+' CSP no scripts');
  check(!/<script\b|<form\b|<button\b|<iframe\b/i.test(html.stdout),label+' no interactions');
  check(csv.stdout.startsWith('\ufeff'),label+' UTF8 BOM');
  const rows=csv.stdout.slice(1).trimEnd().split('\r\n');
  check(rows.shift()==='cenario_demo;data_utc;google_ads_centavos;meta_ads_centavos;total_centavos',
    label+' CSV schema');
  check(rows.length===scenarios.reduce((n,s)=>n+s.daily.length,0),label+' CSV count');
  for(const scenario of scenarios){
    check(html.stdout.includes('Cenário '+scenario.scenario_ref),label+' scenario title');
    check(html.stdout.includes(currency(scenario.total_centavos)),label+' scenario total BRL');
    const csvRows=rows.filter(row=>row.startsWith(scenario.scenario_ref+';'))
      .map(row=>row.split(';'));
    check(csvRows.length===scenario.daily.length,label+' rows for scenario');
    for(let index=0;index<scenario.daily.length;index++){
      const day=scenario.daily[index],row=csvRows[index];
      check(row.join(';')===[scenario.scenario_ref,day.date,
        day.google_ads_centavos,day.meta_ads_centavos,day.total_centavos].join(';'),
        label+' exact CSV row '+index);
      const expected='<tr><td>'+day.date+'</td>'+
        '<td class="money">'+currency(day.google_ads_centavos)+'</td>'+
        '<td class="money">'+currency(day.meta_ads_centavos)+'</td>'+
        '<td class="money">'+currency(day.total_centavos)+'</td></tr>';
      check(html.stdout.includes(expected),label+' daily HTML/CSV parity '+index);
    }
  }
  writeFileSync(csvFile,csv.stdout);
  const integrity=exec('node',[verifier,json,csvFile]);
  check(integrity.status===0,label+' F69 attestation');
  const att=JSON.parse(integrity.stdout);
  check(att.data_rows===rows.length,label+' attested count');
};
const rejected=(label,bad)=>{
  writeFileSync(json,JSON.stringify(bad));
  const html=exec('php',[htmlTool,json]),csv=exec('php',[csvTool,json]);
  check(html.status!==0 && html.stdout==='',label+' HTML closed');
  check(csv.status!==0 && csv.stdout==='',label+' CSV closed');
};
try {
  accepted('no scenarios',[]);
  accepted('mixed channels and leap day',[
    scenario('f70-google',3,10001,5500),
    scenario('f70-meta',2,101,0),
    scenario('f70-google-only',1,1,10000),
  ]);
  accepted('large rounded totals',[scenario('f70-large',31,100000091,3300)]);
  const sample=scenario('f70-invalid',3,10001,5500);
  rejected('unexpected operational flag',{...dto([sample]),media_execution_allowed:true});
  rejected('modified day total',dto([{...sample,
    daily:sample.daily.map((d,i)=>i===1?{...d,total_centavos:d.total_centavos+1}:d)}]));
  rejected('cross-scenario duplicate ref',dto([sample,{...sample}]));
  rejected('extra customer field',{...dto([sample]),customer_email:'private@example.test'});
  rejected('provider connected',dto([{...sample,provider_connected:true}]));
} finally {rmSync(dir,{recursive:true,force:true});}
console.log('PASS PORTAL-F70 '+checks+' HTML/CSV parity and fail-closed checks');
