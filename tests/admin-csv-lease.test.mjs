import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';
import {acquireOwnerExportLease,releaseOwnerExportLease} from '../server/owner-project-export.mjs';

test('F256: finance and tasks CSV share one SQLite lease per administrator',async()=>{
 const db=openStore(':memory:');
 const owner=randomUUID(),other=randomUUID(),client=randomUUID();
 const tokens=[randomUUID(),randomUUID(),randomUUID()];
 for(const [id,role] of [[owner,'admin'],[other,'admin'],[client,'client']]){
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,role,id+'@example.test','hash',role,'2026-11-01');
 }
 for(const [i,id] of [owner,other,client].entries())
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(tokens[i]),id,'csrf',Date.now()+60000);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+app.server.address().port;
  const request=(route,i)=>fetch(base+route,{headers:{Cookie:'imagine_session='+tokens[i]}});
  const paths=['/api/admin/finance.csv','/api/admin/tasks-bi.csv'];
  const lease=acquireOwnerExportLease(db,owner);
  for(const path of paths){
   const blocked=await request(path,0);
   assert.equal(blocked.status,409,'another admin export already owns the lease');
   assert.equal((await request(path,2)).status,403,'a normal client cannot export admin data');
  }
  assert.equal(db.prepare("SELECT count(*) n FROM rate_limits WHERE key=?").get('admin-csv:'+owner),undefined,
   'concurrency conflicts do not consume the hourly CSV quota');
  assert.equal(db.prepare("SELECT count(*) n FROM audit WHERE actor_id=? AND action='admin.finance_csv_export_requested'").get(owner).n,0);
  const allowed=await request('/api/admin/finance.csv',1);
  assert.equal(allowed.status,200,'a different admin can export in parallel');
  await allowed.text();
  releaseOwnerExportLease(db,owner,lease);
  for(const path of paths){
   const success=await request(path,0);
   assert.equal(success.status,200);
   await success.text();
   assert.equal(db.prepare("SELECT count(*) n FROM account_export_leases WHERE user_id=?").get(owner).n,0,
    'lease is always released after a finished transfer');
  }
 }finally{
  await new Promise(resolve=>app.server.close(resolve));
  db.close();
 }
});

test('F256: a stale release cannot erase a newer administrative lease',()=>{
 const db=openStore(':memory:');
 try{
  const admin=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(admin,'Admin','lease-f256@example.test','hash','admin','2026-11-01');
  const a=acquireOwnerExportLease(db,admin),record=db.prepare('SELECT token FROM account_export_leases WHERE user_id=?');
  assert.equal(record.get(admin).token,a);
  releaseOwnerExportLease(db,admin,a);
  const b=acquireOwnerExportLease(db,admin);
  releaseOwnerExportLease(db,admin,a);
  assert.equal(record.get(admin).token,b);
  releaseOwnerExportLease(db,admin,b);
 }finally{db.close();}
});
