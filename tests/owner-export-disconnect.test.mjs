import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {writeExportChunks} from '../server/owner-project-export.mjs';

for(const state of ['close','destroyed','writableEnded']){
 test(`export stops when write synchronously signals ${state}`,async()=>{
  const res=new EventEmitter();
  let writes=0,finalized=false,ended=false;
  res.write=()=>{
   writes++;
   if(state==='close')res.emit('close');
   else res[state]=true;
   return false;
  };
  res.end=()=>{ended=true;};
  function *chunks(){try{yield 'first';yield 'second';}finally{finalized=true;}}
  await writeExportChunks(res,chunks());
  assert.equal(writes,1);
  assert.equal(finalized,true,'cursor is released on disconnect');
  assert.equal(ended,false);
  for(const event of ['close','drain','error'])assert.equal(res.listenerCount(event),0);
 });
}

test('export resumes after asynchronous backpressure',async()=>{
 const res=new EventEmitter();
 const output=[];
 res.write=chunk=>{output.push(chunk);queueMicrotask(()=>res.emit('drain'));return false;};
 let ended=false;
 res.end=()=>{ended=true;};
 await writeExportChunks(res,['first','second']);
 assert.deepEqual(output,['first','second']);
 assert.equal(ended,true);
 for(const event of ['close','drain','error'])assert.equal(res.listenerCount(event),0);
});
