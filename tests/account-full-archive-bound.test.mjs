import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createAccount} from '../server/account.mjs';
import {ACCOUNT_PROJECT_PAGE_SIZE,ACCOUNT_PROJECT_MAX_PAGE} from '../server/account-projects.mjs';

test('F279: large legacy full-account reads fail before loading the unbounded project archive',()=>{
 const db=openStore(':memory:');
 try{
  const id=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Titular','f279@example.test','SECRET_HASH','client','2026-11-11');
  const max=ACCOUNT_PROJECT_PAGE_SIZE*ACCOUNT_PROJECT_MAX_PAGE;
  assert.equal(max,8000);
  let count=max+1,counts=0,projectsRead=0;
  const monitored={
   exec:sql=>db.exec(sql),
   prepare(sql){
    if(sql==='SELECT COUNT(*) AS n FROM orders WHERE user_id=?')
     return {get:(owner)=>{assert.equal(owner,id);counts++;return {n:count};}};
    if(sql.includes('FROM orders o LEFT JOIN briefings b')){
     const query=db.prepare(sql);
     return {all:(...args)=>{projectsRead++;return query.all(...args);}};
    }
    return db.prepare(sql);
   }
  };
  const account=createAccount(monitored,{customer:()=>({})});
  const user={id,role:'client'};
  assert.throws(()=>account.view(user,{compact:false}),
   e=>e.status===413&&/consulta paginada ou exporte seus projetos/.test(e.message));
  assert.equal(projectsRead,0,'SQL must reject the large legacy response before archive reads');
  assert.equal(counts,1);
  const compact=account.view(user,{compact:true});
  assert.equal(compact.projects_total,0);
  assert.deepEqual(compact.projects,[]);
  assert.equal(projectsRead,1,'compact mode remains usable');
  assert.equal(counts,1,'compact mode does not need an extra COUNT');
  count=max;
  const capped=account.view(user,{compact:false});
  assert.ok(Array.isArray(capped.projects));
  assert.equal(counts,2);
  assert.equal(projectsRead,2,'the existing upper boundary stays accepted');
 }finally{db.close();}
});

test('F279: real small account maintains the legacy full-account contract',()=>{
 const db=openStore(':memory:');
 try{
  const id=randomUUID(),project=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente','small-f279@example.test','hash','client','2026-11-11');
  db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site','Projeto normal','','','','PENDING','2026-11-11','2026-11-11')").run(project,id);
  const account=createAccount(db,{customer:()=>({})});
  const full=account.view({id,role:'client'},{compact:false});
  assert.equal(full.projects.length,1);
  assert.equal(full.projects[0].id,project);
  assert.deepEqual(full.projects[0].data,{});
 }finally{db.close();}
});
