import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {financeCsvChunks,streamAdminFinanceCsv,ADMIN_FINANCE_MAX_STREAM_MS} from '../server/admin-finance-export.mjs';
import {safeCsvCell} from '../server/account-export.mjs';
import {writeExportChunks} from '../server/owner-project-export.mjs';

function fakeDatabase(payments){
 let queried=0,visited=0,closed=0;
 const db={prepare(sql){
  // F257: forbid SELECT * so payment provider internals are never fetched.
  assert.match(sql,/SELECT p\.order_id,p\.kind,p\.status,p\.value_cents,p\.net_cents,p\.due_date,p\.paid_at,o\.title,u\.name,u\.email/);
  assert.doesNotMatch(sql,/\bp\.\*/);
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


test('F251: admin finance streaming deadline limits stalled SQLite cursors',async()=>{
 assert.equal(ADMIN_FINANCE_MAX_STREAM_MS,5*60*1000);
 const f=fakeDatabase(Array.from({length:100},(_,i)=>payment('p-'+i)));
 const res=new EventEmitter();
 let writes=0,ends=0,destroys=0;
 res.write=()=>{writes++;return writes!==2;};
 res.end=()=>{ends++;};
 res.destroy=()=>{destroys++;res.destroyed=true;res.emit('close');};
 await streamAdminFinanceCsv(res,f.db,25);
 assert.equal(writes,2,'header and first payment reached the output');
 assert.equal(ends,0,'partial CSV must never be ended normally');
 assert.equal(destroys,1);
 assert.deepEqual(f.stats(),{queried:1,visited:1,closed:1});
 for(const event of ['close','drain','error'])assert.equal(res.listenerCount(event),0);
});

test('F251: normal admin finance streaming clears deadline after successful completion',async()=>{
 const f=fakeDatabase([payment('p-1')]),res=new EventEmitter();
 let ends=0,destroys=0;
 res.write=()=>true;
 res.end=()=>{ends++;};
 res.destroy=()=>{destroys++;res.destroyed=true;res.emit('close');};
 await streamAdminFinanceCsv(res,f.db,25);
 assert.equal(ends,1);
 await new Promise(resolve=>setTimeout(resolve,50));
 assert.equal(destroys,0,'no orphan timeout may destroy a completed response');
 assert.deepEqual(f.stats(),{queried:1,visited:1,closed:1});
});
