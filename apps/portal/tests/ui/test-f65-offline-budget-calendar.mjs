import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {mkdtempSync,writeFileSync,symlinkSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const renderer='apps/portal/tools/f65-render-budget-calendar.php';
const env={...process.env,APP_ENV:'testing',
  PORTAL_F64_BUDGET_PREVIEW_LAB:'1',PORTAL_F65_BUDGET_HTML_LAB:'1'};
const run=(file,environment=env)=>spawnSync('php',[renderer,file],{
  env:environment,encoding:'utf8'
});
const scenario={
  schema_version:'perf.planning.offline-budget.synthetic.v1',
  scenario_ref:'scenario-demo-f106-a',currency:'BRL',
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
  provider_connected:false,media_execution_allowed:false,
  billing_allowed:false,ready_for_production:false,
  scenarios:[scenario]
};
let n=0;
const check=(name,yes)=>{assert.equal(yes,true,'PORTAL-F65 '+name);n++};
const dir=mkdtempSync(join(tmpdir(),'portal-f65-'));
const path=join(dir,'budget.json');
const save=(value)=>writeFileSync(path,JSON.stringify(value));
try{
  save(dto);
  const result=run(path);
  check('valid scenario renders',result.status===0);
  const html=result.stdout;
  for(const [label,value] of [
    ['Portuguese HTML',html.includes('<html lang="pt-BR">')],
    ['strict CSP',html.includes("default-src 'none'")],
    ['no network CSP',html.includes("connect-src 'none'")],
    ['forbid forms CSP',html.includes("form-action 'none'")],
    ['offline banner',html.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA')],
    ['never approved',html.includes('não são gastos realizados')],
    ['no connected accounts',html.includes('Nenhum orçamento foi enviado')],
    ['BRL 100.01',html.includes('R$ 100,01')],
    ['Google 55.00',html.includes('R$ 55,00')],
    ['Meta 45.01',html.includes('R$ 45,01')],
    ['percentage Google 55.00',html.includes('Google Ads · 55,00%')],
    ['percentage Meta 45.00',html.includes('Meta Ads · 45,00%')],
    ['year leap date',html.includes('2028-02-29')],
    ['Google day 18.33',html.includes('R$ 18,33')],
    ['Google day 18.34',html.includes('R$ 18,34')],
    ['Meta day 15.01',html.includes('R$ 15,01')],
    ['calendar headings',html.includes('Total do dia')],
    ['scenario ID DEMO',html.includes('scenario-demo-f106-a')],
    ['no scripts',!/<script(?:\s|>)/i.test(html)],
    ['no external links',!/<a(?:\s|>)|<link(?:\s|>)|https?:\/\//i.test(html)],
    ['no forms/buttons',!/<(?:form|button|input|select)(?:\s|>)/i.test(html)],
    ['no images/iframes',!/<(?:img|iframe|video)(?:\s|>)/i.test(html)],
  ])check(label,value);

  const bads=[
    ['production flag',{...dto,ready_for_production:true}],
    ['advertiser approval',{...dto,advertiser_approval_verified:true}],
    ['media execution',{...dto,media_execution_allowed:true}],
    ['billing flag',{...dto,billing_allowed:true}],
    ['fake portal identity',{...dto,portal_identity_authenticated:true}],
    ['extra token',{...dto,oauth_token:'example'}],
    ['source row currency',{...dto,scenarios:[{...scenario,currency:'USD'}]}],
    ['source row float',{...dto,scenarios:[{...scenario,total_centavos:100.01}]}],
    ['scenario real account',{...dto,scenarios:[{...scenario,scenario_ref:'live_1234'}]}],
    ['daily corrupted',{...dto,scenarios:[{...scenario,daily:scenario.daily.map((d,i)=>i===0?{...d,google_ads_centavos:1834}:d)}]}],
    ['false split',{...dto,scenarios:[{...scenario,channel_split_bp:{google_ads:6000,meta_ads:4000}}]}],
    ['wrong date',{...dto,scenarios:[{...scenario,start_date:'2028-02-30'}]}],
    ['HTML attack scenario',{...dto,scenarios:[{...scenario,scenario_ref:'scenario-demo-<script>alert(1)</script>'}]}]
  ];
  for(const [label,body] of bads) {
    save(body);
    const bad=run(path);
    check('reject '+label,bad.status!==0 && bad.stdout==='');
  }
  save(dto);
  for(const [label,environment] of [
    ['production disabled',{...env,APP_ENV:'production'}],
    ['F64 disabled',{...env,PORTAL_F64_BUDGET_PREVIEW_LAB:'0'}],
    ['F65 disabled',{...env,PORTAL_F65_BUDGET_HTML_LAB:'0'}],
  ]) {
    const denied=run(path,environment);
    check(label,denied.status!==0 && denied.stdout==='');
  }
  const linked=join(dir,'budget-link.json');
  symlinkSync(path,linked);
  check('symlink rejected',run(linked).status!==0);
  check('missing file rejected',run(join(dir,'missing.json')).status!==0);
  check('empty CLI args rejected',spawnSync('php',[renderer],{env,encoding:'utf8'}).status!==0);

  if(process.argv[2]){
    const real=run(process.argv[2]);
    check('accept PostgreSQL-generated F107 JSON',real.status===0);
    for(const [label,term] of [
      ['actual scenario','scenario-demo-f106-a'],
      ['real budget total','R$ 100,01'],
      ['Google share','R$ 55,00'],
      ['Meta share','R$ 45,01'],
      ['leap day','2028-02-29'],
      ['no go','DEMONSTRAÇÃO NÃO HOMOLOGADA']
    ])check(label,real.stdout.includes(term));
    check('real HTML no external links',!/<a(?:\s|>)|https?:\/\//i.test(real.stdout));
    check('real HTML no script',!/<script(?:\s|>)/i.test(real.stdout));
  }
}finally{
  rmSync(dir,{recursive:true,force:true});
}
console.log('PASS PORTAL-F65 '+n+' offline exact-centavo HTML assertions');
