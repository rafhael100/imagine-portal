import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {OWNER_EXPORT_LEASE_MS,OWNER_EXPORT_MAX_STREAM_MS,writeOwnerExportWithinLease} from '../server/owner-project-export.mjs';

test('F241: maximum stream duration remains strictly below the lease expiration',()=>{
 assert.equal(OWNER_EXPORT_MAX_STREAM_MS,OWNER_EXPORT_LEASE_MS-60_000);
 assert.ok(OWNER_EXPORT_MAX_STREAM_MS<OWNER_EXPORT_LEASE_MS);
});

test('F241: timed-out backpressured stream is destroyed and generator finalized',async()=>{
 const res=new EventEmitter();
 let destroyed=0,ended=false,finalized=false;
 const rows=[];
 res.write=chunk=>{rows.push(chunk);return false;};
 res.destroy=()=>{destroyed++;res.destroyed=true;res.emit('close');};
 res.end=()=>{ended=true;};
 function *chunks(){try{yield 'first';yield 'must-not-read';}finally{finalized=true;}}
 await writeOwnerExportWithinLease(res,chunks(),10);
 assert.deepEqual(rows,['first']);
 assert.equal(destroyed,1);
 assert.equal(finalized,true);
 assert.equal(ended,false);
 for(const event of ['drain','close','error'])assert.equal(res.listenerCount(event),0);
});

test('F241: fast export ends normally without an eventual late destroy',async()=>{
 const res=new EventEmitter();
 let destroyed=0,ended=0;
 const rows=[];
 res.write=chunk=>{rows.push(chunk);return true;};
 res.end=()=>{ended++;res.writableEnded=true;};
 res.destroy=()=>{destroyed++;res.destroyed=true;res.emit('close');};
 await writeOwnerExportWithinLease(res,['first','last'],5);
 await new Promise(resolve=>setTimeout(resolve,20));
 assert.deepEqual(rows,['first','last']);
 assert.equal(ended,1);
 assert.equal(destroyed,0);
});

test('F241: invalid timeouts fail before writing any export',async()=>{
 const res={write(){throw Error('must not write');}};
 for(const ms of [0,-1,OWNER_EXPORT_LEASE_MS,Infinity,1.5])
  await assert.rejects(writeOwnerExportWithinLease(res,['secret'],ms),RangeError);
});
