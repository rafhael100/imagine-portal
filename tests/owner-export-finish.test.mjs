import test from 'node:test';
import assert from 'node:assert/strict';
import {Writable} from 'node:stream';
import {writeExportChunks,writeOwnerExportWithinLease} from '../server/owner-project-export.mjs';

const nextTick=()=>new Promise(resolve=>setImmediate(resolve));

test('F244: lease remains held until Writable finish, not just end()',async()=>{
 let releaseFinal;
 const chunks=[];
 const response=new Writable({
  write(chunk,_encoding,callback){chunks.push(chunk.toString());callback();},
  final(callback){releaseFinal=callback;}
 });
 let finished=false;
 const exportPromise=writeExportChunks(response,['first','second']).then(()=>{finished=true;});
 await nextTick();
 assert.equal(response.writableEnded,true,'end() was called');
 assert.equal(response.writableFinished,false,'OS buffer is not yet complete');
 assert.equal(finished,false,'export lease must not be released before finish');
 assert.equal(typeof releaseFinal,'function');
 releaseFinal();
 await exportPromise;
 assert.equal(response.writableFinished,true);
 assert.deepEqual(chunks,['first','second']);
 for(const event of ['finish','close','error','drain'])assert.equal(response.listenerCount(event),0);
});

test('F244: error after end() but before finish is caught and stream cleaned',async()=>{
 const fault=new Error('late flush error');
 const response=new Writable({
  write(_chunk,_encoding,callback){callback();},
  final(callback){queueMicrotask(()=>callback(fault));}
 });
 response.headersSent=true;
 await assert.rejects(writeExportChunks(response,['first']),error=>error===fault);
 assert.equal(response.destroyed,true);
 for(const event of ['finish','close','error','drain'])assert.equal(response.listenerCount(event),0);
});

test('F244: deadline destroys a stream stalled after end() before finish',async()=>{
 let finalStarted=false;
 const response=new Writable({
  write(_chunk,_encoding,callback){callback();},
  final(_callback){finalStarted=true;}
 });
 await writeOwnerExportWithinLease(response,['first'],30);
 assert.equal(finalStarted,true);
 assert.equal(response.writableEnded,true);
 assert.equal(response.writableFinished,false);
 assert.equal(response.destroyed,true,'timer must close an unfinished flush');
 for(const event of ['finish','close','error','drain'])assert.equal(response.listenerCount(event),0);
});
