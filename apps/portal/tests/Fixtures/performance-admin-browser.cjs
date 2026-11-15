const {chromium}=require('playwright');
const AxeBuilder=require('@axe-core/playwright').default;
const fs=require('fs');const crypto=require('crypto');const path=require('path');
const vault=JSON.parse(fs.readFileSync(process.env.PERFORMANCE_ADMIN_BROWSER_VAULT,'utf8'));
const base=process.env.PERFORMANCE_ADMIN_BROWSER_URL||'http://127.0.0.1:18942';
const out=process.env.PERFORMANCE_ADMIN_EVIDENCE;let phase='start',checks=0;
function check(x){if(!x)throw Error('Assertion');checks++;}
function otp(){const b=Buffer.alloc(8);b.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const d=crypto.createHmac('sha1',Buffer.from(vault.secret,'hex')).update(b).digest();const o=d[d.length-1]&15;return String((d.readUInt32BE(o)&0x7fffffff)%1000000).padStart(6,'0');}
(async()=>{const b=await chromium.launch({headless:true});try{
 const page=await b.newPage({viewport:{width:1440,height:1000},...(process.env.CI_ACCESS_PASSWORD?{httpCredentials:{username:'ci-operator',password:process.env.CI_ACCESS_PASSWORD}}:{})});const errors=[];page.on('pageerror',()=>errors.push(1));
 await page.goto(base+'/portal-admin/login');await page.locator('[name=email]').fill(vault.email);await page.locator('[name=password]').fill(vault.password);await Promise.all([page.waitForURL('**/mfa'),page.locator('button[type=submit]').click()]);await page.locator('[name=code]').fill(otp());await Promise.all([page.waitForURL('**/portal-admin'),page.locator('button[type=submit]').click()]);
 const modules=['dashboard','clients','channels','campaigns','agents','approvals','finance','reports','incidents','settings'];
 for(const m of modules){phase=m;const r=await page.goto(base+'/portal-admin/performance/'+m);check(r.status()===200);check(await page.getByRole('heading',{level:1}).count()===1);for(const w of [320,390,768,1440]){await page.setViewportSize({width:w,height:900});check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));check(await page.locator('.metric strong').evaluateAll(nodes=>nodes.every(n=>n.scrollWidth<=n.clientWidth+1)));}const accessibility=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21a','wcag21aa']).analyze();
 const failures=accessibility.violations.filter(v=>['serious','critical'].includes(v.impact));
 fs.writeFileSync(path.join(out,m+'-accessibility.json'),JSON.stringify({module:m,violations:accessibility.violations.map(v=>({id:v.id,impact:v.impact,help:v.help,targets:v.nodes.map(n=>n.target)}))},null,2));check(failures.length===0);
 await page.screenshot({path:path.join(out,m+'-desktop.png'),fullPage:true});if(m==='dashboard'){await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'dashboard-mobile.png'),fullPage:true});}}
 phase='pause';await page.goto(base+'/portal-admin/performance/agents?resource=analysis');await page.locator('.actionform [name=confirmed]').check();await page.locator('.actionform button').click();check(await page.getByRole('status').count()===1);await page.goto(base+'/portal-admin/performance/agents?resource=analysis');check(await page.getByText('Retomar agente interno',{exact:true}).count()>=1);
 phase='filters';await page.goto(base+'/portal-admin/performance/campaigns');await page.locator('#q').fill('nao-existe');await page.getByRole('button',{name:'Filtrar',exact:true}).click();check(await page.getByText('Nenhum registro corresponde aos filtros.').isVisible());
 phase='csrf';const rejected=await page.request.post(base+'/portal-admin/performance/command/lab-alfa',{form:{action:'agent.pause'}});check(rejected.status()===419);
 check(errors.length===0);console.log('ADMIN BROWSER PASS: '+checks+' assertions; ten modules, desktop/mobile, real password/MFA, persisted pause, filters, CSRF and WCAG serious/critical checks.');
 }finally{await b.close();}})().catch(()=>{console.error('Administrative browser check failed at '+phase+'; no credentials or HTML logged.');process.exit(1);});
