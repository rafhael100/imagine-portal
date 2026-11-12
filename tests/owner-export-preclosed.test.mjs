import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {Writable} from 'node:stream';
import {writeExportChunks} from '../server/owner-project-export.mjs';

for(const state of ['destroyed','closed','writableEnded','writableFinished']){
 test(`F246: export does not advance owner cursor when response is already ${state}`,async()=>{
  const res=new EventEmitter();
  res[state]=true;
  let rows=0,writes=0,ends=0;
  res.write=()=>{writes++;return true;};
  res.end=()=>{ends++;};
  function *privateRows(){rows++;yield 'private project';}
  await writeExportChunks(res,privateRows());
  assert.equal(rows,0,'must not open/read the private project cursor');
  assert.equal(writes,0);
  assert.equal(ends,0);
  for(const event of ['close','drain','error','finish'])
   assert.equal(res.listenerCount(event),0);
 });
}

test('F246: actual pre-destroyed Writable does not pull private rows or write',async()=>{
 let rows=0,writes=0;
 const res=new Writable({
  write(_chunk,_encoding,callback){writes++;callback();}
 });
 res.destroy();
 function *privateRows(){rows++;yield 'private project';}
 await writeExportChunks(res,privateRows());
 assert.equal(rows,0);
 assert.equal(writes,0);
 for(const event of ['close','drain','error','finish'])
  assert.equal(res.listenerCount(event),0);
});
