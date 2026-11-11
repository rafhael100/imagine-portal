import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {hashPassword,digest} from '../server/security.mjs';
import {revokeOtherSessionsAtomically,changePasswordAtomically} from '../server/app.mjs';

test('F191: password rotated after async verification cannot revoke fresh sessions',async()=>{
 const db=openStore(':memory:');
 const id=randomUUID(),old=await hashPassword('Senha-antiga-completa-12345!'),next=await hashPassword('Senha-nova-completa-54321!');
 const currentHash=digest(randomUUID()),previous=digest(randomUUID()),fresh=digest(randomUUID());
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente','owner@example.test',old,'client','2026-11-11');
 for(const hash of [currentHash,previous])
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(hash,id,'csrf',Date.now()+60000);
 // Simulates the exact TOCTOU gap between await verifyPassword(old hash)
 // and the revocation transaction: the new password and session now win.
 db.prepare('UPDATE users SET password_hash=? WHERE id=?').run(next,id);
 db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(fresh,id,'new-csrf',Date.now()+60000);
 assert.throws(()=>revokeOtherSessionsAtomically(db,{id,hash:currentHash,password_hash:old}),e=>e.status===409&&/senha/.test(e.message));
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(id).n,3);
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE hash=?').get(fresh).n,1);
 // A revoked current session cannot authorize deletion even with unchanged password.
 db.prepare('DELETE FROM sessions WHERE hash=?').run(currentHash);
 assert.throws(()=>revokeOtherSessionsAtomically(db,{id,hash:currentHash,password_hash:next}),e=>e.status===409&&/sessão/.test(e.message));
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(id).n,2);
 db.close();
});

test('F191: valid current session revokes only other active sessions of same owner',async()=>{
 const db=openStore(':memory:');
 const id=randomUUID(),other=randomUUID(),password=await hashPassword('Senha-de-revogacao-segura-123!');
 for(const [user,email] of [[id,'first@example.test'],[other,'second@example.test']])
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(user,'Cliente',email,password,'client','2026-11-11');
 const current=digest(randomUUID()),otherActive=digest(randomUUID()),expired=digest(randomUUID()),foreign=digest(randomUUID());
 for(const [hash,user,expires] of [[current,id,Date.now()+60000],[otherActive,id,Date.now()+60000],[expired,id,Date.now()-1000],[foreign,other,Date.now()+60000]])
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(hash,user,'csrf',expires);
 const n=revokeOtherSessionsAtomically(db,{id,hash:current,password_hash:password});
 assert.equal(n,1);
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(id).n,1);
 assert.equal(db.prepare('SELECT hash FROM sessions WHERE user_id=?').get(id).hash,current);
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(other).n,1);
 db.close();
});

test('F195: in-flight password change cannot reauthorize a session revoked by logout',async()=>{
 const db=openStore(':memory:');
 const id=randomUUID(),old=await hashPassword('Senha-antiga-suficiente-123!'),next=await hashPassword('Senha-nova-suficiente-456!');
 const cookie=digest(randomUUID()),other=digest(randomUUID());
 db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Responsável','logout@example.test',old,'client','2026-11-11');
 for(const h of [cookie,other])db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(h,id,'csrf',Date.now()+60000);
 // Captured at request authentication, immediately before asynchronous KDF.
 const staleUser={id,hash:cookie,password_hash:old};
 db.prepare('DELETE FROM sessions WHERE hash=?').run(cookie);
 assert.throws(()=>changePasswordAtomically(db,staleUser,next),e=>e.status===409&&/sessão/.test(e.message));
 assert.equal(db.prepare('SELECT password_hash FROM users WHERE id=?').get(id).password_hash,old);
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(id).n,1);
 assert.equal(db.prepare('SELECT hash FROM sessions WHERE user_id=?').get(id).hash,other);
 db.close();
});

test('F195: valid current session rotates password and removes its own older sessions only',async()=>{
 const db=openStore(':memory:');
 const id=randomUUID(),otherId=randomUUID();
 const old=await hashPassword('Senha-original-muito-segura-123!'),next=await hashPassword('Senha-diferente-muito-segura-456!');
 for(const [user,email] of [[id,'account@example.test'],[otherId,'other@example.test']])
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(user,'Responsável',email,old,'client','2026-11-11');
 const current=digest(randomUUID()),second=digest(randomUUID()),foreign=digest(randomUUID());
 for(const [hash,user] of [[current,id],[second,id],[foreign,otherId]])
  db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run(hash,user,'csrf',Date.now()+60000);
 changePasswordAtomically(db,{id,hash:current,password_hash:old},next);
 assert.equal(db.prepare('SELECT password_hash FROM users WHERE id=?').get(id).password_hash,next);
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(id).n,0);
 assert.equal(db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(otherId).n,1);
 assert.throws(()=>changePasswordAtomically(db,{id,hash:current,password_hash:old},next),e=>e.status===409);
 db.close();
});
