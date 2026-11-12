import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

test('F271: BI classifies management events without loading their private notes',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID(),client=randomUUID(),order=randomUUID(),task=randomUUID();
  for(const [id,name,role] of [[admin,'Admin','admin'],[client,'Cliente','client']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,name,id+'@example.test','hash',role,'2026-11-10');
  db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site','Site','Brief','Escopo','7 dias','2026-11-10','2026-11-10')").run(order,client);
  db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instruções do trabalho',?,?,?,?,?,?,?,?)")
   .run(task,'Tarefa de revisão',admin,'done','2026-11-20',admin,'2026-11-10T12:00:00Z','2026-11-11T12:09:00Z',order,null);
  const notes=[
   'Atividade editada: PRIVATE_0',
   'Atividade cancelada: PRIVATE_1',
   'Atividade arquivada: PRIVATE_2',
   'Atividade restaurada do arquivo: PRIVATE_3',
   'Atividade Editada: PRIVATE_4',
   'Atividade editada : PRIVATE_5',
   'Conclusão manual: PRIVATE_6'
  ];
  const insert=db.prepare('INSERT INTO manual_task_updates(id,task_id,actor_id,status,note,created_at) VALUES(?,?,?,?,?,?)');
  for(let i=0;i<notes.length;i++)insert.run(randomUUID(),task,admin,'done',notes[i],
   '2026-11-11T12:'+String(i).padStart(2,'0')+':00Z');
  let checked=0;
  const wrapped={prepare(sql){
   const stmt=db.prepare(sql);
   if(!sql.includes('FROM task_updates UNION ALL'))return stmt;
   return {all(){
    checked++;
    assert.doesNotMatch(sql,/SELECT task_id,status,note,created_at/);
    const rows=stmt.all();
    assert.equal(rows.length,notes.length);
    assert.ok(rows.every(row=>!Object.hasOwn(row,'note')));
    assert.deepEqual(rows.map(row=>row.management_note),[1,1,1,1,0,0,0],
      'case-sensitive prefixes must retain the old regex meaning');
    return rows;
   }};
  }};
  const data=buildTasksBI(wrapped,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),
   new Date('2026-11-11T15:00:00Z'),true);
  assert.equal(checked,1);
  assert.equal(data.summary.total,1);
  assert.equal(data.rows[0].updates_count,notes.length);
  assert.equal(data.rows[0].completion_estimated,false);
  assert.equal(data.rows[0].completed_at,'2026-11-11T12:04:00Z',
   'first real completion is the first non-management event');
  const output=JSON.stringify(data);
  for(let i=0;i<notes.length;i++)assert.equal(output.includes('PRIVATE_'+i),false);
 }finally{db.close();}
});
