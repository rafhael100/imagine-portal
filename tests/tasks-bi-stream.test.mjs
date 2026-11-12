import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {tasksBICsv,tasksBICsvChunks} from '../server/tasks-bi.mjs';
import {writeExportChunks} from '../server/owner-project-export.mjs';

const row=i=>({
 id:'task-'+i,title:i===0?'\u034F=SUM(1,2)':'Atividade '+i,
 kind:'manual',client:'Cliente',order_title:'Pedido',assignee:'Equipe',
 status:'pending',priority:'normal',created_at:'2026-11-11',
 due_date:'2026-11-20',completed_at:null,completion_estimated:false,
 overdue_days:0,age_days:1,cycle_days:null,on_time:null,archived_at:null,
 note:'Observação'
});

test('F255: streamed CSV equals the existing public serializer on empty and large BI reports',()=>{
 for(const size of [0,1,1000]){
  const data={rows:Array.from({length:size},(_,i)=>row(i))};
  const chunks=[...tasksBICsvChunks(data)];
  assert.equal(chunks.length,size+1);
  assert.equal(chunks.join(''),tasksBICsv(data));
  assert.ok(chunks[0].startsWith('\uFEFF"ID";"Atividade";'));
  if(size){
   assert.ok(chunks[1].startsWith('\r\n"task-0";'));
   assert.ok(chunks[1].includes('"\'\u034F=SUM(1,2)"'));
  }
  assert.equal(chunks.join('').endsWith('\r\n'),false);
 }
});

test('F255: backpressure on BI header never pulls even one activity row before drain',async()=>{
 let visited=0,closed=0;
 const data={rows:(function*(){
  try{for(let i=0;i<250;i++){visited++;yield row(i);}}
  finally{closed++;}
 })()};
 const out=[];
 const res=new EventEmitter();
 res.write=chunk=>{out.push(chunk);return out.length!==1;};
 let ended=0;
 res.end=()=>{ended++;};
 const pending=writeExportChunks(res,tasksBICsvChunks(data));
 assert.equal(visited,0);
 assert.equal(out.length,1);
 res.emit('drain');
 await pending;
 assert.equal(visited,250);
 assert.equal(closed,1);
 assert.equal(out.length,251);
 assert.equal(ended,1);
 for(const name of ['close','drain','error'])assert.equal(res.listenerCount(name),0);
});

test('F255: disconnected BI download does not consume private activity rows',async()=>{
 let visited=0;
 const data={rows:(function*(){visited++;yield row(0);})()};
 const res=new EventEmitter();
 res.write=()=>{res.emit('close');return false;};
 res.end=()=>{throw Error('must not end a truncated CSV');};
 await writeExportChunks(res,tasksBICsvChunks(data));
 assert.equal(visited,0);
 for(const name of ['close','drain','error'])assert.equal(res.listenerCount(name),0);
});
