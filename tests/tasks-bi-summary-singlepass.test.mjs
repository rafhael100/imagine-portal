import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

const mean=values=>values.length?Math.round(values.reduce((s,v)=>s+v,0)/values.length*10)/10:null;
const median=values=>{
 if(!values.length)return null;
 const sorted=[...values].sort((a,b)=>a-b),mid=Math.floor(sorted.length/2);
 return Math.round((values.length%2?sorted[mid]:(sorted[mid-1]+sorted[mid])/2)*10)/10;
};

test('F284: all summary KPIs match the previous repeated-filter implementation',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID(),staff=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(admin,'Admin','admin-f284@example.test','hash','admin','2026-11-01');
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(staff,'Staff','staff-f284@example.test','hash','client','2026-11-01');
  db.prepare("INSERT INTO staff VALUES(?,'atendimento',0)").run(staff);
  const insert=db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instruções',?,?,?,?,?,?,?,?)");
  const history=db.prepare('INSERT INTO manual_task_updates(id,task_id,actor_id,status,note,created_at) VALUES(?,?,?,?,?,?)');
  const statuses=['pending','doing','blocked','done','canceled'],priorities=['normal','critical','high','low'];
  for(let i=0;i<65;i++){
   const id=randomUUID(),status=statuses[i%5],created='2026-11-'+String(1+i%19).padStart(2,'0')+'T12:00:00Z';
   const finished='2026-11-25T12:00:00Z',due='2026-11-'+String(5+i%20).padStart(2,'0');
   insert.run(id,'Atividade '+i,i%3===0?staff:admin,status,due,admin,created,finished,null,
    (status==='done'||status==='canceled')&&i%2===0?'2026-11-26T12:00:00Z':null);
   db.prepare('UPDATE manual_tasks SET priority=? WHERE id=?').run(priorities[i%4],id);
   if(status==='done'&&i%2===0)history.run(randomUUID(),id,admin,'done','Concluída',finished);
  }
  const data=buildTasksBI(db,new URLSearchParams({from:'2026-11-01',to:'2026-11-30'}),
   new Date('2026-11-20T15:00:00Z'),true);
  const rows=data.rows,active=rows.filter(t=>t.active),done=rows.filter(t=>t.status==='done'),
   reliable=done.filter(t=>!t.completion_estimated),cycles=reliable.map(t=>t.cycle_days),
   ontime=reliable.filter(t=>t.on_time).length;
  const expected={
   total:rows.length,
   open:active.length,
   doing:active.filter(t=>t.status==='doing').length,
   blocked:active.filter(t=>t.status==='blocked').length,
   overdue:active.filter(t=>t.overdue_days>0).length,
   today:active.filter(t=>t.due_date===data.today).length,
   unassigned:active.filter(t=>t.unassigned).length,
   urgent:active.filter(t=>['critical','high'].includes(t.priority)).length,
   done:done.length,
   canceled:rows.filter(t=>t.status==='canceled').length,
   archived:rows.filter(t=>t.archived_at).length,
   cycle_mean:mean(cycles),cycle_median:median(cycles),
   on_time_percent:reliable.length?Math.round(ontime/reliable.length*100):null,
   on_time_sample:reliable.length,
   estimated_completions:done.length-reliable.length,
   oldest_open:active.length?Math.max(...active.map(t=>t.age_days)):null,
   due_changes:rows.reduce((s,t)=>s+t.due_changes,0)
  };
  assert.deepEqual(data.summary,expected,'every KPI must match the previous algorithm');
  assert.equal(data.summary.total,65);
  assert.ok(data.summary.unassigned>0);
  assert.ok(data.summary.archived>0);
  assert.ok(data.summary.estimated_completions>0);
  assert.ok(data.summary.on_time_sample>0);
 }finally{db.close();}
});
