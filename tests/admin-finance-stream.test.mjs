import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {financeCsvChunks} from '../server/admin-finance-export.mjs';
import {safeCsvCell} from '../server/account-export.mjs';
import {writeExportChunks} from '../server/owner-project-export.mjs';

function fakeDatabase(payments){
 let queried=0,visited=0,closed=0;
 const db={prepare(sql){
  assert.match(sql,/FROM payments p JOIN orders o/);
  return {iterate(){
   queried++;
   return (function*(){
    try{for(const payment of payments){visited++;yield payment;}}
    finally{closed++;}
   })();
  }};
 }};
 return {db,stats:()=>({queried,visited,closed})};
}
const payment=(id,title='Site')=>({
 order_id:id,name:'Cliente',email:'cliente@example.test',title,kind:'site',
 status:'PENDING',value_cents:12345,net_cents:null,due_date:'2026-11-11',paid_at:null
});

test('F250: CSV financeiro consulta pagamentos apenas depois de enviar cabecalho',()=>{
 const f=fakeDatabase([payment('first','\u034F=2+2'),payment('second')]);
 const chunks=financeCsvChunks(f.db);
 const header=chunks.next();
 assert.equal(header.done,false);
 assert.ok(header.value.startsWith('\uFEFF"Pedido";"Cliente";'));
 assert.deepEqual(f.stats(),{queried:0,visited:0,closed:0});
 const first=chunks.next();
 assert.ok(first.value.startsWith('\r\n"first";'));
 assert.ok(first.value.includes('"\'\u034F=2+2"'));
 assert.deepEqual(f.stats(),{queried:1,visited:1,closed:0});
 chunks.return();
 assert.deepEqual(f.stats(),{queried:1,visited:1,closed:1});
});

test('F250: streaming financeiro preserva bytes, colunas, BOM e ausencia de nova linha final',()=>{
 const rows=[payment('first'),{...payment('second','\u00AD+SUM(1,2)'),net_cents:11999,paid_at:'2026-11-10'}];
 const f=fakeDatabase(rows);
 const csv=[...financeCsvChunks(f.db)].join('');
 const headers=['Pedido','Cliente','Email','Serviço','Tipo','Status','Valor bruto BRL','Valor líquido BRL','Vencimento','Pagamento'];
 const legacy='\uFEFF'+[headers,...rows.map(p=>[
  p.order_id,p.name,p.email,p.title,p.kind,p.status,
  (p.value_cents/100).toFixed(2),p.net_cents===null?'':(p.net_cents/100).toFixed(2),p.due_date,p.paid_at
 ])].map(r=>r.map(safeCsvCell).join(';')).join('\r\n');
 assert.equal(csv,legacy);
 assert.ok(csv.includes('"\'\u00AD+SUM(1,2)"'));
 assert.deepEqual(f.stats(),{queried:1,visited:2,closed:1});
});

test('F250: backpressure de cabecalho segura cursor SQLite ate drain',async()=>{
 const f=fakeDatabase(Array.from({length:50},(_,i)=>payment('id-'+i)));
 const res=new EventEmitter(),parts=[];
 let ended=0;
 res.write=part=>{parts.push(part);return parts.length!==1;};
 res.end=()=>{ended++;};
 const pending=writeExportChunks(res,financeCsvChunks(f.db));
 assert.equal(parts.length,1);
 assert.deepEqual(f.stats(),{queried:0,visited:0,closed:0});
 res.emit('drain');
 await pending;
 assert.equal(parts.length,51);
 assert.equal(ended,1);
 assert.deepEqual(f.stats(),{queried:1,visited:50,closed:1});
 for(const event of ['close','error','drain'])assert.equal(res.listenerCount(event),0);
});

test('F250: desconexao durante cabecalho impede consultar dados privados',async()=>{
 const f=fakeDatabase(Array.from({length:50},(_,i)=>payment('id-'+i)));
 const res=new EventEmitter();
 res.write=()=>{res.emit('close');return false;};
 let ended=false;
 res.end=()=>{ended=true;};
 await writeExportChunks(res,financeCsvChunks(f.db));
 assert.deepEqual(f.stats(),{queried:0,visited:0,closed:0});
 assert.equal(ended,false);
 for(const event of ['close','error','drain'])assert.equal(res.listenerCount(event),0);
});
