import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

test('F258: admin BI JSON has its own one-minute rate limit with tenant isolation',async()=>{
 const db=openStore(':memory:');
 const ids=[randomUUID(),randomUUID(),randomUUID()],tokens=[randomUUID(),randomUUID(),randomUUID()];
 const roles=['admin','admin','client'];
 for(let i=0;i<ids.length;i++){
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(ids[i],'Pessoa '+i,ids[i]+'@example.test','hash',roles[i],'2026-11-01');
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(tokens[i]),ids[i],'csrf',Date.now()+3600000);
 }
 const reset=Date.now()+60000;
 db.prepare('INSERT INTO rate_limits(key,count,reset_at) VALUES(?,?,?)').run('admin-bi-json:'+ids[0],119,reset);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+app.server.address().port;
  const request=(path,i)=>fetch(base+path,{headers:{Cookie:'imagine_session='+tokens[i]}});
  assert.equal((await request('/api/admin/tasks-bi',2)).status,403);
  assert.equal((await request('/api/admin/tasks-bi',0)).status,200);
  assert.equal((await request('/api/admin/tasks-bi',0)).status,429);
  assert.equal((await request('/api/admin/tasks-bi?from=bad-date',0)).status,429,
   'the BI aggregation is throttled before expensive validation/query');
  assert.equal((await request('/api/admin/tasks-bi',1)).status,200,
   'a different admin has an independent BI budget');
  assert.equal((await request('/api/admin/tasks-bi.csv',0)).status,200,
   'the CSV route uses a separate download quota and lease');
  const bucket=db.prepare('SELECT count,reset_at FROM rate_limits WHERE key=?').get('admin-bi-json:'+ids[0]);
  assert.equal(bucket.count,120);
  assert.equal(bucket.reset_at,reset);
 }finally{
  await new Promise(resolve=>app.server.close(resolve));
  db.close();
 }
});
