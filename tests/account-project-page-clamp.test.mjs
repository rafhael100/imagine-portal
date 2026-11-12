import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {accountProjectsPage,ACCOUNT_PROJECT_PAGE_SIZE} from '../server/account-projects.mjs';

test('F265: owner archive clamps stale page numbers to the last authorized page',()=>{
 const db=openStore(':memory:');
 createProduction(db);
 try{
  const id=randomUUID(),user={id,role:'client'};
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente','f265@example.test','hash','client','2026-11-11');
  const empty=accountProjectsPage(db,user,{page:'999'});
  assert.deepEqual({page:empty.page,pages:empty.pages,total:empty.total,has_more:empty.has_more},
   {page:1,pages:1,total:0,has_more:false});
  const insert=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,phase,created_at,updated_at) VALUES(?,?,'site',?,'','','','PENDING',?,?,?)");
  for(let i=0;i<ACCOUNT_PROJECT_PAGE_SIZE+1;i++)
   insert.run(randomUUID(),id,'Projeto '+i,i===0?'entregue':'solicitado','2026-11-11','2026-11-11');
  const last=accountProjectsPage(db,user,{page:'999'});
  assert.equal(last.total,9);
  assert.equal(last.page,2);
  assert.equal(last.pages,2);
  assert.equal(last.projects.length,1);
  assert.equal(last.has_more,false);
  const filtered=accountProjectsPage(db,user,{page:'999',phase:'entregue'});
  assert.equal(filtered.total,1);
  assert.equal(filtered.page,1);
  assert.equal(filtered.pages,1);
  assert.equal(filtered.projects.length,1);
  assert.equal(filtered.projects[0].phase,'entregue');
  assert.throws(()=>accountProjectsPage(db,user,{page:'1001'}),e=>e.status===400,
   'the existing hard upper bound is unchanged');
  assert.throws(()=>accountProjectsPage(db,user,{page:'0'}),e=>e.status===400);
 }finally{db.close();}
});
