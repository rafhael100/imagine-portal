import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

async function fixture(t){
 const db=openStore(':memory:');
 const admin=randomUUID(),client=randomUUID(),order=randomUUID(),payment=randomUUID(),token=randomUUID();
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(admin,'Admin','admin-audit@example.test','hash','admin','2026-10-01');
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(client,'Cliente','client-audit@example.test','hash','client','2026-10-01');
 db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,created_at,updated_at) VALUES(?,?,'site','Site','Brief','Escopo','7 dias','2026-10-01','2026-10-01')").run(order,client);
 db.prepare("INSERT INTO payments(id,order_id,kind,status,value_cents,updated_at) VALUES(?,?,'site','PENDING',19900,'2026-10-01')").run(payment,order);
 db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(token),admin,'csrf',Date.now()+60000);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
 t.after(async()=>{await new Promise(resolve=>app.server.close(resolve));db.close();});
 const base='http://127.0.0.1:'+app.server.address().port;
 // node:sqlite returns null-prototype row objects; normalize for strict tests.
 const history=()=>db.prepare("SELECT action,resource_id FROM audit WHERE actor_id=? AND action LIKE 'admin.finance_csv_export_%' ORDER BY id")
  .all(admin).map(({action,resource_id})=>({action,resource_id}));
 return {app,db,admin,token,base,history};
}

test('F253: only successful admin finance downloads have requested and completed audit entries',async t=>{
 const f=await fixture(t);
 assert.equal((await fetch(f.base+'/api/admin/finance.csv')).status,401);
 assert.deepEqual(f.history(),[],'anonymous traffic must not be audited as authorized');
 const response=await fetch(f.base+'/api/admin/finance.csv',{headers:{Cookie:'imagine_session='+f.token}});
 assert.equal(response.status,200);
 const csv=await response.text();
 assert.ok(csv.includes('199.00'));
 assert.deepEqual(f.history(),[
  {action:'admin.finance_csv_export_requested',resource_id:f.admin},
  {action:'admin.finance_csv_exported',resource_id:f.admin}
 ]);
 const auditText=JSON.stringify(f.history());
 for(const privateValue of ['client-audit@example.test','199.00','imagine_session'])
  assert.equal(auditText.includes(privateValue),false,'audit should record metadata only');
});

test('F253: disconnected admin finance download records request but not completion',async t=>{
 const f=await fixture(t);
 let interrupted=0;
 const hook=(req,res)=>{
  if(req.url!=='/api/admin/finance.csv')return;
  const write=res.write;
  res.write=function(...args){
   const result=write.apply(this,args);
   if(!interrupted){interrupted++;this.destroy();}
   return result;
  };
 };
 f.app.server.prependListener('request',hook);
 t.after(()=>f.app.server.off('request',hook));
 try{
  const response=await fetch(f.base+'/api/admin/finance.csv',{headers:{Cookie:'imagine_session='+f.token}});
  await response.text();
 }catch{
  // Premature close is deliberate; it must not count as a completed export.
 }
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(interrupted,1);
 assert.deepEqual(f.history(),[{action:'admin.finance_csv_export_requested',resource_id:f.admin}]);
});
