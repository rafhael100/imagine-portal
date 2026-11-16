const {chromium}=require('playwright'),AxeBuilder=require('@axe-core/playwright').default;
const fs=require('fs'),crypto=require('crypto'),path=require('path');
const root=process.env.ADMIN_PHASE4_TLS,vault=JSON.parse(fs.readFileSync(path.join(root,'browser-vault.json'),'utf8'));
const base='http://127.0.0.1:18944',out=process.env.ADMIN_PHASE4_EVIDENCE;
function otp(){const b=Buffer.alloc(8);b.writeBigUInt64BE(BigInt(Math.floor(Date.now()/30000)));const d=crypto.createHmac('sha1',Buffer.from(vault.secret,'hex')).update(b).digest(),o=d[d.length-1]&15;return String((d.readUInt32BE(o)&0x7fffffff)%1000000).padStart(6,'0');}
(async()=>{const browser=await chromium.launch({headless:true});try{
 const page=await browser.newPage();let checks=0;const check=x=>{if(!x)throw Error('Assertion');checks++;};
 await page.goto(base+'/portal-admin/login');await page.locator('[name=email]').fill(vault.email);await page.locator('[name=password]').fill(vault.password);
 await Promise.all([page.waitForURL('**/mfa'),page.locator('button[type=submit]').click()]);await page.locator('[name=code]').fill(otp());
 await Promise.all([page.waitForURL('**/portal-admin'),page.locator('button[type=submit]').click()]);
 for(const width of [1440,390]){
  await page.setViewportSize({width,height:900});let r=await page.goto(base+'/portal-admin/performance-private');check(r.status()===200);
  await page.getByRole('link',{name:'Abrir empresa '+vault.company}).click();check(await page.getByRole('heading',{name:'Estado da consulta'}).isVisible());
  check(await page.getByText('Dados indisponíveis',{exact:true}).isVisible());check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  const accessibility=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa']).analyze();check(accessibility.violations.filter(v=>['serious','critical'].includes(v.impact)).length===0);
  await page.screenshot({path:path.join(out,'private-read-'+width+'.png'),fullPage:true});
  fs.writeFileSync(path.join(out,'accessibility-'+width+'.json'),JSON.stringify({violations:accessibility.violations.map(v=>({id:v.id,impact:v.impact}))}));
 }
 console.log('PHASE4 BROWSER PASS: '+checks+' checks; nominal password/MFA, authorized company selection, real S2S FPM/Nginx result, desktop/mobile and serious/critical accessibility checks.');
}finally{await browser.close();}})().catch(()=>{console.error('Phase4 browser failed; credentials and HTML omitted');process.exit(1);});
