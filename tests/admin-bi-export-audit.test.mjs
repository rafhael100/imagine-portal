import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

async function fixture(t){
 const db=openStore(':memory:'),admin=randomUUID(),client=randomUUID(),token=randomUUID();
 for(const [id,role] of [[admin,'admin'],[client,'client']])
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,role,id+'@example.test','hash',role,'2026-11-01');
 db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(token),admin,'csrf',Date.now()+3600000);
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
 t.after(async()=>{await new Promise(resolve=>app.server.close(resolve));db.close();});
 const base='http://127.0.0.1:'+app.server.address().port;
 const history=()=>db.prepare("SELECT action,resource_id FROM audit WHERE actor_id=? AND action LIKE 'admin.tasks_csv_export_%' ORDER BY id")
  .all(admin).map(({action,resource_id})=>({action,resource_id}));
 return {db,app,admin,token,base,history};
}

test('F260: successful BI CSV is audited as requested and fully transferred once',async t=>{
 const f=await fixture(t);
 const denied=await fetch(f.base+'/api/admin/tasks-bi.csv');
 assert.equal(denied.status,401);
 assert.deepEqual(f.history(),[]);
 const response=await fetch(f.base+'/api/admin/tasks-bi.csv',{headers:{Cookie:'imagine_session='+f.token}});
 assert.equal(response.status,200);
 assert.match(response.headers.get('cache-control')||'',/no-store/);
 assert.match(response.headers.get('content-disposition')||'',/atividades-imagine.csv/);
 const csv=await response.text();
 assert.ok(csv.startsWith('"ID";')||csv.startsWith('\uFEFF"ID";'));
 assert.deepEqual(f.history(),[
  {action:'admin.tasks_csv_export_requested',resource_id:f.admin},
  {action:'admin.tasks_csv_exported',resource_id:f.admin}
 ]);
 assert.equal((await fetch(f.base+'/api/admin/tasks-bi',{headers:{Cookie:'imagine_session='+f.token}})).status,200);
 assert.equal(f.history().length,2,'JSON dashboard reads must not count as CSV exports');
});

test('F260: broken BI CSV leaves requested but no completed audit record',async t=>{
 const f=await fixture(t);
 let broken=0;
 const hook=(req,res)=>{
  if(req.url!=='/api/admin/tasks-bi.csv')return;
  const write=res.write;
  res.write=function(...args){
   const result=write.apply(this,args);
   if(!broken){broken++;this.destroy();}
   return result;
  };
 };
 f.app.server.prependListener('request',hook);
 t.after(()=>f.app.server.off('request',hook));
 try{
  const response=await fetch(f.base+'/api/admin/tasks-bi.csv',{headers:{Cookie:'imagine_session='+f.token}});
  await response.text();
 }catch{/* deliberate disconnect */}
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(broken,1);
 assert.deepEqual(f.history(),[{action:'admin.tasks_csv_export_requested',resource_id:f.admin}]);
 assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM account_export_leases WHERE user_id=?').get(f.admin).n,0);
});
