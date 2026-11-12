import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

test('F283: category counts and top eight risks match old full scans, including stable ties',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID(),staff=randomUUID();
  for(const [id,name,role] of [[admin,'Admin','admin'],[staff,'Atendente','client']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,name,id+'@example.test','hash',role,'2026-11-01');
  db.prepare("INSERT INTO staff VALUES(?,'atendimento',1)").run(staff);
  const insert=db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Acompanhamento',?,?,?,?,?,?,?,?)");
  const priorities=['normal','critical','high','low'],statuses=['pending','doing','blocked','done'];
  for(let i=0;i<56;i++){
   const id=randomUUID(),status=statuses[i%4],due=['2026-11-05','2026-11-15','2026-11-27','2026-11-29'][i%4];
   const created='2026-11-'+String(i%20+1).padStart(2,'0')+'T12:00:00Z';
   insert.run(id,'Tarefa '+i,i%2?staff:admin,status,due,admin,created,'2026-11-26T12:00:00Z',null,null);
   db.prepare('UPDATE manual_tasks SET priority=? WHERE id=?').run(priorities[i%4],id);
  }
  let initialIds=[];
  const observed={prepare(sql){
   const statement=db.prepare(sql);
   if(!sql.includes('FROM work_items t LEFT JOIN'))return statement;
   return {all(){
    const source=statement.all();
    initialIds=source.map(t=>t.id);
    return source;
   }};
  }};
  const result=buildTasksBI(observed,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),
   new Date('2026-11-27T15:00:00Z'),true);
  assert.equal(result.rows.length,56);
  assert.equal(initialIds.length,56);
  const byId=new Map(result.rows.map(t=>[t.id,t]));
  const original=initialIds.map(id=>byId.get(id));
  assert.ok(original.every(Boolean));
  const previousBreakdown=key=>[...new Set(original.map(t=>t[key]))]
   .map(value=>({key:value||'',count:original.filter(t=>t[key]===value).length}))
   .sort((a,b)=>b.count-a.count);
  assert.deepEqual(result.statuses,previousBreakdown('status'));
  assert.deepEqual(result.priorities,previousBreakdown('priority'));
  assert.deepEqual(result.kinds,previousBreakdown('kind'));
  const previousRisks=original.filter(t=>t.active&&(t.overdue_days||t.status==='blocked'||t.unassigned))
   .sort((a,b)=>b.overdue_days-a.overdue_days).slice(0,8);
  assert.equal(previousRisks.length,8);
  assert.deepEqual(result.risks,previousRisks,
   'risk subset, overdue sort and equal-overdue ordering must remain stable');
  assert.equal(result.statuses.reduce((total,t)=>total+t.count,0),result.summary.total);
 }finally{db.close();}
});
