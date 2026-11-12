import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

test('F278: BI reads only events and management history of existing work items',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID(),client=randomUUID(),order=randomUUID(),task=randomUUID(),orphan=randomUUID();
  for(const [id,role] of [[admin,'admin'],[client,'client']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Conta',id+'@example.test','hash',role,'2026-11-01');
  db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site','Projeto','Brief','Escopo','7 dias','2026-11-01','2026-11-01')").run(order,client);
  db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Trabalho interno',?,?,?,?,?,?,?,?)")
   .run(task,'Acompanhar projeto',admin,'pending','2026-11-20',admin,'2026-11-11T12:00:00Z','2026-11-11T12:00:00Z',order,null);
  const addHistory=db.prepare('INSERT INTO task_management_history(id,task_id,origin,actor_id,action,reason,before_json,after_json,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
  addHistory.run(randomUUID(),task,'manual',admin,'edit-task','Mudou prazo','{"due_date":"2026-11-20"}','{"due_date":"2026-11-25"}','2026-11-11T13:00:00Z');
  for(let i=0;i<120;i++)
   addHistory.run(randomUUID(),orphan,'manual',admin,'edit-task','Registro órfão',
    '{SENSITIVE_ORPHAN_BEFORE_F278','{SENSITIVE_ORPHAN_AFTER_F278',
    '2026-11-10T'+String(i%24).padStart(2,'0')+':00:00Z');
  let checks=0;
  const instrumented={prepare(sql){
   const statement=db.prepare(sql);
   if(sql.includes('FROM task_updates')){
    checks++;
    assert.equal((sql.match(/task_id IN \(SELECT id FROM work_items\)/g)||[]).length,3,
     'all three event sources must be scoped to existing tasks');
    return {all(){const values=statement.all();assert.equal(values.some(row=>row.task_id===orphan),false);return values;}};
   }
   if(sql.includes('WITH snapshots AS')){
    checks++;
    assert.match(sql,/FROM task_management_history WHERE task_id IN \(SELECT id FROM work_items\)/);
    return {all(){const values=statement.all();
     assert.equal(values.length,1,'orphan histories never enter Node memory');
     assert.equal(values[0].task_id,task);
     return values;
    }};
   }
   return statement;
  }};
  const result=buildTasksBI(instrumented,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),
   new Date('2026-11-12T15:00:00Z'),true);
  assert.equal(checks,2);
  assert.equal(result.summary.total,1);
  assert.equal(result.summary.due_changes,1);
  assert.equal(result.rows[0].title,'Acompanhar projeto');
  assert.equal(JSON.stringify(result).includes('SENSITIVE_ORPHAN'),false);
 }finally{db.close();}
});
