import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

test('F272: full account view fails closed for invalid own paid briefing without affecting compact view or other owner',async()=>{
 const db=openStore(':memory:'),a=randomUUID(),b=randomUUID(),paid=randomUUID(),unpaid=randomUUID(),foreign=randomUUID(),tokens=[randomUUID(),randomUUID()];
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  for(const [id,email] of [[a,'a-f272@example.test'],[b,'b-f272@example.test']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente',email,'hash','client','2026-11-11');
  const insert=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  insert.run(paid,a,'Projeto do titular A','RECEIVED','2026-11-11','2026-11-11');
  insert.run(unpaid,a,'Rascunho não pago A','PENDING','2026-11-10','2026-11-10');
  insert.run(foreign,b,'Projeto do titular B','RECEIVED','2026-11-12','2026-11-12');
  const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
  brief.run(paid,'{"wrong_shape":', '2026-11-11');
  brief.run(unpaid,'{"company_name":"PRIVATE_UNPAID_F272"}','2026-11-10');
  brief.run(foreign,'{"company_name":"Empresa permitida B","private_password":"SECRET_F272"}','2026-11-12');
  for(const [i,id] of [a,b].entries())
   db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(tokens[i]),id,'csrf',Date.now()+60000);
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+app.server.address().port;
  const request=(path,i)=>fetch(base+path,{headers:{Cookie:'imagine_session='+tokens[i]}});
  assert.equal((await fetch(base+'/api/account')).status,401);
  const invalid=await request('/api/account',0);
  assert.equal(invalid.status,503);
  const message=await invalid.text();
  assert.ok(message.includes('temporariamente indisponíveis'));
  assert.equal(message.includes('wrong_shape'),false);
  const compact=await request('/api/account?compact=1',0);
  assert.equal(compact.status,200,'compact contact suggestions skip invalid paid briefings');
  const compactJson=await compact.json();
  assert.deepEqual(compactJson.projects,[]);
  assert.equal(compactJson.projects_total,2);
  assert.equal(JSON.stringify(compactJson).includes('PRIVATE_UNPAID_F272'),false);
  const unaffected=await request('/api/account',1);
  assert.equal(unaffected.status,200);
  const data=await unaffected.json();
  assert.equal(data.projects.length,1);
  assert.equal(data.projects[0].data.company_name,'Empresa permitida B');
  assert.equal(JSON.stringify(data).includes('SECRET_F272'),false);
  // A valid JSON scalar also fails, rather than looking like a complete export.
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?').run('"not-an-object"',paid);
  assert.equal((await request('/api/account',0)).status,503);
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?')
   .run('{"company_name":"Empresa permitida A","secret":"PRIVATE_PAID_F272"}',paid);
  const repaired=await request('/api/account',0);
  assert.equal(repaired.status,200);
  const output=await repaired.text();
  assert.ok(output.includes('Empresa permitida A'));
  for(const value of ['PRIVATE_UNPAID_F272','PRIVATE_PAID_F272','Empresa permitida B'])
   assert.equal(output.includes(value),false);
 }finally{
  await new Promise(resolve=>app.server.listening?app.server.close(resolve):resolve());
  db.close();
 }
});
