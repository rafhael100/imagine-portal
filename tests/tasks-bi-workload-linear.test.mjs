import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

const mean=values=>values.length?Math.round(values.reduce((s,v)=>s+v,0)/values.length*10)/10:null;

test('F282: workload Map aggregation matches the previous full-cohort scan',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const users=[randomUUID(),randomUUID(),randomUUID()];
  for(const [i,id] of users.entries())
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Responsável '+i,
    id+'@example.test','hash',i===0?'admin':'client','2026-11-01');
  for(const id of users.slice(1))
   db.prepare("INSERT INTO staff VALUES(?,'atendimento',1)").run(id);
  const add=db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instruções internas',?,?,?,?,?,?,?,?)");
  const update=db.prepare('INSERT INTO manual_task_updates(id,task_id,actor_id,status,note,created_at) VALUES(?,?,?,?,?,?)');
  for(let i=0;i<48;i++){
   const id=randomUUID(),assigned=users[i%3],status=['pending','doing','blocked','done'][i%4];
   const created='2026-11-'+String(1+(i%20)).padStart(2,'0')+'T12:00:00Z';
   const finished='2026-11-'+String(21+(i%5)).padStart(2,'0')+'T14:00:00Z';
   add.run(id,'Atividade '+i,assigned,status,i%2?'2026-11-22':'2026-11-29',
    users[0],created,status==='done'?finished:created,null,null);
   if(status==='done')update.run(randomUUID(),id,assigned,'done','Concluída',finished);
  }
  let originalOrder=[];
  const observed={prepare(sql){
   const stmt=db.prepare(sql);
   if(!sql.includes('FROM work_items t LEFT JOIN'))return stmt;
   return {all(){
    const source=stmt.all();
    originalOrder=[...new Set(source.map(item=>item.assignee_id))];
    return source;
   }};
  }};
  const result=buildTasksBI(observed,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),
   new Date('2026-11-27T15:00:00Z'),true);
  assert.equal(result.rows.length,48);
  const expected=[...new Set(result.rows.map(t=>t.assignee_id))].map(id=>{
   const ts=result.rows.filter(t=>t.assignee_id===id),os=ts.filter(t=>t.active),
    ds=ts.filter(t=>t.status==='done'&&!t.completion_estimated);
   return {
    id:id||'none',name:ts[0].assignee,total:ts.length,
    open:os.length,overdue:os.filter(t=>t.overdue_days>0).length,
    blocked:os.filter(t=>t.status==='blocked').length,
    urgent:os.filter(t=>['critical','high'].includes(t.priority)).length,
    done:ts.filter(t=>t.status==='done').length,
    cycle_mean:mean(ds.map(t=>t.cycle_days)),
    on_time_percent:ds.length?Math.round(ds.filter(t=>t.on_time).length/ds.length*100):null
   };
  }).sort((a,b)=>b.overdue-a.overdue||b.open-a.open);
  const byId=items=>[...items].sort((a,b)=>a.id.localeCompare(b.id));
  assert.deepEqual(byId(result.workload),byId(expected),
   'all manager metrics remain unchanged, irrespective of the post-query rows sort');
  assert.deepEqual(result.workload.map(item=>item.id),originalOrder,
   'stable KPI ties keep first-seen SQL cohort order');
  assert.equal(result.workload.length,3);
  assert.equal(result.workload.reduce((sum,t)=>sum+t.total,0),result.summary.total);
  assert.equal(result.workload.reduce((sum,t)=>sum+t.open,0),result.summary.open);
 }finally{db.close();}
});
