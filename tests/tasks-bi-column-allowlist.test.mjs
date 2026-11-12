import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI,tasksBICsv} from '../server/tasks-bi.mjs';

test('F269: BI SQL never loads checklist or source key that are excluded from public result',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID(),staff=randomUUID(),client=randomUUID(),order=randomUUID(),id=randomUUID();
  for(const [uid,name,role] of [[admin,'Admin','admin'],[staff,'Equipe','client'],[client,'Cliente','client']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(uid,name,uid+'@example.test','hash',role,'2026-11-01');
  db.prepare("INSERT INTO staff VALUES(?,'atendimento',1)").run(staff);
  db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site','Pedido','Brief','Escopo','7 dias','2026-11-01','2026-11-01')").run(order,client);
  db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instruções internas',?,?,?,?,?,?,?,?)")
   .run(id,'Atividade',staff,'pending','2026-11-25',admin,'2026-11-11T12:00:00Z','2026-11-11T12:00:00Z',order,null);
  db.prepare('UPDATE manual_tasks SET checklist=?,source_key=?,goal=? WHERE id=?')
   .run('["PRIVATE_CHECKLIST_F269"]','PRIVATE_SOURCE_KEY_F269','Meta permitida',id);
  let inspected=0;
  const proxy={prepare(sql){
   const stmt=db.prepare(sql);
   if(!sql.includes('FROM work_items t'))return stmt;
   return {all(){
    inspected++;
    assert.doesNotMatch(sql,/t\.\*/);
    assert.doesNotMatch(sql,/t\.checklist\b/);
    assert.doesNotMatch(sql,/t\.source_key\b/);
    const rows=stmt.all();
    assert.equal(rows.length,1);
    assert.equal(Object.hasOwn(rows[0],'checklist'),false);
    assert.equal(Object.hasOwn(rows[0],'source_key'),false);
    return rows;
   }};
  }};
  const data=buildTasksBI(proxy,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),new Date('2026-11-11T15:00:00Z'),true);
  assert.equal(inspected,1);
  assert.equal(data.summary.total,1);
  assert.equal(data.rows[0].title,'Atividade');
  assert.equal(data.rows[0].goal,'Meta permitida','existing public shape is preserved');
  const output=JSON.stringify(data)+tasksBICsv(data);
  for(const secret of ['PRIVATE_CHECKLIST_F269','PRIVATE_SOURCE_KEY_F269'])
   assert.equal(output.includes(secret),false);
 }finally{db.close();}
});
