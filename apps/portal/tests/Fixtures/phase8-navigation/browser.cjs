'use strict';
const {chromium}=require('playwright');
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),{execFileSync}=require('node:child_process');
if(process.env.GITHUB_ACTIONS!=='true'||process.env.ADMIN_PHASE7_CI!=='1'||process.env.ADMIN_PHASE8_NAVIGATION_CI!=='1')process.exit(1);
const vault=JSON.parse(fs.readFileSync(process.env.P8_NAV_BROWSER_VAULT,'utf8'));
const base='https://127.0.0.1:18947',out=process.env.P7_EVIDENCE;
const controlScript=path.join(process.env.GITHUB_WORKSPACE,'apps/portal/tests/Fixtures/phase8-navigation/control.sh');
function control(service,action){
 try{return execFileSync('bash',[controlScript,service,action],{encoding:'utf8',stdio:['ignore','pipe','pipe']}).trim();}
 catch(error){const match=String(error.stderr||'').match(/PHASE8_NAVIGATION_FIXTURE_DENIED stage=[a-z-]+ type=[A-Za-z\\]+/);if(match)console.error(match[0]);throw new Error('fixture_'+service+'_'+action.replaceAll('-','_'));}
}
function snapshot(service){return JSON.parse(control(service,'snapshot'));}
function count(){return snapshot('receiver').receipts.length;}
function otp(secret){const buffer=Buffer.alloc(8);buffer.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const digest=crypto.createHmac('sha1',Buffer.from(secret,'hex')).update(buffer).digest(),offset=digest[digest.length-1]&15;return String((digest.readUInt32BE(offset)&0x7fffffff)%1000000).padStart(6,'0');}
(async()=>{
 const browser=await chromium.launch({headless:true}),checks=[],correlations=new Set();
 const check=(value,name)=>{if(!value)throw new Error(name);checks.push(name);};
 try{
  const context=await browser.newContext({httpCredentials:vault.httpCredentials}),page=await context.newPage();
  const own=base+'/portal-admin/performance-private/'+vault.users[0].company;
  const login=async(p,user)=>{
   await p.goto(base+'/portal-admin/login');await p.locator('[name=email]').fill(user.email);await p.locator('[name=password]').fill(user.password);
   await Promise.all([p.waitForURL('**/mfa'),p.locator('button[type=submit]').click()]);
   await p.locator('[name=code]').fill(otp(user.secret));await Promise.all([p.waitForURL('**/portal-admin'),p.locator('button[type=submit]').click()]);
  };
  const verifyReceipt=async(p,company,operation,query,name)=>{
   const correlation=(await p.locator('dt').filter({hasText:/^Correlação$/}).locator('xpath=following-sibling::dd[1]').textContent()).trim();
   const receipt=snapshot('receiver').receipts.find(row=>row.correlation_id===correlation);
   check(receipt&&receipt.company_id===company&&receipt.operation===operation,'scoped_receipt_'+name);
   check(receipt.query_matches.includes(query),'signed_requested_filters_'+name);
   check(receipt.status==='unavailable'&&receipt.metrics_null===true&&receipt.billable===false&&receipt.external_operations_enabled===false,'truthful_source_'+name);
   check(!correlations.has(correlation),'fresh_request_correlation_'+name);correlations.add(correlation);
  };
  await login(page,vault.users[0]);
  for(const width of [1440,390]){
   await page.setViewportSize({width,height:900});await page.goto(base+'/portal-admin/performance-private');
   check(await page.getByRole('link',{name:'Abrir empresa '+vault.users[0].company}).isVisible(),'own_company_'+width);
   check(await page.getByRole('link',{name:'Abrir empresa '+vault.users[1].company}).count()===0,'other_company_hidden_'+width);
   await page.getByRole('link',{name:'Abrir empresa '+vault.users[0].company}).click();
   check(await page.getByRole('navigation',{name:'Consultas Performance'}).getByRole('link').count()===3,'three_independent_read_tabs_'+width);
   check(await page.getByRole('link',{name:/Abrir os dez módulos/}).count()===0,'lab_membership_not_inherited_'+width);
   await verifyReceipt(page,vault.users[0].company,'dashboard.read','first','dashboard_'+width);
   await page.getByRole('navigation',{name:'Consultas Performance'}).getByRole('link',{name:'Clientes',exact:true}).click();
   await page.getByLabel('Período solicitado',{exact:true}).fill('2026-11');await page.getByLabel('Página solicitada',{exact:true}).fill('2');
   const [response]=await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Consultar',exact:true}).click()]);
   check(response.status()===200&&response.headers()['cache-control'].includes('no-store'),'filtered_private_response_'+width);
   await verifyReceipt(page,vault.users[0].company,'clients.read','november','clients_'+width);
   await page.getByRole('navigation',{name:'Consultas Performance'}).getByRole('link',{name:'Relatórios',exact:true}).click();
   check(await page.getByLabel('Período solicitado',{exact:true}).inputValue()==='2026-11'&&await page.getByLabel('Página solicitada',{exact:true}).inputValue()==='2','filters_preserved_between_tabs_'+width);
   await verifyReceipt(page,vault.users[0].company,'reports.read','november','reports_'+width);
   await page.getByLabel('Período solicitado',{exact:true}).fill('2026-12');await page.getByLabel('Página solicitada',{exact:true}).fill('3');
   await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded'}),page.getByRole('button',{name:'Consultar',exact:true}).click()]);
   await verifyReceipt(page,vault.users[0].company,'reports.read','december','changed_filters_'+width);
   check(await page.getByText('Não informado pela fonte',{exact:true}).isVisible(),'requested_period_not_reported_as_source_period_'+width);
   check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'responsive_navigation_'+width);
   await page.screenshot({path:path.join(out,'native-navigation-'+width+'.png'),fullPage:true});
   await page.getByRole('link',{name:'Limpar filtros',exact:true}).click();
   check(await page.getByLabel('Período solicitado',{exact:true}).inputValue()===''&&await page.getByLabel('Página solicitada',{exact:true}).inputValue()==='1','reset_filters_'+width);
   await verifyReceipt(page,vault.users[0].company,'reports.read','first','reset_'+width);
  }
  let before=count();
  for(const query of ['period=2026-13','period=2026-1','period%5B%5D=2026-11','page=0','page=10001','page=1.5','page%5B%5D=2','operation=clients.read','company='+vault.users[1].company,'resource=unexpected']){
   const response=await page.goto(own+'/reports?'+query);
   check(response.status()===422&&await page.getByRole('alert').isVisible(),'invalid_filter_'+checks.length);
  }
  check(count()===before,'invalid_filters_never_reach_receiver');
  let response=await page.goto(base+'/portal-admin/performance-private/'+vault.users[1].company+'/reports');
  check(response.status()===403&&count()===before,'cross_company_direct_read_denied');
  control('portal','revoke-clients');response=await page.goto(own+'/clients');
  check(response.status()===403&&count()===before,'revoked_client_grant_denied_before_source');
  await page.goto(own+'/reports');check(await page.getByRole('navigation',{name:'Consultas Performance'}).getByRole('link',{name:'Clientes',exact:true}).count()===0,'revoked_client_tab_hidden');
  control('portal','restore-clients');response=await page.goto(own+'/clients');check(response.status()===200,'restored_client_grant_current');

  const secondContext=await browser.newContext({httpCredentials:vault.httpCredentials}),second=await secondContext.newPage();await login(second,vault.users[1]);
  await second.goto(base+'/portal-admin/performance-private');
  check(await second.getByRole('link',{name:'Abrir empresa '+vault.users[1].company}).isVisible(),'reports_only_company_selectable');
  await second.getByRole('link',{name:'Abrir empresa '+vault.users[1].company}).click();
  check(await second.getByRole('heading',{name:'Consulta de relatórios.',exact:true}).isVisible()&&await second.getByRole('navigation',{name:'Consultas Performance'}).getByRole('link').count()===1,'reports_only_entry_and_navigation');
  await verifyReceipt(second,vault.users[1].company,'reports.read','first','reports_only');
  const secondOwn=base+'/portal-admin/performance-private/'+vault.users[1].company;before=count();
  for(const module of ['dashboard','clients']){response=await second.goto(secondOwn+'/'+module);check(response.status()===403,'missing_independent_grant_'+module);}
  check(count()===before,'missing_read_grants_never_reach_receiver');
  control('portal','clients-only');await second.goto(secondOwn);
  check(await second.getByRole('heading',{name:'Consulta de clientes.',exact:true}).isVisible()&&await second.getByRole('navigation',{name:'Consultas Performance'}).getByRole('link').count()===1,'clients_only_entry_and_navigation');
  await verifyReceipt(second,vault.users[1].company,'clients.read','first','clients_only');
  before=count();response=await second.goto(secondOwn+'/reports');check(response.status()===403&&count()===before,'reports_revocation_observed_without_session_reset');

  control('receiver','revoke-reports');before=count();response=await page.goto(own+'/reports');
  check(response.status()===403&&count()===before,'receiver_specific_report_delegation_required');
  response=await page.goto(own+'/clients');check(response.status()===200,'receiver_client_delegation_independent');
  control('receiver','restore-reports');response=await page.goto(own+'/reports');check(response.status()===200,'receiver_report_delegation_restored');
  await verifyReceipt(page,vault.users[0].company,'reports.read','first','restored_reports');

  const portal=snapshot('portal'),engine=snapshot('receiver');
  check(portal.completed.length>0&&portal.completed.every(row=>engine.receipts.some(receipt=>receipt.company_id===row.company_id&&receipt.operation===row.operation&&receipt.correlation_id===row.correlation_id)),'all_completed_reads_have_scoped_receiver_receipts');
  check(engine.receipts.every(row=>row.status==='unavailable'&&row.metrics_null&&row.billable===false&&row.external_operations_enabled===false),'no_metrics_or_external_operations_fabricated');
  fs.writeFileSync(path.join(out,'navigation-checks.json'),JSON.stringify({schema:'performance.phase8.navigation.evidence.v1',checks,passed:checks.length,
   source:'synthetic',operations:['dashboard.read','clients.read','reports.read'],requested_filters:['period','page'],
   native_users:'independent_password_mfa',runtime_overlay:false,deployment_authorized:false,external_operations_enabled:false,billable:false},null,2));
  console.log('PHASE8_NATIVE_NAVIGATION_BROWSER_PASS: '+checks.length+' checks');
 }finally{await browser.close();}
})().catch(error=>{console.error('PHASE8_NAVIGATION_BROWSER_FAILED: '+(typeof error.message==='string'&&/^[a-z_0-9]+$/.test(error.message)?error.message:'interaction'));process.exit(1);});
