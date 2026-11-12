import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {accountProjectsPage} from '../server/account-projects.mjs';

test('F267: unpublished and unpaid delivery metadata is null before leaving SQLite',()=>{
 const db=openStore(':memory:');
 createProduction(db);
 try{
  const owner=randomUUID(),other=randomUUID(),published=randomUUID(),review=randomUUID(),unpaid=randomUUID(),foreign=randomUUID();
  for(const id of [owner,other])db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)')
   .run(id,'Cliente',id+'@example.test','hash','client','2026-11-11');
  const orders=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  orders.run(published,owner,'Publicado','RECEIVED','2026-11-11','2026-11-11');
  orders.run(review,owner,'Em revisão','RECEIVED','2026-11-10','2026-11-10');
  orders.run(unpaid,owner,'Sem pagamento','PENDING','2026-11-09','2026-11-09');
  orders.run(foreign,other,'Outro cliente','RECEIVED','2026-11-12','2026-11-12');
  const production=db.prepare('INSERT INTO productions(order_id,stage,progress,due_date,live_url,updated_at) VALUES(?,?,?,?,?,?)');
  production.run(published,'published',100,'2026-11-12','https://public-site.example.test/','2026-11-11');
  production.run(review,'review',100,'2026-11-13','https://private-preview.example.test/unreleased','2026-11-11');
  production.run(unpaid,'published',100,'2026-11-14','https://unpaid-secret.example.test/','2026-11-11');
  production.run(foreign,'published',100,'2026-11-15','https://foreign-secret.example.test/','2026-11-11');
  let checked=0;
  const wrapped={prepare(sql){
   const stmt=db.prepare(sql);
   return {
    get:(...args)=>stmt.get(...args),
    all:(...args)=>{
     const rows=stmt.all(...args);
     if(sql.includes('AS production_live_url')){
      checked++;
      assert.match(sql,/CASE WHEN .*pr\.stage='published' THEN pr\.live_url ELSE NULL END AS production_live_url/);
      for(const row of rows){
       if(row.id===unpaid){
        assert.equal(row.production_stage,null);
        assert.equal(row.production_progress,null);
        assert.equal(row.production_due_date,null);
        assert.equal(row.production_live_url,null);
       }
       if(row.id===review){
        assert.equal(row.production_stage,'review');
        assert.equal(row.production_live_url,null,'an unpublished URL should never reach Node');
       }
       if(row.id===published)assert.equal(row.production_live_url,'https://public-site.example.test/');
       assert.notEqual(row.id,foreign);
      }
     }
     return rows;
    }
   };
  }};
  const result=accountProjectsPage(wrapped,{id:owner,role:'client'});
  assert.equal(checked,1);
  assert.equal(result.total,3);
  assert.equal(result.projects.find(p=>p.id===unpaid).production,null);
  assert.equal(result.projects.find(p=>p.id===review).production.stage,'review');
  assert.equal(result.projects.find(p=>p.id===published).production.live_url,'https://public-site.example.test/');
  const text=JSON.stringify(result);
  for(const secret of ['private-preview.example.test','unpaid-secret.example.test','foreign-secret.example.test'])
   assert.equal(text.includes(secret),false);
 }finally{db.close();}
});
