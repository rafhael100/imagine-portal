import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createAccount,safeAccountProfile} from '../server/account.mjs';
import {createApp} from '../server/app.mjs';
import {digest} from '../server/security.mjs';

test('F274: corrupted stored account profiles fail closed instead of masquerading as empty',()=>{
 for(const raw of ['{PRIVATE_CORRUPT_PROFILE','null','[]','"wrong shape"','123','false']){
  assert.throws(()=>safeAccountProfile({data:raw,version:1,updated_at:'2026-11-11'}),
   error=>error.status===503&&error.message==='Dados cadastrais temporariamente indisponíveis.',
   raw);
 }
 const empty=safeAccountProfile(null);
 assert.equal(empty.version,0);
 assert.deepEqual(Object.keys(empty).sort(),['company','phone','version','website','whatsapp']);
 const restored=safeAccountProfile({data:'{"company":"Minha empresa","private_key":"NEVER_PUBLIC_F274"}',version:2,updated_at:'2026-11-11'});
 assert.equal(restored.company,'Minha empresa');
 assert.equal(Object.hasOwn(restored,'private_key'),false);
 assert.equal(restored.version,2);
});

test('F274: corrupt profile returns HTTP 503 without disclosure and blocks silent overwrites',async()=>{
 const db=openStore(':memory:'),userId=randomUUID(),other=randomUUID(),token=randomUUID();
 const app=createApp({db,asaas:{configured:false,environment:'sandbox'},origin:'http://localhost',publicDir:'.'});
 try{
  for(const [id,email] of [[userId,'primary-f274@example.test'],[other,'other-f274@example.test']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente',email,'hash','client','2026-11-11');
  db.prepare('INSERT INTO account_profiles VALUES(?,?,?,?)')
   .run(userId,'{PRIVATE_PERSISTED_PROFILE_F274',7,'2026-11-11');
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(digest(token),userId,'csrf',Date.now()+60000);
  await new Promise(resolve=>app.server.listen(0,'127.0.0.1',resolve));
  const base='http://127.0.0.1:'+app.server.address().port;
  const full=await fetch(base+'/api/account',{headers:{Cookie:'imagine_session='+token}});
  assert.equal(full.status,503);
  const error=await full.text();
  assert.ok(error.includes('Dados cadastrais temporariamente indisponíveis'));
  assert.equal(error.includes('PRIVATE_PERSISTED_PROFILE_F274'),false);
  assert.equal((await fetch(base+'/api/account?compact=1',{headers:{Cookie:'imagine_session='+token}})).status,503);
  const account=createAccount(db,{customer:()=>({})});
  await assert.rejects(account.save({id:userId,role:'client'},{version:7}),
   e=>e.status===503&&/Dados cadastrais/.test(e.message));
  const profile=db.prepare('SELECT data,version FROM account_profiles WHERE user_id=?').get(userId);
  assert.equal(profile.version,7);
  assert.equal(profile.data,'{PRIVATE_PERSISTED_PROFILE_F274');
  assert.equal(db.prepare('SELECT COUNT(*) AS n FROM audit WHERE actor_id=?').get(userId).n,0);
  db.prepare('UPDATE account_profiles SET data=? WHERE user_id=?')
   .run('{"company":"Empresa recuperada","internal_secret":"DO_NOT_EXPORT_F274"}',userId);
  const response=await fetch(base+'/api/account?compact=1',{headers:{Cookie:'imagine_session='+token}});
  assert.equal(response.status,200);
  const body=await response.text();
  assert.ok(body.includes('Empresa recuperada'));
  assert.equal(body.includes('DO_NOT_EXPORT_F274'),false);
 }finally{
  await new Promise(resolve=>app.server.listening?app.server.close(resolve):resolve());
  db.close();
 }
});
