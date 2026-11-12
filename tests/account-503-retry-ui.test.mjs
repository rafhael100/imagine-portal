import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const source=await readFile(fileURLToPath(new URL('../account-ui.js',import.meta.url)),'utf8');

function harness(errors){
 const calls=[],retry={onclick:null,disabled:false};
 const box={isConnected:true,innerHTML:'',querySelector:selector=>{
  if(selector==='[data-account-retry]'&&box.innerHTML.includes('data-account-retry'))return retry;
  return null;
 }};
 const context={
  document:{querySelector:s=>s==='#account-content'?box:null,querySelectorAll:()=>[]},
  api:path=>{calls.push(path);return Promise.reject(errors.shift()||Error('Unknown failure'));},
  state:{tab:'account',user:{role:'client'}},
  escapeHtml:value=>String(value??'').replace(/[&<>"']/g,c=>({
   '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  })[c])
 };
 runInNewContext(source+'\nthis.__load=loadClientAccount;',context);
 return {load:context.__load,box,retry,calls};
}

test('F275: corrupt stored profile produces fixed private-safe guidance with retry',async()=>{
 const h=harness([Error('Dados cadastrais temporariamente indisponíveis. PRIVATE_ACCOUNT_SECRET')]);
 await h.load();
 assert.deepEqual(h.calls,['/api/account?compact=1']);
 assert.match(h.box.innerHTML,/Seus dados cadastrais estão temporariamente indisponíveis/);
 assert.match(h.box.innerHTML,/procure nossa equipe/);
 assert.match(h.box.innerHTML,/role="alert"/);
 assert.match(h.box.innerHTML,/Tentar novamente/);
 assert.equal(h.box.innerHTML.includes('PRIVATE_ACCOUNT_SECRET'),false);
 assert.equal(typeof h.retry.onclick,'function');
 h.retry.onclick();
 assert.equal(h.retry.disabled,true,'avoid double submitting the same retry control');
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(h.calls.length,2);
 assert.match(h.box.innerHTML,/Não foi possível consultar seu cadastro agora/);
});

test('F275: generic backend exception is not embedded in account error markup',async()=>{
 const h=harness([Error('<img src=x onerror=alert(1)> PRIVATE_DATABASE_TRACE')]);
 await h.load();
 assert.match(h.box.innerHTML,/Não foi possível consultar seu cadastro agora/);
 assert.equal(h.box.innerHTML.includes('PRIVATE_DATABASE_TRACE'),false);
 assert.equal(h.box.innerHTML.includes('<img'),false);
 assert.match(h.box.innerHTML,/data-account-retry/);
});
