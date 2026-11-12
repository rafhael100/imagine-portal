import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

test('F254: 20 CSV gerenciais/hora por admin, compartilhados entre financeiro e atividades',async()=>{
 const db=openStore(':memory:');
 const admin=randomUUID(),other=randomUUID(),tokens=[randomUUID(),randomUUID()];
 for(const [id,name] of [[admin,'Admin A'],[other,'Admin B']]){
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,name,id+'@example.test','hash','admin','2026-11-01');
 }
 for(const [i,id] of [admin,other].entries())
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(tokens[i]),id,'csrf',Date.now()+3600000);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const url='http://127.0.0.1:'+app.server.address().port;
  const request=(path,token)=>fetch(url+path,{headers:{Cookie:'imagine_session='+token}});
  const routes=['/api/admin/finance.csv','/api/admin/tasks-bi.csv'];
  assert.equal((await request(routes[0],null)).status,401);
  for(let i=0;i<20;i++){
   const response=await request(routes[i%2],tokens[0]);
   assert.equal(response.status,200,'authorized export '+i);
   await response.arrayBuffer();
  }
  for(const route of routes){
   const blocked=await request(route,tokens[0]);
   assert.equal(blocked.status,429,route);
  }
  assert.equal((await request('/api/admin/tasks-bi',tokens[0])).status,200,
   'the regular BI JSON view is not a file download');
  assert.equal((await request(routes[0],tokens[1])).status,200,
   'one administrator must not consume another administrator quota');
  const row=db.prepare("SELECT count,reset_at FROM rate_limits WHERE key=?").get('admin-csv:'+admin);
  assert.equal(row.count,20);
  assert.ok(row.reset_at>Date.now());
  const audit=db.prepare("SELECT COUNT(*) AS n FROM audit WHERE actor_id=? AND action='admin.finance_csv_export_requested'").get(admin).n;
  assert.equal(audit,10,'blocked requests do not produce authorized download audit records');
 }finally{
  await new Promise(resolve=>app.server.close(resolve));
  db.close();
 }
});
