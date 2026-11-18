'use strict';
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{execFileSync}=require('node:child_process');
if(process.env.GITHUB_ACTIONS!=='true'||process.env.ADMIN_PHASE7_CI!=='1')process.exit(1);
const vault=JSON.parse(fs.readFileSync(process.env.P7_BROWSER_VAULT,'utf8'));
const base='https://127.0.0.1:18947',out=process.env.P7_EVIDENCE;
const controlScript=path.join(process.env.GITHUB_WORKSPACE,'apps/portal/tests/Fixtures/phase7/control.sh');
function control(service,action){
 try{return execFileSync('bash',[controlScript,service,action],{encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();}
 catch(error){const match=String(error.stderr||'').match(/PHASE7_FIXTURE_DENIED stage=[a-z-]+ type=[A-Za-z\\]+/);if(match)console.error(match[0]);throw new Error('fixture_'+service+'_'+action.replaceAll('-','_'));}
}
function snapshot(service){return JSON.parse(control(service,'snapshot'));}
function otp(secret){const b=Buffer.alloc(8);b.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const d=crypto.createHmac('sha1',Buffer.from(secret,'hex')).update(b).digest(),o=d[d.length-1]&15;return String((d.readUInt32BE(o)&0x7fffffff)%1000000).padStart(6,'0');}
(async()=>{
 const browser=await chromium.launch({headless:true});const checks=[];
 const check=(value,name)=>{if(!value)throw new Error(name);checks.push(name);};
 try {
  // No ignoreHTTPSErrors: the ephemeral CA is explicitly trusted by this CI runner.
  const makeContext=()=>browser.newContext({httpCredentials:vault.httpCredentials});
  const context=await makeContext(),page=await context.newPage();
  const before=snapshot('receiver').receipts;
  await page.goto(base+'/portal-admin/performance-private');check(page.url().endsWith('/portal-admin/login'),'anonymous_redirect');
  const login=async(p,user)=>{
   await p.goto(base+'/portal-admin/login');await p.locator('[name=email]').fill(user.email);await p.locator('[name=password]').fill(user.password);
   await Promise.all([p.waitForURL('**/mfa'),p.locator('button[type=submit]').click()]);
   await p.goto(base+'/portal-admin/performance-private');check(p.url().endsWith('/portal-admin/login'),'password_without_mfa_denied');
   await p.goto(base+'/portal-admin/mfa');await p.locator('[name=code]').fill(otp(user.secret));
   await Promise.all([p.waitForURL('**/portal-admin'),p.locator('button[type=submit]').click()]);
  };
  await login(page,vault.users[0]);check(snapshot('receiver').receipts===before,'unauthenticated_no_receiver_receipt');
  const cookie=(await context.cookies()).find(c=>c.name==='imagine_performance_p6_session');
  check(cookie&&cookie.secure&&cookie.httpOnly&&cookie.sameSite==='Strict','secure_database_session_cookie');
  for(const width of [1440,390]) {
   await page.setViewportSize({width,height:900});const response=await page.goto(base+'/portal-admin/performance-private');check(response.status()===200,'company_selector_'+width);
   check(await page.getByRole('link',{name:'Abrir empresa '+vault.users[1].company}).count()===0,'other_company_hidden_'+width);
   await page.getByRole('link',{name:'Abrir empresa '+vault.users[0].company}).click();
   check(await page.getByRole('heading',{name:'Estado da consulta'}).isVisible(),'private_read_'+width);
   check(await page.getByText('Dados indisponíveis',{exact:true}).isVisible(),'official_metrics_unavailable_'+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'responsive_'+width);
   await page.screenshot({path:path.join(out,'exact-compose-'+width+'.png'),fullPage:true});
  }
  const firstAudit=snapshot('portal'),firstEngine=snapshot('receiver');
  check(firstAudit.sessions>0&&firstAudit.encrypted,'encrypted_sessions_persisted');
  check(firstAudit.completed.length>=2&&firstAudit.completed.every(id=>firstEngine.correlations.includes(id)&&firstEngine.results.includes(id)),'correlated_sql_audits');
  let receiptCount=firstEngine.receipts;
  let response=await page.goto(base+'/portal-admin/performance-private/'+vault.users[1].company);
  check(response.status()===403&&snapshot('receiver').receipts===receiptCount,'cross_company_denied_before_source');
  const otherContext=await makeContext(),other=await otherContext.newPage();await login(other,vault.users[1]);
  response=await other.goto(base+'/portal-admin/performance-private/'+vault.users[1].company);check(response.status()===200,'second_nominal_admin_own_company');
  const own=base+'/portal-admin/performance-private/'+vault.users[0].company;
  for(const service of ['portal','receiver']) {
   const scenarios=[['revoke-grant','restore-grant','revoked_grant'],['extra-privilege','remove-privilege','excess_sql_privilege']];
   if(service==='portal')scenarios.push(['extra-column-privilege','remove-column-privilege','audit_column_read_privilege']);
   for(const [deny,restore,name] of scenarios) {
    receiptCount=snapshot('receiver').receipts;control(service,deny);
    response=await page.goto(own);check(response.status()===403&&snapshot('receiver').receipts===receiptCount,service+'_'+name+'_denied');
    control(service,restore);response=await page.goto(own);check(response.status()===200,service+'_'+name+'_recovery');
   }
  }
  receiptCount=snapshot('receiver').receipts;control('portal','expire-mfa');response=await page.goto(own);
  check(response.status()===403&&snapshot('receiver').receipts===receiptCount,'stale_mfa_denied_before_source');
  control('portal','revoke-session');await page.goto(own);
  check(page.url().endsWith('/portal-admin/login')&&snapshot('receiver').receipts===receiptCount,'revoked_session_denied_before_source');
  const p=snapshot('portal'),e=snapshot('receiver');
  check(p.completed.every(id=>e.correlations.includes(id)&&e.results.includes(id)),'final_correlated_audits');
  fs.writeFileSync(path.join(out,'checks.json'),JSON.stringify({schema:'performance.phase7.evidence.v1',checks,passed:checks.length,
    source:'synthetic',app_environment:'staging',session_driver:'database',database_tls:'verify-full',
    deployment_authorized:false,external_operations_enabled:false,billable:false},null,2));
  console.log('PHASE7_EXACT_COMPOSE_BROWSER_PASS: '+checks.length+' checks');
 } finally {await browser.close();}
})().catch(error=>{console.error('PHASE7_BROWSER_FAILED: '+(typeof error.message==='string'&&/^[a-z_0-9]+$/.test(error.message)?error.message:'interaction'));process.exit(1);});

