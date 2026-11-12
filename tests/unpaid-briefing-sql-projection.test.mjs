import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {accountProjectsPage} from '../server/account-projects.mjs';
import {iterateOwnerExportProjects} from '../server/owner-project-export.mjs';

test('F263: both account listing and export discard unpaid briefing at SQL boundary',()=>{
 const db=openStore(':memory:');
 createProduction(db);
 try{
  const owner=randomUUID(),other=randomUUID(),paid=randomUUID(),unpaid=randomUUID(),foreign=randomUUID();
  const users=db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)');
  users.run(owner,'Titular','owner-f263@example.test','hash','client','2026-11-11');
  users.run(other,'Outro','foreign-f263@example.test','hash','client','2026-11-11');
  const orders=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  orders.run(paid,owner,'Site pago','RECEIVED','2026-11-11','2026-11-11');
  orders.run(unpaid,owner,'Site não pago','PENDING','2026-11-10','2026-11-10');
  orders.run(foreign,other,'Site de terceiro','RECEIVED','2026-11-12','2026-11-12');
  const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
  brief.run(paid,JSON.stringify({company_name:'Marca própria',password:'INTERNO_NAO_EXPORTADO'}),'2026-11-11');
  brief.run(unpaid,JSON.stringify({company_name:'SEGREDO_NAO_PAGO',notes:'RASCUNHO_NAO_PAGO'}),'2026-11-10');
  brief.run(foreign,JSON.stringify({company_name:'SEGREDO_OUTRO_TITULAR'}),'2026-11-12');
  let pageQueries=0,exportQueries=0,unpaidNull=0;
  const tracked={prepare(sql){
   const statement=db.prepare(sql);
   const guard=(row)=>{
    if(row.payment_status==='PENDING'){
     assert.equal(row.data,null,'unpaid briefing must be NULL even at the SQLite result boundary');
     unpaidNull++;
    }
    return row;
   };
   return {
    get:(...p)=>statement.get(...p),
    all:(...p)=>{
     const result=statement.all(...p);
     if(sql.includes('pr.stage AS production_stage')){
      pageQueries++;
      assert.match(sql,/CASE WHEN o\.payment_status IN .* THEN b\.data ELSE NULL END AS data/);
      result.forEach(guard);
     }
     return result;
    },
    iterate:(...p)=>{
     const values=statement.iterate(...p);
     if(sql.includes('LEFT JOIN briefings')){
      exportQueries++;
      assert.match(sql,/CASE WHEN o\.payment_status IN .* THEN b\.data ELSE NULL END AS data/);
      return (function*(){for(const row of values)yield guard(row);})();
     }
     return values;
    }
   };
  }};
  const user={id:owner,role:'client'};
  const view=accountProjectsPage(tracked,user);
  assert.equal(view.total,2);
  assert.deepEqual(view.projects.find(p=>p.id===unpaid).data,{});
  assert.equal(view.projects.find(p=>p.id===paid).data.company_name,'Marca própria');
  const exportRows=[...iterateOwnerExportProjects(tracked,user)];
  assert.equal(exportRows.length,2);
  assert.deepEqual(exportRows.find(p=>p.id===unpaid).data,{});
  assert.equal(exportRows.find(p=>p.id===paid).data.company_name,'Marca própria');
  assert.equal(pageQueries,1);
  assert.equal(exportQueries,1);
  assert.equal(unpaidNull,2);
  const serialized=JSON.stringify({view,exportRows});
  for(const value of ['SEGREDO_NAO_PAGO','RASCUNHO_NAO_PAGO','SEGREDO_OUTRO_TITULAR','INTERNO_NAO_EXPORTADO'])
   assert.equal(serialized.includes(value),false,value);
 }finally{db.close();}
});
