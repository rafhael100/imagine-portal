import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const source=await readFile(fileURLToPath(new URL('../account-ui.js',import.meta.url)),'utf8');
function harness(){
 const calls=[],timers=[],opened=[];
 const result={isConnected:true,innerHTML:'',textContent:'',onclick:null,contains:()=>true};
 const search={value:'',oninput:null},payment={value:'all',onchange:null};
 const nodes={'#account-project-results':result,'#account-project-search':search,'#account-project-payment':payment};
 const context={
  document:{querySelector:selector=>nodes[selector]??null},
  api:url=>new Promise((resolve,reject)=>calls.push({url,resolve,reject})),
  setTimeout:fn=>(timers.push(fn),timers.length),
  clearTimeout:()=>{},
  openOrder:(id,section)=>opened.push({id,section}),
  escapeHtml:value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
 };
 runInNewContext(source+'\nthis.__bind=bindAccountProjectArchive;',context);
 context.__bind([],{remote:true,allCount:21});
 return {calls,timers,opened,result,search,payment};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const item=n=>({id:'project-'+String(n).padStart(2,'0'),title:'Meu site '+n,payment_status:'RECEIVED',data:{company_name:'Marca '+n}});
function click(h,kind,value){
 const button={dataset:{[kind]:value},disabled:false};
 h.result.onclick({target:{closest:selector=>selector===('[data-account-'+(kind==='accountPage'?'page':'project')+']')?button:null}});
}

test('F204: UI fetches first remote page and next page from server, not the entire archive',async()=>{
 const h=harness();
 assert.equal(h.calls.length,1);
 assert.equal(h.calls[0].url,'/api/account/projects?page=1&payment=all&search=');
 h.calls[0].resolve({projects:Array.from({length:8},(_,i)=>item(i+1)),total:21,page:1,pages:3});
 await tick();
 assert.ok(h.result.innerHTML.includes('Página 1 de 3'));
 assert.equal((h.result.innerHTML.match(/<article>/g)||[]).length,8);
 click(h,'accountPage','next');
 assert.equal(h.calls[1].url,'/api/account/projects?page=2&payment=all&search=');
 h.calls[1].resolve({projects:[item(9),item(10)],total:21,page:2,pages:3});
 await tick();
 assert.ok(h.result.innerHTML.includes('data-account-project="project-09"'));
 click(h,'accountProject','project-09');
 assert.deepEqual(h.opened,[{id:'project-09',section:'materials'}]);
});

test('F204: in-flight responses cannot replace newer search/filter results',async()=>{
 const h=harness();
 h.search.value='Cafe São';
 h.search.oninput();
 assert.equal(h.timers.length,1);
 h.calls[0].resolve({projects:[item(1)],total:21,page:1,pages:3});
 await tick();
 assert.equal(h.result.innerHTML.includes('data-account-project="project-01"'),false);
 h.timers[0]();
 assert.equal(h.calls[1].url,'/api/account/projects?page=1&payment=all&search=Cafe%20S%C3%A3o');
 h.payment.value='paid';h.payment.onchange();
 assert.equal(h.calls[2].url,'/api/account/projects?page=1&payment=paid&search=Cafe%20S%C3%A3o');
 h.calls[2].resolve({projects:[item(3)],total:1,page:1,pages:1});
 await tick();
 h.calls[1].resolve({projects:[item(2)],total:1,page:1,pages:1});
 await tick();
 assert.ok(h.result.innerHTML.includes('data-account-project="project-03"'));
 assert.equal(h.result.innerHTML.includes('data-account-project="project-02"'),false);
});

test('F204: remote query error offers retry and never displays a misleading empty state',async()=>{
 const h=harness();
 h.calls[0].reject(new Error('Network unavailable'));
 await tick();
 assert.ok(h.result.innerHTML.includes('Tentar novamente'));
 click(h,'accountPage','retry');
 assert.equal(h.calls.length,2);
 h.calls[1].resolve({projects:[],total:0,page:1,pages:1});
 await tick();
 assert.ok(h.result.innerHTML.includes('Nenhum projeto encontrado'));
 h.result.isConnected=false;
 h.payment.value='other';h.payment.onchange();
 assert.equal(h.calls.length,2);
});

test('F210: remote browser stops at capped last page and offers full export guidance',async()=>{
 const h=harness();
 h.calls[0].resolve({projects:[item(8000)],total:8008,page:1000,pages:1000,limited:true});
 await tick();
 assert.ok(h.result.innerHTML.includes('8.000 projetos'));
 assert.ok(h.result.innerHTML.includes('exportação completa'));
 assert.ok(h.result.innerHTML.includes('Página 1000 de 1000'));
 assert.ok(h.result.innerHTML.includes('data-account-page="next" disabled'));
});

test('F211: rate-limited project search displays a clear retry-later message',async()=>{
 const h=harness();
 h.calls[0].reject(new Error('Muitas tentativas. Aguarde alguns minutos.'));
 await tick();
 assert.ok(h.result.innerHTML.includes('Muitas consultas em pouco tempo'));
 assert.ok(h.result.innerHTML.includes('Tentar novamente'));
});
