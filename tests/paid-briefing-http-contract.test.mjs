import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

test('F270: malformed paid briefing blocks all three owner HTTP exports, not another owner',async()=>{
 const db=openStore(':memory:');
 const a=randomUUID(),b=randomUUID(),aOrder=randomUUID(),bOrder=randomUUID(),tokens=[randomUUID(),randomUUID()];
 for(const [id,mail] of [[a,'a-f270@example.test'],[b,'b-f270@example.test']])
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente',mail,'hash','client','2026-11-11');
 const orders=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','','RECEIVED','2026-11-11','2026-11-11')");
 orders.run(aOrder,a,'Projeto do titular A');
 orders.run(bOrder,b,'Projeto do titular B');
 const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
 brief.run(aOrder,'["JSON_INVALID_TYPE_PRIVATE_F270"]','2026-11-11');
 brief.run(bOrder,'{"company_name":"Empresa autorizada B"}','2026-11-11');
 for(const [i,id] of [a,b].entries())
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(tokens[i]),id,'csrf',Date.now()+3600000);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+app.server.address().port;
  const get=(path,index)=>fetch(base+path,{headers:{Cookie:'imagine_session='+tokens[index]}});
  const endpoints=['/api/account/projects','/api/account/projects.csv','/api/account/export'];
  for(const endpoint of endpoints){
   const invalid=await get(endpoint,0);
   assert.equal(invalid.status,503,'owner A: '+endpoint);
   assert.match(invalid.headers.get('content-type')||'',/application\/json/);
   const err=await invalid.text();
   assert.ok(err.includes('temporariamente indisponíveis'));
   assert.equal(err.includes('JSON_INVALID_TYPE_PRIVATE_F270'),false,'never disclose the malformed stored value');
   const other=await get(endpoint,1);
   assert.equal(other.status,200,'owner B must not be blocked by other account data: '+endpoint);
   const text=await other.text();
   assert.equal(text.includes('Projeto do titular A'),false);
   assert.equal(text.includes('JSON_INVALID_TYPE_PRIVATE_F270'),false);
  }
  assert.equal(db.prepare('SELECT count(*) AS n FROM account_export_leases WHERE user_id=?').get(a).n,0);
  assert.equal(db.prepare("SELECT count(*) AS n FROM audit WHERE actor_id=? AND action IN ('account.data_exported','account.projects_csv_exported')").get(a).n,0);
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?')
   .run('{"company_name":"Empresa autorizada A","private_key":"DO_NOT_EXPORT_F270"}',aOrder);
  for(const endpoint of endpoints){
   const recovered=await get(endpoint,0);
   assert.equal(recovered.status,200,'repaired owner A: '+endpoint);
   const output=await recovered.text();
   assert.equal(output.includes('DO_NOT_EXPORT_F270'),false);
  }
 }finally{
  await new Promise(resolve=>app.server.close(resolve));
  db.close();
 }
});
