import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createAccount} from '../server/account.mjs';

test('F277: account views do not read password hash from SQLite, but preserve public identity',()=>{
 const db=openStore(':memory:');
 try{
  const id=randomUUID(),foreign=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)')
   .run(id,'Titular','user-f277@example.test','PRIVATE_PASSWORD_HASH_F277','client','2026-11-11');
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)')
   .run(foreign,'Outro','foreign-f277@example.test','PRIVATE_FOREIGN_HASH_F277','client','2026-11-11');
  let userReads=0;
  const monitored={
   exec:sql=>db.exec(sql),
   prepare(sql){
    const stmt=db.prepare(sql);
    if(!sql.includes('FROM users WHERE id=?'))return stmt;
    assert.match(sql,/^SELECT id,name,email,role,created_at FROM users WHERE id=\?$/);
    return {get:(...params)=>{
     userReads++;
     const u=stmt.get(...params);
     assert.ok(u);
     assert.equal(Object.hasOwn(u,'password_hash'),false);
     assert.deepEqual(Object.keys(u).sort(),['created_at','email','id','name','role']);
     return u;
    }};
   }
  };
  const account=createAccount(monitored,{customer:()=>({})});
  const user={id,role:'client'};
  for(const compact of [false,true]){
   const view=account.view(user,{compact});
   assert.equal(view.user.id,id);
   assert.equal(view.user.name,'Titular');
   assert.equal(view.user.email,'user-f277@example.test');
   assert.equal(view.user.role,'client');
   assert.equal(view.created_at,'2026-11-11');
   const serialized=JSON.stringify(view);
   for(const secret of ['PRIVATE_PASSWORD_HASH_F277','PRIVATE_FOREIGN_HASH_F277'])
    assert.equal(serialized.includes(secret),false);
  }
  assert.equal(userReads,2);
 }finally{db.close();}
});
