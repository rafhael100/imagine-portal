import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

const localDay=value=>value?new Intl.DateTimeFormat('en-CA',{
 timeZone:'America/Sao_Paulo',year:'numeric',month:'2-digit',day:'2-digit'
}).format(new Date(value)):'';

test('F281: monthly aggregates match the previous per-month scan for all filter bases',()=>{
 const db=openStore(':memory:');
 createProduction(db);createTeam(db);
 try{
  const admin=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(admin,'Admin','f281@example.test','hash','admin','2026-11-01');
  const tasks=[
   ['2026-09-14T15:00:00Z','2026-09-20T12:00:00Z','done'],
   ['2026-09-15T03:00:00Z','2026-10-01T01:30:00Z','done'],
   ['2026-10-01T01:30:00Z','2026-11-01T14:00:00Z','done'],
   ['2026-10-01T13:00:00Z','2026-10-10T15:00:00Z','pending'],
   ['2026-11-01T01:30:00Z','2026-11-15T20:00:00Z','done'],
   ['2026-11-10T15:00:00Z','2026-11-11T15:00:00Z','pending'],
   ['2026-11-30T21:00:00Z','2026-12-01T14:00:00Z','done']
  ];
  const ins=db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instrução',?,?,?,?,?,?,?,?)");
  tasks.forEach(([created,updated,status],i)=>ins.run(randomUUID(),'Tarefa '+i,admin,status,'2026-11-25',
    admin,created,updated,null,null));
  const from='2026-09-15',to='2026-11-30';
  for(const basis of ['created','due','completed']){
   const params=new URLSearchParams({from,to,basis});
   const data=buildTasksBI(db,params,new Date('2026-11-15T20:00:00Z'),true);
   const expected=[];
   for(const month of ['2026-09','2026-10','2026-11']){
    const inside=value=>value&&value>=from&&value<=to;
    expected.push({
     month,
     created:data.rows.filter(t=>inside(localDay(t.created_at))&&localDay(t.created_at).startsWith(month)).length,
     completed:data.rows.filter(t=>t.status==='done'&&inside(localDay(t.completed_at))&&
      localDay(t.completed_at).startsWith(month)).length
    });
   }
   assert.deepEqual(data.months,expected,'monthly aggregation must match the full-cohort contract for basis '+basis);
   assert.equal(data.months.length,3);
  }
  const created=buildTasksBI(db,new URLSearchParams({from,to,basis:'created'}),
   new Date('2026-11-15T20:00:00Z'),true);
  assert.deepEqual(created.months.map(x=>x.created),[2,2,2],
   'UTC dates just after midnight must be grouped using the São Paulo calendar');
 }finally{db.close();}
});
