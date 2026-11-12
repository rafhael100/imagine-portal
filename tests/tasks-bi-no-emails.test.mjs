import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI,tasksBICsv} from '../server/tasks-bi.mjs';

test('F268: BI preserves task labels but never selects unused client/staff emails',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID(),staff=randomUUID(),client=randomUUID(),order=randomUUID(),task=randomUUID();
  for(const [id,name,email,role] of [
   [admin,'Admin','admin-private-f268@example.test','admin'],
   [staff,'Atendente','staff-private-f268@example.test','client'],
   [client,'Empresa','client-private-f268@example.test','client']
  ])db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,name,email,'hash',role,'2026-11-01');
  db.prepare("INSERT INTO staff VALUES(?,'atendimento',1)").run(staff);
  db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site','Site Empresa','Brief','Escopo','7 dias','2026-11-01','2026-11-01')").run(order,client);
  db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Revisar informações',?,?,?,?,?,?,?,?)")
   .run(task,'Tarefa de acompanhamento',staff,'pending','2026-11-20',admin,'2026-11-11T12:00:00Z','2026-11-11T12:00:00Z',order,null);
  const data=buildTasksBI(db,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),new Date('2026-11-11T15:00:00Z'),true);
  assert.equal(data.summary.total,1);
  assert.equal(data.rows.length,1);
  assert.equal(data.rows[0].title,'Tarefa de acompanhamento');
  assert.equal(data.rows[0].assignee,'Atendente');
  assert.equal(data.rows[0].client,'Empresa');
  assert.equal(Object.hasOwn(data.rows[0],'assignee_email'),false);
  assert.equal(Object.hasOwn(data.rows[0],'client_email'),false);
  const exported=JSON.stringify(data)+'\n'+tasksBICsv(data);
  for(const email of ['admin-private-f268@example.test','staff-private-f268@example.test','client-private-f268@example.test'])
   assert.equal(exported.includes(email),false,'BI does not need '+email);
  assert.ok(tasksBICsv(data).includes('"Tarefa de acompanhamento"'));
 }finally{db.close();}
});
