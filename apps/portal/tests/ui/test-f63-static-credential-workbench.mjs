import assert from 'node:assert/strict';
import {spawnSync} from 'node:child_process';
import {readFileSync,writeFileSync,mkdtempSync,rmSync,symlinkSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

const renderer='apps/portal/tools/f63-render-credential-workbench.php';
const env={...process.env,APP_ENV:'testing',PORTAL_F62_CREDENTIAL_WORKBENCH_LAB:'1',PORTAL_F63_STATIC_HTML_LAB:'1'};
const run=(path,e=env)=>spawnSync('php',[renderer,path],{encoding:'utf8',env:e});
const fixture={
  schema_version:'perf.portal.credential-bound-workbench.synthetic.v1',
  status:'credential_scoped_demo_workbench_only',
  synthetic_only:true,read_only:true,credential_binding_confirmed:true,
  portal_identity_authenticated:false,real_human_approval_verified:false,
  provider_connected:false,authorization_eligible:false,ready_for_production:false,
  dispatch_enabled:false,billing_enabled:false,
  counts:{account_candidates:2,linked_campaigns:1,visible_reviews:1,review_pending:0,review_internal_no_execution:1,review_rejected:0},
  accounts:[
    {portal_channel:'google_ads',account_ref:'demo_google_a',connection_state:'unverified_demo'},
    {portal_channel:'meta_ads',account_ref:'demo_meta_a',connection_state:'unverified_demo'}
  ],
  campaigns:[{
    campaign_ref:'cam-demo-f89-a',campaign_title:'F89 demo campaign A',
    portal_channel:'meta_ads',campaign_observation_state:'draft_demo',
    account_ref:'demo_meta_a',account_state:'unverified_demo',portal_stage:null
  }],
  reviews:[{
    campaign_ref:'cam-demo-f89-a',request_ref:'req-demo-f97-a-approved',
    operation:'campaign.create',portal_channel:'meta_ads',
    review_observation_state:'approved_demo_no_execution',journaled:true,portal_stage:null
  }]
};
let n=0;
const check=(label,value)=>{assert.equal(value,true,'PORTAL F63 '+label);n++};
const tmp=mkdtempSync(join(tmpdir(),'portal-f63-'));
const path=join(tmp,'snapshot.json');
const write=(value)=>writeFileSync(path,JSON.stringify(value));
try {
  write(fixture);
  const htmlResult=run(path);
  check('valid fixture renders',htmlResult.status===0);
  const html=htmlResult.stdout;
  for(const [label,value] of [
    ['Portuguese markup',html.includes('<html lang="pt-BR">')],
    ['CSP default-src none',html.includes("default-src 'none'")],
    ['CSP connect-src none',html.includes("connect-src 'none'")],
    ['CSP form-action none',html.includes("form-action 'none'")],
    ['visible demo warning',html.includes('DEMONSTRAÇÃO NÃO HOMOLOGADA')],
    ['never verified Portal',html.includes('Sem login de cliente')],
    ['Google Ads label',html.includes('Google Ads')],
    ['Meta label',html.includes('Meta (Facebook e Instagram)')],
    ['draft only',html.includes('Rascunho DEMO')],
    ['no execution review',html.includes('Revisão interna DEMO — sem execução')],
    ['campaign label',html.includes('F89 demo campaign A')],
    ['campaign reference',html.includes('cam-demo-f89-a')],
    ['account reference',html.includes('demo_meta_a')],
    ['review reference',html.includes('req-demo-f97-a-approved')],
    ['summary count',html.includes('Contas candidatas DEMO')],
    ['no live data',html.includes('Nenhuma conta publicitária real está conectada')],
    ['no script',!/<script(?:\s|>)/i.test(html)],
    ['no external link or anchor',!/<a(?:\s|>)|<link(?:\s|>)|https?:\/\//i.test(html)],
    ['no forms and buttons',!/<form(?:\s|>)|<button(?:\s|>)/i.test(html)],
    ['no image, iframe, video',!/<(?:img|iframe|video)(?:\s|>)/i.test(html)],
    ['no currency amount',!(/R\$|US\$/).test(html)],
  ])check(label,value);

  for(const [name,bad] of [
    ['unknown schema',{...fixture,schema_version:'v2'}],
    ['falsified customer identity',{...fixture,portal_identity_authenticated:true}],
    ['billing enabled',{...fixture,billing_enabled:true}],
    ['dispatch enabled',{...fixture,dispatch_enabled:true}],
    ['false review count',{...fixture,counts:{...fixture.counts,visible_reviews:2}}],
    ['script injection',{...fixture,campaigns:[{...fixture.campaigns[0],campaign_title:'<script>alert(1)</script>'}]}],
    ['unknown account',{...fixture,campaigns:[{...fixture.campaigns[0],account_ref:'demo_missing'}]}],
    ['forged external approval',{...fixture,reviews:[{...fixture.reviews[0],review_observation_state:'approved'}]}],
    ['unverified journal',{...fixture,reviews:[{...fixture.reviews[0],journaled:false}]}],
    ['extra private key',{...fixture,access_token:'private-test-only'}],
  ]) {
    write(bad);
    const denied=run(path);
    check(name+' rejected',denied.status!==0 && denied.stdout==='');
  }
  write(fixture);
  for(const [label,badEnv] of [
    ['production env',{...env,APP_ENV:'production'}],
    ['F62 lab disabled',{...env,PORTAL_F62_CREDENTIAL_WORKBENCH_LAB:'0'}],
    ['F63 lab disabled',{...env,PORTAL_F63_STATIC_HTML_LAB:'0'}],
  ]) {
    const denied=run(path,badEnv);
    check(label,denied.status!==0 && denied.stdout==='');
  }
  const linked=join(tmp,'symlink.json');
  symlinkSync(path,linked);
  check('symlink denied',run(linked).status!==0);
  check('missing file denied',run(join(tmp,'absent.json')).status!==0);
  check('no input denied',spawnSync('php',[renderer],{env,encoding:'utf8'}).status!==0);
  if(process.argv[2]){
    const fromDb=run(process.argv[2]);
    check('actual F100 PostgreSQL snapshot accepted',fromDb.status===0);
    for(const [label,part] of [
      ['generated campaign F89','cam-demo-f89-a'],
      ['generated account Meta','demo_meta_a'],
      ['generated review F97','req-demo-f97-a-approved'],
      ['generated no-go','DEMONSTRAÇÃO NÃO HOMOLOGADA'],
      ['generated no action','Revisão interna DEMO — sem execução'],
    ])check(label,fromDb.stdout.includes(part));
    check('real database HTML has no script',!/<script(?:\s|>)/i.test(fromDb.stdout));
    check('real database HTML has no links',!/<a(?:\s|>)|https?:\/\//i.test(fromDb.stdout));
  }
}finally{
  rmSync(tmp,{recursive:true,force:true});
}
console.log('PASS PORTAL-F63 '+n+' static offline credential-workbench HTML assertions');
