import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';

const source=await readFile(fileURLToPath(new URL('../account-ui.js',import.meta.url)),'utf8');
function mount(pages){
 const state={tab:'account'};
 const calls=[],notices=[];
 const target={
  isConnected:true,innerHTML:'',textContent:'',onclick:null,
  contains:()=>true,
 };
 const ctx={
  document:{querySelector:()=>target},
  state,
  api:async path=>{
   calls.push(path);
   const match=path.match(/[?]page=(\d+)/);
   return pages[Number(match?.[1]??0)]??{events:[],page:1,has_more:false};
  },
  task:async(_button,fn)=>fn(),
  notify:(message,error)=>notices.push({message,error}),
  escapeHtml:value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])),
 };
 runInNewContext(source+'\nthis.__bindSecurity=bindAccountSecurityHistory;',ctx);
 return {target,state,calls,notices,load:ctx.__bindSecurity('#account-security-history')};
}
const event=(action,day)=>({action,created_at:'2026-11-'+String(day).padStart(2,'0')+'T10:00:00.000Z'});

test('F190: client and staff share paged timeline; continuation appends without duplicate pages',async()=>{
 const h=mount({
  1:{events:[event('password.changed',11),event('account.created',10)],page:1,has_more:true},
  2:{events:[event('sessions.revoked_others',9)],page:2,has_more:false},
 });
 await h.load();
 assert.equal(h.calls[0],'/api/account/security-events?page=1');
 assert.ok(h.target.innerHTML.includes('Mostrar atividades anteriores'));
 assert.equal((h.target.innerHTML.match(/<li>/g)||[]).length,2);
 const button={disabled:false};
 h.target.onclick({target:{closest:()=>button}});
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(h.calls[1],'/api/account/security-events?page=2');
 assert.equal((h.target.innerHTML.match(/<li>/g)||[]).length,3);
 assert.equal(h.target.innerHTML.includes('Mostrar atividades anteriores'),false);
 await h.load();
 assert.equal(h.calls.at(-1),'/api/account/security-events?page=1');
 assert.equal((h.target.innerHTML.match(/<li>/g)||[]).length,2);
});

test('F190: invalid timestamps and unknown actions remain harmless text, disconnected DOM is not mutated',async()=>{
 const h=mount({1:{events:[{action:'<script>x</script>',created_at:'not-a-date'}],page:1,has_more:false}});
 await h.load();
 assert.ok(h.target.innerHTML.includes('Evento de segurança'));
 assert.ok(h.target.innerHTML.includes('Data não disponível'));
 assert.equal(h.target.innerHTML.includes('<script>'),false);
 const prior=h.calls.length;
 h.target.isConnected=false;
 await h.load();
 assert.equal(h.calls.length,prior);
});

test('F190: empty audit history is usable and shows no pagination action',async()=>{
 const h=mount({1:{events:[],page:1,has_more:false}});
 await h.load();
 assert.ok(h.target.innerHTML.includes('Nenhuma alteração'));
 assert.ok(!h.target.innerHTML.includes('Mostrar atividades anteriores'));
});
