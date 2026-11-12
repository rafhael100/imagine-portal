import test from 'node:test';
import assert from 'node:assert/strict';
import {mkdtemp,rm} from 'node:fs/promises';
import {join} from 'node:path';
import {tmpdir} from 'node:os';
import {openStore} from '../server/store.mjs';
import {consumeRateLimit} from '../server/rate-limit.mjs';

test('F259: two SQLite connections count each request without lost increments',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'imagine-rate-limit-'));
 t.after(()=>rm(dir,{recursive:true,force:true}));
 const filename=join(dir,'portal.sqlite'),a=openStore(filename),b=openStore(filename);
 try{
  const now=Date.now(),key='admin-bi-json:synthetic-owner';
  const attempts=[];
  for(let i=0;i<8;i++)attempts.push(consumeRateLimit(i%2?b:a,key,5,60000,now));
  assert.deepEqual(attempts,[true,true,true,true,true,false,false,false]);
  const row=b.prepare('SELECT count,reset_at FROM rate_limits WHERE key=?').get(key);
  assert.equal(row.count,5);
  assert.equal(row.reset_at,now+60000);
  assert.equal(consumeRateLimit(b,'admin-bi-json:other',5,60000,now),true,
   'different owners have independent quotas');
  assert.equal(consumeRateLimit(a,key,5,60000,now+60000),true,
   'the expired window resets at the exact boundary');
  const renewed=a.prepare('SELECT count,reset_at FROM rate_limits WHERE key=?').get(key);
  assert.equal(renewed.count,1);
  assert.equal(renewed.reset_at,now+120000);
 }finally{b.close();a.close();}
});

test('F259: single-use buckets cannot race past their quota',()=>{
 const db=openStore(':memory:');
 try{
  const now=Date.now();
  assert.equal(consumeRateLimit(db,'register:192.0.2.1',1,3600000,now),true);
  assert.equal(consumeRateLimit(db,'register:192.0.2.1',1,3600000,now),false);
  assert.equal(db.prepare("SELECT count AS n FROM rate_limits WHERE key=?").get('register:192.0.2.1').n,1);
  assert.equal(consumeRateLimit(db,'register:192.0.2.1',1,3600000,now+3600001),true);
 }finally{db.close();}
});

test('F259: invalid bucket configuration fails before touching SQLite',()=>{
 const db=openStore(':memory:');
 try{
  for(const args of [
   ['',2,60000,Date.now()],['x',0,60000,Date.now()],
   ['x',1,0,Date.now()],['x',1,60000,NaN],
   ['x',1,Number.MAX_SAFE_INTEGER,Date.now()]
  ])assert.throws(()=>consumeRateLimit(db,...args),TypeError);
  assert.equal(db.prepare('SELECT count(*) n FROM rate_limits').get().n,0);
 }finally{db.close();}
});
