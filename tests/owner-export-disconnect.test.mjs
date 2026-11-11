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

for(const state of ['close','destroyed','writableEnded']){
 test(`F240: a positive write() closing via ${state} stops before the next DB row`,async()=>{
  const res=new EventEmitter();
  const yielded=[],written=[];
  let finalized=false,ended=false;
  res.write=chunk=>{
   written.push(chunk);
   if(state==='close')res.emit('close');
   else res[state]=true;
   return true;
  };
  res.end=()=>{ended=true;};
  function *chunks(){
   try{
    yielded.push('first');yield 'first';
    yielded.push('unexpected extra cursor row');yield 'private';
   }finally{finalized=true;}
  }
  await writeExportChunks(res,chunks());
  assert.deepEqual(written,['first']);
  assert.deepEqual(yielded,['first']);
  assert.equal(finalized,true);
  assert.equal(ended,false);
  for(const event of ['close','drain','error'])assert.equal(res.listenerCount(event),0);
 });
}

test('F240: drain immediately followed by close does not read the next cursor row',async()=>{
 const res=new EventEmitter();
 const yielded=[];
 let finalized=false,ended=false;
 res.write=()=>false;
 res.end=()=>{ended=true;};
 function *chunks(){try{yielded.push(1);yield 'first';yielded.push(2);yield 'second';}finally{finalized=true;}}
 const pending=writeExportChunks(res,chunks());
 res.emit('drain');
 res.emit('close');
 await pending;
 assert.deepEqual(yielded,[1]);
 assert.equal(finalized,true);
 assert.equal(ended,false);
 for(const event of ['close','drain','error'])assert.equal(res.listenerCount(event),0);
});

for(const when of ['write:true','write:false','end']){
 test(`F242: synchronous transport error during ${when} is rejected and releases listeners`,async()=>{
  const res=new EventEmitter();
  let destroyed=0,ended=0,finalized=false;
  const error=new Error(`transport failed: ${when}`);
  res.headersSent=true;
  res.destroy=()=>{destroyed++;res.destroyed=true;res.emit('close');};
  res.write=()=>{
   if(when.startsWith('write'))res.emit('error',error);
   return when!=='write:false';
  };
  res.end=()=>{ended++;if(when==='end')res.emit('error',error);};
  function *chunks(){try{yield 'first';yield 'must-not-read';}finally{finalized=true;}}
  await assert.rejects(writeExportChunks(res,chunks()),err=>err===error);
  assert.equal(destroyed,1);
  assert.equal(finalized,true);
  assert.equal(ended,when==='end'?1:0);
  for(const event of ['close','drain','error'])assert.equal(res.listenerCount(event),0);
 });
}
