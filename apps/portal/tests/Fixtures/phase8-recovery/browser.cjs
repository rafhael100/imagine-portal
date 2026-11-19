'use strict';
const {chromium}=require('playwright');
const {safeSessionDiagnostic}=require('./safe-session-diagnostic.cjs');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{execFileSync}=require('node:child_process');
if(process.env.GITHUB_ACTIONS!=='true'||process.env.ADMIN_PHASE8_RECOVERY_CI!=='1')process.exit(1);
const vault=JSON.parse(fs.readFileSync(process.env.P7_BROWSER_VAULT,'utf8'));
const live='https://127.0.0.1:18947',restored='https://127.0.0.1:18948';
const stepScript=path.join(process.env.GITHUB_WORKSPACE,'apps/portal/tests/Fixtures/phase8-recovery/step.sh');
function step(action){
 try {
  const output=execFileSync('bash',[stepScript,action],{encoding:'utf8',stdio:['ignore','pipe','pipe'],timeout:300000});
  for(const line of output.split('\n'))if(/^PHASE(?:7|8|10)_[A-Z_]+(?:[ :]|$)/.test(line))console.log(line);
 } catch(error) {
  const diagnostics=String(error.stderr||'');
  for(const pattern of [/PHASE8_STATE_DENIED stage=[a-z-]+ type=[A-Za-z\\]+/,/PHASE8_VERIFICATION_DENIED action=[a-z]+ check=[a-z_]+/,/PHASE8_RECEIVER_PROBE_DENIED/,/PHASE10_STORAGE_DENIED stage=[a-z-]+ check=[a-z_]+/,/PHASE10_STORAGE_ORCHESTRATION_DENIED stage=[a-z-]+ check=[a-z_]+/]){
   const safe=diagnostics.match(pattern);if(safe)console.error(safe[0]);
  }
  for(const line of diagnostics.split('\n'))if(/^PHASE8_PROFILE_DIFFERENCE kind=(portal|receiver) category=[a-z_]+ fields=[a-z_,]+ expected_rows=[0-9]+ actual_rows=[0-9]+$/.test(line))console.error(line);
  throw new Error('recovery_step_'+action);
 }
}
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
function otp(secret){const b=Buffer.alloc(8);b.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const d=crypto.createHmac('sha1',Buffer.from(secret,'hex')).update(b).digest(),o=d[d.length-1]&15;return String((d.readUInt32BE(o)&0x7fffffff)%1000000).padStart(6,'0');}
(async()=>{
 const browser=await chromium.launch({headless:true}),checks=[];
 const check=(value,name)=>{if(!value)throw new Error(name);checks.push(name);};
 const visit=async(page,url)=>{
  for(let attempt=0;attempt<30;attempt++){
   try {const response=await page.goto(url);if(response&&response.status()<500)return response;}catch{}
   await delay(500);
  }
  throw new Error('private_package_not_ready');
 };
 try {
  // Phase7's TOTP counters remain authoritative. Wait at most one new timestep
  // instead of clearing/replacing counters, the session store or cookies.
  await delay(30000-Date.now()%30000+250);
  const contexts=[],pages=[];
  for(const user of vault.users.slice(0,2)){
   const context=await browser.newContext({httpCredentials:vault.httpCredentials});
   const page=await context.newPage();contexts.push(context);pages.push(page);
   await visit(page,live+'/portal-admin/login');
   await page.locator('[name=email]').fill(user.email);await page.locator('[name=password]').fill(user.password);
   await Promise.all([page.waitForURL('**/mfa'),page.locator('button[type=submit]').click()]);
   await page.locator('[name=code]').fill(otp(user.secret));
   await Promise.all([page.waitForURL('**/portal-admin'),page.locator('button[type=submit]').click()]);
   const response=await visit(page,live+'/portal-admin/performance-private/'+user.company);
   check(response.status()===200&&await page.getByRole('heading',{name:'Estado da consulta'}).isVisible(),'nominal_fresh_login_'+contexts.length);
  }
  step('restart');
  for(let index=0;index<2;index++){
   const response=await visit(pages[index],live+'/portal-admin/performance-private/'+vault.users[index].company);
   check(response.status()===200&&!pages[index].url().endsWith('/login'),'same_browser_session_after_restart_'+index);
  }
  const alphaSession=await contexts[0].storageState();
  step('snapshot');
  let response=await visit(pages[1],live+'/portal-admin/performance-private/'+vault.users[1].company);
  check(response.status()===200,'post_snapshot_receipt_and_nonce_created');
  step('revoke');
  await visit(pages[0],live+'/portal-admin/performance-private/'+vault.users[0].company);
  check(pages[0].url().endsWith('/portal-admin/login'),'current_source_session_revocation_effective');
  step('reconcile');
  const restoredAlpha=await browser.newContext({httpCredentials:vault.httpCredentials,storageState:alphaSession});
  const alpha=await restoredAlpha.newPage();
  await visit(alpha,restored+'/portal-admin/performance-private/'+vault.users[0].company);
  check(alpha.url().endsWith('/portal-admin/login'),'pre_backup_revoked_cookie_denied_after_restore');
  response=await visit(pages[1],restored+'/portal-admin/performance-private/'+vault.users[1].company);
  const restoredBetaHeading = await pages[1].getByRole('heading',{name:'Estado da consulta'}).isVisible();
  if (response.status() !== 200 || !restoredBetaHeading) {
    // Preserve strict failure. Capture only allowlisted status, route class and
    // heading visibility; NEVER print URLs, cookies, response bodies or secrets.
    let routePath;
    try {routePath = new URL(pages[1].url()).pathname;} catch {routePath = null;}
    console.error(safeSessionDiagnostic('restored_beta', response.status(), routePath, restoredBetaHeading));
  }
  check(response.status()===200&&restoredBetaHeading,'pre_backup_active_database_session_survives_restore');
  check(await pages[1].getByText('Dados indisponíveis',{exact:true}).isVisible(),'restored_official_metrics_still_unavailable');
  const cookie=(await contexts[1].cookies()).find(item=>item.name==='imagine_performance_p6_session');
  check(cookie&&cookie.secure&&cookie.httpOnly&&cookie.sameSite==='Strict','restored_secure_cookie_attributes');
  response=await visit(pages[1],restored+'/portal-admin/performance-private/'+vault.users[0].company);
  check(response.status()===403,'restored_cross_company_read_denied');
  step('finish');
  fs.writeFileSync(path.join(process.env.P8_EVIDENCE,'browser-checks.json'),JSON.stringify({schema:'performance.phase8.recovery.browser.v1',checks,passed:checks.length,
   source:'synthetic',session_driver:'database',database_tls:'verify-full',deployment_authorized:false,external_operations_enabled:false,billable:false},null,2));
  console.log('PHASE8_RECOVERY_BROWSER_PASS: '+checks.length+' checks');
 }finally{await browser.close();}
})().catch(error=>{console.error('PHASE8_RECOVERY_BROWSER_FAILED: '+(typeof error.message==='string'&&/^[a-z_0-9]+$/.test(error.message)?error.message:'interaction'));process.exit(1);});
