// Synthetic, local-only benchmark for the legacy Node/SQLite BI.
// No external network, client data, credentials, filesystem writes or deploys.
import {performance} from 'node:perf_hooks';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {createTeam} from '../server/team.mjs';
import {buildTasksBI} from '../server/tasks-bi.mjs';

function argument(name,defaultValue,max){
 const index=process.argv.indexOf(name);
 if(index<0)return defaultValue;
 const raw=process.argv[index+1];
 if(!raw||!/^[1-9][0-9]*$/.test(raw))throw Error('Invalid '+name+'; expected a positive integer.');
 const value=Number(raw);
 if(!Number.isSafeInteger(value)||value>max)throw Error('Invalid '+name+'; maximum is '+max+'.');
 return value;
}

function run(){
 const rows=argument('--rows',1000,20000),iterations=argument('--iterations',3,10);
 const db=openStore(':memory:');
 try{
  createProduction(db);
  createTeam(db);
  const admin='f286-synthetic-admin';
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)')
   .run(admin,'Usuário sintético','synthetic-f286@example.test','synthetic-hash','admin','2026-11-01');
  const insert=db.prepare("INSERT INTO manual_tasks(id,title,instructions,assignee_id,status,due_date,created_by,created_at,updated_at,order_id,archived_at) VALUES(?,?,'Instruções sintéticas',?,?,?,?,?,?,?,?)");
  const statuses=['pending','doing','blocked','done'];
  db.exec('BEGIN IMMEDIATE');
  try{
   for(let i=0;i<rows;i++){
    const created='2026-11-'+String(1+i%20).padStart(2,'0')+'T12:00:00Z';
    insert.run('synthetic-task-'+i,'Atividade sintética '+i,admin,statuses[i%4],
     '2026-11-'+String(5+i%24).padStart(2,'0'),admin,created,
     '2026-11-25T12:00:00Z',null,null);
   }
   db.exec('COMMIT');
  }catch(error){db.exec('ROLLBACK');throw error;}
  const params=new URLSearchParams({from:'2026-11-01',to:'2026-11-30'});
  const clock=new Date('2026-11-20T15:00:00Z');
  const memoryBefore=process.memoryUsage().heapUsed;
  const runs=[];
  let report;
  for(let i=0;i<iterations;i++){
   const start=performance.now();
   report=buildTasksBI(db,params,clock,true);
   runs.push(Math.round((performance.now()-start)*1000)/1000);
   if(report.summary.total!==rows||report.rows.length!==rows)
    throw Error('Synthetic BI returned an incomplete cohort.');
  }
  const samples=[...runs].sort((a,b)=>a-b);
  process.stdout.write(JSON.stringify({
   benchmark:'legacy-node-tasks-bi',
   synthetic:true,
   rows,
   iterations,
   duration_ms:runs,
   median_ms:samples[Math.floor(samples.length/2)],
   heap_delta_bytes:process.memoryUsage().heapUsed-memoryBefore,
   total_tasks:report.summary.total,
   months:report.months.length,
   note:'Local synthetic measurements only; not representative of Laravel/PostgreSQL production.'
  })+'\n');
 }finally{db.close();}
}
try{run();}catch(error){process.stderr.write('Synthetic benchmark failed: '+error.message+'\n');process.exitCode=1;}
