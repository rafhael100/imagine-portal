import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {openStore} from '../server/store.mjs';
import {createProduction} from '../server/production.mjs';
import {parsePaidBriefingData} from '../server/account-export.mjs';
import {accountProjectsPage} from '../server/account-projects.mjs';
import {preflightOwnerProjectExport,iterateOwnerExportProjects} from '../server/owner-project-export.mjs';

test('F264: valid JSON scalars and arrays are rejected rather than silently omitted',()=>{
 for(const input of ['null','[]','[{"company_name":"wrong"}]','"empresa"','123','false','{invalid']){
  assert.throws(()=>parsePaidBriefingData(input),e=>
   e.status===503&&e.message==='Dados de projeto temporariamente indisponíveis para exportação.',
   input);
 }
 assert.deepEqual(parsePaidBriefingData(''),{});
 const data=parsePaidBriefingData('{"company_name":"Marca","api_secret":"NEVER_EXPORT"}');
 assert.deepEqual(data,{company_name:'Marca'});
});

test('F264: paid briefing preflight, paging and streaming agree on object-only contract',()=>{
 const db=openStore(':memory:');
 createProduction(db);
 try{
  const owner=randomUUID(),other=randomUUID(),paid=randomUUID(),unpaid=randomUUID(),foreign=randomUUID();
  const u=db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)');
  u.run(owner,'Titular','owner-f264@example.test','hash','client','2026-11-11');
  u.run(other,'Outro','other-f264@example.test','hash','client','2026-11-11');
  const insert=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  insert.run(paid,owner,'Pago','RECEIVED','2026-11-11','2026-11-11');
  insert.run(unpaid,owner,'Pendente','PENDING','2026-11-10','2026-11-10');
  insert.run(foreign,other,'Terceiro','RECEIVED','2026-11-12','2026-11-12');
  const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
  brief.run(paid,'[]','2026-11-11');
  brief.run(unpaid,'"PRIVATE_UNPAID_DRAFT"','2026-11-10');
  brief.run(foreign,'42','2026-11-12');
  const user={id:owner,role:'client'};
  assert.throws(()=>preflightOwnerProjectExport(db,user),e=>e.status===503);
  assert.throws(()=>accountProjectsPage(db,user),e=>e.status===503);
  assert.throws(()=>[...iterateOwnerExportProjects(db,user)],e=>e.status===503);
  // Payment-gated data can be repaired without exposing other owners.
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?')
   .run('{"company_name":"Autorizada","secret":"DO_NOT_EXPORT"}',paid);
  assert.doesNotThrow(()=>preflightOwnerProjectExport(db,user));
  const view=accountProjectsPage(db,user);
  assert.equal(view.total,2);
  assert.deepEqual(view.projects.find(p=>p.id===unpaid).data,{});
  assert.equal(view.projects.find(p=>p.id===paid).data.company_name,'Autorizada');
  const rows=[...iterateOwnerExportProjects(db,user)];
  assert.equal(rows.length,2);
  assert.equal(rows.find(p=>p.id===paid).data.company_name,'Autorizada');
  assert.deepEqual(rows.find(p=>p.id===unpaid).data,{});
  const text=JSON.stringify({rows,view});
  for(const secret of ['PRIVATE_UNPAID_DRAFT','DO_NOT_EXPORT','Terceiro'])
   assert.equal(text.includes(secret),false);
  // A malformed JSON string must not call json_type() without a validity guard.
  db.prepare('UPDATE briefings SET data=? WHERE order_id=?').run('{INVALID',paid);
  assert.throws(()=>preflightOwnerProjectExport(db,user),e=>e.status===503);
 }finally{db.close();}
});
