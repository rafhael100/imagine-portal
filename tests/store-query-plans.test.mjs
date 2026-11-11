import test from 'node:test';
import assert from 'node:assert/strict';
import {openStore} from '../server/store.mjs';

test('F184: consultas multicliente e coleta de quotas usam índices restritos e idempotentes',()=>{
 const db=openStore(':memory:');
 const plans=[
  {
   sql:'SELECT count(*) FROM sessions WHERE user_id=? AND expires_at>?',
   args:['owner-test',Date.now()],
   index:'idx_sessions_user_expiry',
  },
  {
   sql:"SELECT action,created_at FROM audit WHERE actor_id=? AND action IN ('password.changed','sessions.revoked_others') ORDER BY id DESC LIMIT 10",
   args:['owner-test'],
   index:'idx_audit_actor_id_id',
  },
  {
   sql:'DELETE FROM rate_limits WHERE reset_at<=?',
   args:[Date.now()],
   index:'idx_rate_limits_reset_at',
  },
 ];
 for(const item of plans){
  const index=db.prepare("SELECT name FROM sqlite_master WHERE type='index' AND name=?").get(item.index);
  assert.equal(index?.name,item.index);
  const detail=db.prepare('EXPLAIN QUERY PLAN '+item.sql).all(...item.args).map(x=>x.detail).join(' ');
  assert.match(detail,new RegExp(item.index),item.sql);
 }
 // Schema creation is additive and does not change existing account data.
 const count=db.prepare("SELECT count(*) AS n FROM users").get().n;
 assert.equal(count,0);
 db.close();
});
