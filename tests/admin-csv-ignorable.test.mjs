import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';
import {tasksBICsv} from '../server/tasks-bi.mjs';

test('F248: CSV de atividades protege prefixos Unicode invisiveis em campos editaveis',()=>{
 const csv=tasksBICsv({rows:[{
  id:'t-1',title:'\u034F=SUM(1,2)',client:'\u00AD@SUM(1)',
  order_title:'\u061C+2',note:'\u180E-3'
 }]});
 for(const malicious of ['\u034F=SUM(1,2)','\u00AD@SUM(1)','\u061C+2','\u180E-3']){
  assert.ok(csv.includes('"\' '+malicious+'"')===false,'the old guard must not add a space');
  assert.ok(csv.includes('"\' '+malicious.replaceAll(' ','')+'"')===false);
  assert.ok(csv.includes('"\''+malicious+'"'),JSON.stringify(malicious));
 }
 assert.ok(csv.startsWith('\uFEFF"ID"'));
});

test('F248: CSV financeiro HTTP escapa entradas de clientes sem afrouxar autenticacao',async()=>{
 const db=openStore(':memory:');
 const admin=randomUUID(),client=randomUUID(),order=randomUUID(),payment=randomUUID(),token=randomUUID();
 const adminName='Admin',customerName='\u034F=2+2',title='\u00AD+SUM(1,2)';
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(admin,adminName,'admin-f248@example.test','hash','admin','2026-10-01');
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(client,customerName,'client-f248@example.test','hash','client','2026-10-01');
 db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site',?,'Brief','Escopo','7 dias','2026-10-01','2026-10-01')").run(order,client,title);
 db.prepare("INSERT INTO payments(id,order_id,kind,status,value_cents,updated_at) VALUES(?,?,'site','PENDING',12345,'2026-10-01')").run(payment,order);
 db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(token),admin,'csrf',Date.now()+60000);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+app.server.address().port;
  assert.equal((await fetch(base+'/api/admin/finance.csv')).status,401);
  const response=await fetch(base+'/api/admin/finance.csv',{headers:{Cookie:'imagine_session='+token}});
  assert.equal(response.status,200);
  assert.match(response.headers.get('content-type')||'',/text\/csv/);
  assert.match(response.headers.get('cache-control')||'',/no-store/);
  const csv=await response.text();
  assert.ok(csv.includes('"\'\u034F=2+2"'));
  assert.ok(csv.includes('"\'\u00AD+SUM(1,2)"'));
  assert.ok(csv.includes('"123.45"'));
 }finally{
  await new Promise(resolve=>app.server.close(resolve));
  db.close();
 }
});
