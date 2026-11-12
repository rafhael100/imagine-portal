import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createAccount} from '../server/account.mjs';

test('F273: full and compact self-service account never fetch unpaid briefing payloads from SQLite',()=>{
 const db=openStore(':memory:');
 try{
  const owner=randomUUID(),other=randomUUID(),paid=randomUUID(),unpaid=randomUUID(),foreign=randomUUID();
  for(const [id,mail] of [[owner,'owner-f273@example.test'],[other,'other-f273@example.test']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Titular',mail,'hash','client','2026-11-11');
  const orders=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  orders.run(paid,owner,'Meu site pago','RECEIVED','2026-11-11','2026-11-11');
  orders.run(unpaid,owner,'Meu site pendente','PENDING','2026-11-10','2026-11-10');
  orders.run(foreign,other,'Site particular de outro titular','RECEIVED','2026-11-12','2026-11-12');
  const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
  brief.run(paid,'{"company_name":"Minha marca","secret":"PRIVATE_PAID_F273"}','2026-11-11');
  brief.run(unpaid,'{"company_name":"PRIVATE_UNPAID_F273","password":"PRIVATE_PASS_F273"}','2026-11-10');
  brief.run(foreign,'{"company_name":"PRIVATE_FOREIGN_F273"}','2026-11-12');
  let queries=0,unpaidRows=0;
  const monitored={
   exec:sql=>db.exec(sql),
   prepare(sql){
    const statement=db.prepare(sql);
    if(!sql.includes('FROM orders o LEFT JOIN briefings b'))return statement;
    return {all:(...params)=>{
     queries++;
     assert.match(sql,/CASE WHEN o\.payment_status IN \('CONFIRMED','RECEIVED','RECEIVED_IN_CASH'\) THEN b\.data ELSE NULL END AS data/);
     const rows=statement.all(...params);
     for(const row of rows){
      if(row.payment_status==='PENDING'){unpaidRows++;assert.equal(row.data,null);}
      assert.notEqual(row.id,foreign,'foreign orders must be filtered by owner ID');
     }
     return rows;
    }};
   }
  };
  const account=createAccount(monitored,{customer:()=>({})});
  const user={id:owner,role:'client'};
  const full=account.view(user,{compact:false});
  assert.equal(full.projects.length,2);
  assert.equal(full.projects.find(p=>p.id===paid).data.company_name,'Minha marca');
  assert.deepEqual(full.projects.find(p=>p.id===unpaid).data,{});
  assert.equal(unpaidRows,1);
  const compact=account.view(user,{compact:true});
  assert.equal(compact.projects_total,2);
  assert.equal(compact.projects.length,1);
  assert.equal(compact.projects[0].data.company_name,'Minha marca');
  assert.equal(queries,2);
  const serialized=JSON.stringify({full,compact});
  for(const secret of ['PRIVATE_PAID_F273','PRIVATE_UNPAID_F273','PRIVATE_PASS_F273','PRIVATE_FOREIGN_F273'])
   assert.equal(serialized.includes(secret),false,secret);
 }finally{db.close();}
});
