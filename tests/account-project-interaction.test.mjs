import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const source=await readFile(fileURLToPath(new URL('../account-ui.js',import.meta.url)),'utf8');
function harness(projects){
 const result={isConnected:true,innerHTML:'',onclick:null,contains:()=>true};
 const search={value:'',oninput:null},payment={value:'all',onchange:null},opened=[];
 const nodes={'#account-project-results':result,'#account-project-search':search,'#account-project-payment':payment};
 const ctx={
  document:{querySelector:selector=>nodes[selector]??null},
  openOrder:(id,section)=>opened.push({id,section}),
  escapeHtml:value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
 };
 runInNewContext(source+'\nthis.__bind=bindAccountProjectArchive;',ctx);
 ctx.__bind(projects);
 return {result,search,payment,opened};
}
function project(n){return {id:'p-'+String(n).padStart(3,'0'),title:'Site '+n,payment_status:n%2?'PENDING':'RECEIVED',data:{company_name:'Empresa '+n}};}
function dispatch(result,selector,dataset){
 const button={dataset,disabled:false};
 result.onclick({target:{closest:query=>query===selector?button:null}});
}

test('F199: page buttons and search rerender the right owned cards, without stale navigation',()=>{
 const h=harness(Array.from({length:18},(_,i)=>project(i+1)));
 assert.equal((h.result.innerHTML.match(/<article>/g)||[]).length,8);
 assert.ok(h.result.innerHTML.includes('Página 1 de 3'));
 dispatch(h.result,'[data-account-page]',{accountPage:'next'});
 assert.ok(h.result.innerHTML.includes('Página 2 de 3'));
 assert.ok(h.result.innerHTML.includes('data-account-project="p-009"'));
 dispatch(h.result,'[data-account-page]',{accountPage:'next'});
 assert.ok(h.result.innerHTML.includes('Página 3 de 3'));
 assert.equal((h.result.innerHTML.match(/<article>/g)||[]).length,2);
 h.search.value='Site 12';h.search.oninput();
 assert.ok(h.result.innerHTML.includes('data-account-project="p-012"'));
 assert.ok(!h.result.innerHTML.includes('data-account-project="p-011"'));
 assert.ok(h.result.innerHTML.includes('Página 1 de 1'));
 h.payment.value='other';h.payment.onchange();
 assert.ok(h.result.innerHTML.includes('Nenhum projeto encontrado'));
 h.payment.value='paid';h.payment.onchange();
 assert.ok(h.result.innerHTML.includes('data-account-project="p-012"'));
 dispatch(h.result,'[data-account-project]',{accountProject:'p-012'});
 assert.deepEqual(h.opened,[{id:'p-012',section:'materials'}]);
});

test('F199: disconnected project results never redraw or navigate on input',()=>{
 const h=harness([project(1)]);
 const original=h.result.innerHTML;
 h.result.isConnected=false;
 h.search.value='Nada';h.search.oninput();
 assert.equal(h.result.innerHTML,original);
});
