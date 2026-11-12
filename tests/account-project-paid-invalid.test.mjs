import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {accountProjectsPage} from '../server/account-projects.mjs';

test('F261: corrupted own paid briefing becomes controlled 503, not a JSON parse leak',()=>{
 const db=openStore(':memory:');
 createProduction(db); // accountProjectsPage joins productions.
 try{
  const owner=randomUUID(),foreign=randomUUID();
  for(const [id,name] of [[owner,'Titular'],[foreign,'Outro titular']]){
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)')
    .run(id,name,id+'@example.test','hash','client','2026-11-11');
  }
  const order=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
  const ownPaid=randomUUID(),ownUnpaid=randomUUID(),foreignPaid=randomUUID();
  order.run(ownPaid,owner,'Meu projeto','RECEIVED','2026-11-11','2026-11-11');
  order.run(ownUnpaid,owner,'Meu projeto pendente','PENDING','2026-11-10','2026-11-10');
  order.run(foreignPaid,foreign,'PRIVADO OUTRO TITULAR','RECEIVED','2026-11-12','2026-11-12');
  brief.run(ownPaid,JSON.stringify({company_name:'Minha empresa',password:'NAO_EXPOR'}),'2026-11-11');
  brief.run(ownUnpaid,'{BRIEFING_INVALIDO_NAO_PAGO','2026-11-10');
  brief.run(foreignPaid,'{CORRUPTO_DE_OUTRA_CONTA','2026-11-12');
  const user={id:owner,role:'client',staff_profile:null};
  const valid=accountProjectsPage(db,user);
  assert.equal(valid.total,2);
  assert.ok(valid.projects.some(p=>p.id===ownPaid&&p.data.company_name==='Minha empresa'));
  assert.deepEqual(valid.projects.find(p=>p.id===ownUnpaid).data,{});
  const text=JSON.stringify(valid);
  for(const secret of ['PRIVADO OUTRO TITULAR','NAO_EXPOR','BRIEFING_INVALIDO_NAO_PAGO','CORRUPTO_DE_OUTRA_CONTA'])
   assert.equal(text.includes(secret),false,secret);
  const unpaid=accountProjectsPage(db,user,{payment:'other'});
  assert.equal(unpaid.total,1,'malformed unpaid data must never block own pending orders');
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?')
   .run('{SEGREDO_DO_BRIEFING_PAGO',ownPaid);
  assert.throws(()=>accountProjectsPage(db,user),e=>
   e.status===503&&e.message==='Dados de projeto temporariamente indisponíveis para exportação.'&&
   !e.message.includes('SEGREDO'));
  // Unknown or foreign accounts must not be able to force an error for this owner.
  assert.equal(accountProjectsPage(db,user,{payment:'other'}).total,1);
  assert.throws(()=>accountProjectsPage(db,{id:foreign,role:'admin'}),e=>e.status===403);
 }finally{db.close();}
});

test('F261: fixing a malformed paid briefing restores its owner-only project view',()=>{
 const db=openStore(':memory:');
 createProduction(db); // accountProjectsPage joins productions.
 try{
  const id=randomUUID(),project=randomUUID(),user={id,role:'client'};
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Titular','owner-f261@example.test','hash','client','2026-11-11');
  db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site','Site','','','','CONFIRMED','2026-11-11','2026-11-11')").run(project,id);
  db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)').run(project,'{INVALIDO','2026-11-11');
  assert.throws(()=>accountProjectsPage(db,user),e=>e.status===503);
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?').run('{"company_name":"Marca valida"}',project);
  assert.equal(accountProjectsPage(db,user).projects[0].data.company_name,'Marca valida');
 }finally{db.close();}
});
