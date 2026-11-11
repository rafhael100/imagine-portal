import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const source=await readFile(fileURLToPath(new URL('../account-ui.js',import.meta.url)),'utf8');
const context={
 escapeHtml:value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
};
runInNewContext(source+'\nthis.projectTestApi={filteredAccountProjects,accountProjectCards,accountProjectPaymentLabel};',context);
const {filteredAccountProjects:filter,accountProjectCards:cards}=context.projectTestApi;

function projects(n=21){
 return Array.from({length:n},(_,i)=>({
  id:'project-'+String(i+1).padStart(2,'0'),
  title:'Site '+String(i+1),
  payment_status:i%2===0?'RECEIVED':'PENDING',
  data:{company_name:i===12?'Café São João':'Empresa '+(i+1),domain:i===12?'cafesao.example':'ex'+(i+1)+'.test'},
 }));
}

test('F188: account archive paginates only eight authorized projects per page',()=>{
 const data=projects();
 const first=filter(data);
 assert.equal(first.count,21);assert.equal(first.page,1);assert.equal(first.pages,3);
 assert.equal(first.items.length,8);assert.equal(first.items[0].id,'project-01');
 const second=filter(data,'','all',2);
 assert.equal(second.items[0].id,'project-09');assert.equal(second.items.length,8);
 const last=filter(data,'','all',3);
 assert.equal(last.items.length,5);assert.equal(last.items.at(-1).id,'project-21');
 assert.equal(filter(data,'','all',999).page,3);
 assert.equal(filter(data,'','all',-5).page,1);
 assert.equal(filter([],null,'all',1).count,0);
});

test('F188: search is accent-insensitive and scope includes title/company/domain, never another account',()=>{
 const data=projects();
 assert.equal(filter(data,'CAFE SAO').items[0].id,'project-13');
 assert.equal(filter(data,'cafesao.example').count,1);
 assert.equal(filter(data,'site 12').count,1);
 assert.equal(filter(data,'project-08').count,1);
 assert.equal(filter(data,'zzzz').count,0);
 assert.equal(filter([{...data[0]}],'site 20').count,0);
});

test('F188: payment states filter without changing backend authorization',()=>{
 const data=projects();
 assert.equal(filter(data,'','paid').count,11);
 assert.equal(filter(data,'','other').count,10);
 assert.equal(filter(data,'site 3','paid').count,1);
 assert.equal(filter(data,'site 3','other').count,0);
 assert.equal(filter(data,'','any-status').count,0);
});

test('F188: card html escapes user-supplied strings while preserving safe project action',()=>{
 const html=cards([{id:'safe-123',title:'<script>alert(1)</script>',payment_status:'PENDING',data:{
  company_name:'<img src=x onerror=alert(1)>',domain:'x"; onclick="boom',notes:'<b>unsafe</b>',
 }}]);
 assert.ok(html.includes('&lt;script&gt;'));
 assert.ok(html.includes('&lt;img src=x onerror=alert(1)&gt;'));
 assert.ok(html.includes('x&quot;; onclick=&quot;boom'));
 assert.ok(html.includes('&lt;b&gt;unsafe&lt;/b&gt;'));
 assert.ok(html.includes('data-account-project="safe-123"'));
 assert.ok(!html.includes('<script>'));
});

test('F192: project cards show translated payment labels and fail closed on unknown states',()=>{
 const label=context.projectTestApi.accountProjectPaymentLabel;
 for(const [state,expected] of Object.entries({
  RECEIVED:'Pago',CONFIRMED:'Pagamento confirmado',PENDING:'Pagamento pendente',
  OVERDUE:'Pagamento em atraso',REFUNDED:'Pagamento estornado',NOT_CREATED:'Pagamento não iniciado',
  UNRECOGNIZED:'Pagamento em análise',
 })) assert.equal(label(state),expected);
 const html=cards([
  {id:'a-safe-id',title:'Site',payment_status:'REFUNDED',data:{}},
  {id:'b-safe-id',title:'Novo site',payment_status:'UNRECOGNIZED',data:{}},
 ]);
 assert.ok(html.includes('Pagamento estornado'));
 assert.ok(html.includes('Pagamento em análise'));
 assert.equal(html.includes('UNRECOGNIZED'),false);
});
