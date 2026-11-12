import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

function setup(){
 const db=openStore(':memory:');createProduction(db);createTeam(db);
 const actor=randomUUID(),client=randomUUID(),order=randomUUID(),task=randomUUID();
 for(const [id,role] of [[actor,'admin'],[client,'client']])
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Pessoa',id+'@example.test','hash',role,'2026-11-01');
 db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site','Site','Brief','Escopo','7 dias','2026-11-01','2026-11-01')").run(order,client);
 db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instruções',?,?,?,?,?,?,?,?)")
  .run(task,'Tarefa concluída',actor,'done','2026-11-25',actor,'2026-11-01T12:00:00Z','2026-11-11T12:10:00Z',order,null);
 return {db,actor,task};
}
const historySQL='INSERT INTO task_management_history(id,task_id,origin,actor_id,action,reason,before_json,after_json,created_at) VALUES(?,?,?,?,?,?,?,?,?)';
const query=(db)=>buildTasksBI(db,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),
 new Date('2026-11-11T15:00:00Z'),true);

test('F276: BI SQL returns derived flags, never private management snapshots',()=>{
 const {db,actor,task}=setup();
 try{
  const insert=db.prepare(historySQL);
  const versions=[
   ['{"status":"pending","due_date":"2026-11-20","secret":"PRIVATE_BEFORE_0"}','{"status":"pending","due_date":"2026-11-25","secret":"PRIVATE_AFTER_0"}',1],
   ['{"status":"done","updated_at":"2026-11-11T12:05:00Z","due_date":"2026-11-25","secret":"PRIVATE_BEFORE_1"}','{"status":"done","due_date":"2026-11-25","secret":"PRIVATE_AFTER_1"}',0],
   ['{"status":"done","secret":"PRIVATE_BEFORE_2"}','{"status":"done","due_date":null,"secret":"PRIVATE_AFTER_2"}',1],
   ['{"status":"done","due_date":1,"secret":"PRIVATE_BEFORE_3"}','{"status":"done","due_date":1.0,"secret":"PRIVATE_AFTER_3"}',0],
   ['{"status":"done","due_date":["same"],"secret":"PRIVATE_BEFORE_4"}','{"status":"done","due_date":["same"],"secret":"PRIVATE_AFTER_4"}',1]
  ];
  versions.forEach(([before,after],i)=>insert.run(randomUUID(),task,'manual',actor,'edit-task','reason '+i,before,after,
   '2026-11-11T12:'+String(i).padStart(2,'0')+':00Z'));
  let inspected=0;
  const monitored={prepare(sql){
   const stmt=db.prepare(sql);
   if(!sql.includes('WITH snapshots AS'))return stmt;
   return {all(){
    inspected++;
    const rows=stmt.all();
    assert.equal(rows.length,versions.length);
    assert.deepEqual(rows.map(x=>x.due_changed),versions.map(x=>x[2]));
    for(const row of rows){
     assert.equal(Object.hasOwn(row,'before_json'),false);
     assert.equal(Object.hasOwn(row,'after_json'),false);
     assert.equal(Object.hasOwn(row,'reason'),false);
     assert.equal(row.malformed_history,0);
    }
    return rows;
   }};
  }};
  const data=query(monitored);
  assert.equal(inspected,1);
  assert.equal(data.summary.total,1);
  assert.equal(data.summary.due_changes,3);
  assert.equal(data.rows[0].due_changes,3);
  assert.equal(data.rows[0].completion_estimated,true);
  assert.equal(data.rows[0].completed_at,'2026-11-11T12:05:00Z');
  const raw=JSON.stringify(data);
  for(let i=0;i<versions.length;i++){
   assert.equal(raw.includes('PRIVATE_BEFORE_'+i),false);
   assert.equal(raw.includes('PRIVATE_AFTER_'+i),false);
  }
 }finally{db.close();}
});

test('F276: corrupt history of an active task fails closed; unrelated orphan history cannot poison BI',()=>{
 const {db,actor,task}=setup();
 try{
  const insert=db.prepare(historySQL);
  insert.run(randomUUID(),randomUUID(),'manual',actor,'edit-task','orphan',
   '{PRIVATE_ORPHAN_INVALID','{}','2026-11-11T11:00:00Z');
  assert.equal(query(db).summary.total,1);
  insert.run(randomUUID(),task,'manual',actor,'edit-task','actual',
   '{PRIVATE_TASK_INVALID','{}','2026-11-11T12:00:00Z');
  assert.throws(()=>query(db),e=>e.status===503&&
   e.message==='Histórico de atividade temporariamente indisponível.');
 }finally{db.close();}
});
