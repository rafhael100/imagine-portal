import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createApp} from '../server/app.mjs';
import {createAccount} from '../server/account.mjs';
import {hashPassword,digest} from '../server/security.mjs';
test('Minha conta reúne somente dados próprios, preserva cadastro fiscal e exige senha para trocar e-mail',async t=>{const db=openStore(':memory:'),app=createApp({db,asaas:{configured:false,environment:'sandbox'},publicDir:'.',origin:'http://localhost'}),one=randomUUID(),two=randomUUID(),id=randomUUID(),password='Senha-atual-segura-123!',hash=await hashPassword(password);for(const[user,name,email]of [[one,'Responsável Um','um@example.test'],[two,'Responsável Dois','dois@example.test']])db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(user,name,email,hash,'client','2026-10-31');db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site','Meu projeto','Brief','Escopo','Prazo','RECEIVED','2026-10-31','2026-10-31')").run(id,one);db.prepare("INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,'2026-10-31')").run(id,JSON.stringify({company_name:'Empresa Um',domain:'um.example.test',company_intro:'Apresentação do cliente'}));await new Promise(r=>app.server.listen(0,'127.0.0.1',r));t.after(async()=>{await new Promise(r=>app.server.close(r));db.close();});const base='http://127.0.0.1:'+app.server.address().port,cookies={};for(const user of [one,two]){const raw=randomUUID();db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(raw),user,'csrf',Date.now()+60000);cookies[user]='imagine_session='+raw;}const call=async(user,body)=>{const r=await fetch(base+'/api/account',{method:body?'POST':'GET',headers:{Cookie:cookies[user],Origin:'http://localhost','X-CSRF-Token':'csrf','Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});return {status:r.status,body:await r.json()};};let d=(await call(one)).body;assert.equal(d.projects.length,1);assert.equal(d.projects[0].data.company_name,'Empresa Um');assert.equal((await call(two)).body.projects.length,0);assert.equal(JSON.stringify(d).includes('password_hash'),false);const data={version:0,name:'Nome Atualizado',email:'um@example.test',company:'Empresa Um',whatsapp:'(85) 98765-4321',phone:'',website:'https://empresa.example.test'};assert.equal((await call(one,{...data,email:'novo@example.test'})).status,400);assert.equal((await call(one,{...data,email:'dois@example.test',currentPassword:password})).status,409);d=(await call(one,data)).body;assert.equal(d.profile.company,'Empresa Um');assert.equal(d.user.name,'Nome Atualizado');assert.equal(d.fiscal.document,'');assert.equal((await call(one,data)).status,409);assert.equal((await call(one,{...data,version:1,whatsapp:'11999'})).status,400);assert.equal((await call(one,{...data,version:1,website:'javascript:alert(1)'})).status,400);d=(await call(one,{...data,version:1,email:'novo@example.test',currentPassword:password,role:'admin'})).body;assert.equal(d.user.email,'novo@example.test');assert.equal(d.user.role,'client');db.prepare("UPDATE orders SET payment_status='REFUNDED' WHERE id=?").run(id);assert.deepEqual((await call(one)).body.projects[0].data,{});});

test('F175: alteração de senha concorrente bloqueia troca de e-mail iniciada com credencial antiga',async()=>{
 const db=openStore(':memory:'),id=randomUUID(),name='Responsável',email='original@example.test';
 const current='Senha-atual-segura-123!',previousHash=await hashPassword(current);
 const newHash=await hashPassword('Outra-senha-segura-456!');
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,name,email,previousHash,'client','2026-11-11');
 const raw=randomUUID(),sessionHash=digest(raw);
 db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(sessionHash,id,'csrf',Date.now()+60000);
 const account=createAccount(db,{customer:()=>({document:''})});
 const save=account.save({id,hash:sessionHash},{version:0,name,email:'novo@example.test',company:'',whatsapp:'',phone:'',website:'',currentPassword:current});
 // save() checks the old password asynchronously. Complete a competing
 // password rotation while the KDF is awaiting its result, before BEGIN.
 db.prepare('UPDATE users SET password_hash=? WHERE id=?').run(newHash,id);
 await assert.rejects(save,error=>error.status===409&&/senha foi alterada/.test(error.message));
 const user=db.prepare('SELECT email,password_hash FROM users WHERE id=?').get(id);
 assert.equal(user.email,email);
 assert.equal(user.password_hash,newHash);
 assert.equal(db.prepare('SELECT COUNT(*) AS n FROM account_profiles').get().n,0);
 assert.equal(db.prepare("SELECT COUNT(*) AS n FROM audit WHERE action='account.email.changed'").get().n,0);
 db.close();
});

test('F175: troca legítima de e-mail é auditada, preserva cookie atual e revoga outras sessões',async()=>{
 const db=openStore(':memory:'),id=randomUUID(),name='Responsável',email='original@example.test';
 const current='Senha-atual-segura-123!',previousHash=await hashPassword(current);
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,name,email,previousHash,'client','2026-11-11');
 const currentHash=digest(randomUUID()),otherHash=digest(randomUUID());
 for(const hash of [currentHash,otherHash])db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(hash,id,'csrf',Date.now()+60000);
 const account=createAccount(db,{customer:()=>({document:''})});
 const saved=await account.save({id,hash:currentHash},{version:0,name,email:'novo@example.test',company:'',whatsapp:'',phone:'',website:'',currentPassword:current});
 assert.equal(saved.user.email,'novo@example.test');
 assert.equal(db.prepare('SELECT COUNT(*) AS n FROM sessions WHERE user_id=?').get(id).n,1);
 assert.equal(db.prepare('SELECT hash FROM sessions WHERE user_id=?').get(id).hash,currentHash);
 assert.equal(db.prepare("SELECT COUNT(*) AS n FROM audit WHERE actor_id=? AND action='account.email.changed'").get(id).n,1);
 db.close();
});

test('F196: project archive consistently sorts equal created_at timestamps by id without leaking other owners',async()=>{
 const db=openStore(':memory:'),id=randomUUID(),other=randomUUID(),hash=await hashPassword('Uma-senha-super-segura-123!');
 for(const [u,email] of [[id,'stable@example.test'],[other,'other@example.test']])
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(u,'Responsável',email,hash,'client','2026-11-11');
 const insert=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','','PENDING',?,?)");
 for(const project of ['a','z','m'])
  insert.run(project,id,'Projeto '+project,'2026-11-10T10:00:00Z','2026-11-10T10:00:00Z');
 insert.run('other-secret',other,'Projeto outro','2026-11-11T10:00:00Z','2026-11-11T10:00:00Z');
 const account=createAccount(db,{customer:()=>({document:''})});
 const own=account.view({id,role:'client'});
 assert.deepEqual(own.projects.map(p=>p.id),['z','m','a']);
 assert.equal(JSON.stringify(own).includes('other-secret'),false);
 db.close();
});
