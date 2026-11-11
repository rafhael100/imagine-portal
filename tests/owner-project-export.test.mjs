import test from 'node:test';
import assert from 'node:assert/strict';
import {EventEmitter} from 'node:events';
import {randomUUID} from 'node:crypto';
import {mkdtemp,rm} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {openStore} from '../server/store.mjs';
import {iterateOwnerExportProjects,writeExportChunks,acquireOwnerExportLease,releaseOwnerExportLease,pruneExpiredOwnerExportLeases,OWNER_EXPORT_LEASE_MS} from '../server/owner-project-export.mjs';
import {projectsCsv,projectsCsvChunks} from '../server/account-export.mjs';

test('F229: streaming customer rows never reveal another owner or unpaid briefing',()=>{
 const db=openStore(':memory:');
 try{
  const owner=randomUUID(),other=randomUUID(),one=randomUUID(),two=randomUUID(),foreign=randomUUID();
  for(const [id,email] of [[owner,'export1@example.test'],[other,'export2@example.test']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente',email,'hash','client','2026-11-11');
  const insert=db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
  insert.run(one,owner,'Projeto pago','RECEIVED','2026-11-11','2026-11-11');
  insert.run(two,owner,'Projeto pendente','PENDING','2026-11-10','2026-11-10');
  insert.run(foreign,other,'PROJETO PRIVADO','RECEIVED','2026-11-12','2026-11-12');
  const brief=db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
  brief.run(one,JSON.stringify({company_name:'Empresa permitida',notes:'Observações',password:'SEGREDO_INTERNO'}),'2026-11-11');
  brief.run(two,JSON.stringify({company_name:'Empresa pendente',notes:'NÃO_EXPORTAR'}),'2026-11-10');
  brief.run(foreign,JSON.stringify({company_name:'Outro titular'}),'2026-11-12');
  const rows=[...iterateOwnerExportProjects(db,{role:'client',id:owner})];
  assert.deepEqual(rows.map(p=>p.id),[one,two]);
  assert.equal(rows[0].data.company_name,'Empresa permitida');
  assert.equal(rows[0].data.password,undefined);
  assert.deepEqual(rows[1].data,{});
  const csv=projectsCsv(rows);
  assert.equal(csv,[...projectsCsvChunks(iterateOwnerExportProjects(db,{role:'client',id:owner}))].join(''));
  for(const forbidden of ['SEGREDO_INTERNO','NÃO_EXPORTAR','Outro titular','PROJETO PRIVADO'])assert.equal(csv.includes(forbidden),false);
  assert.throws(()=>[...iterateOwnerExportProjects(db,{role:'admin',id:owner})],e=>e.status===403);
 }finally{db.close();}
});

test('F229: streaming writer respects drain and safely stops after disconnect',async()=>{
 class Writable extends EventEmitter{
  constructor(){super();this.chunks=[];this.destroyed=false;this.writableEnded=false;}
  write(value){this.chunks.push(value);return this.chunks.length!==1;}
  end(){this.writableEnded=true;}
 }
 const r=new Writable();
 const pending=writeExportChunks(r,['header','row1','row2']);
 assert.deepEqual(r.chunks,['header']);
 r.emit('drain');
 await pending;
 assert.deepEqual(r.chunks,['header','row1','row2']);
 assert.equal(r.writableEnded,true);
 const closed=new Writable();
 const terminated=writeExportChunks(closed,['header','private']);
 closed.destroyed=true;closed.emit('close');
 await terminated;
 assert.deepEqual(closed.chunks,['header']);
 assert.equal(closed.writableEnded,false);
});

test('F234: a remote close without the destroyed flag stops the cursor and never ends a partial download',async()=>{
 class Slow extends EventEmitter{
  constructor(){super();this.chunks=[];this.destroyed=false;this.writableEnded=false;}
  write(c){this.chunks.push(c);return false;}
  end(){this.writableEnded=true;}
 }
 const res=new Slow();
 let iterated=0;
 function *privateChunks(){for(const c of ['header','secret-after-close']){iterated++;yield c;}}
 const pending=writeExportChunks(res,privateChunks());
 res.emit('close');
 await pending;
 assert.deepEqual(res.chunks,['header']);
 assert.equal(iterated,1);
 assert.equal(res.writableEnded,false);
 assert.equal(res.listenerCount('close'),0);
 assert.equal(res.listenerCount('drain'),0);
});

test('F234: failures after HTTP headers abort the transport rather than producing a partial 200 response',async()=>{
 class Broken extends EventEmitter{
  constructor(){super();this.headersSent=true;this.destroyed=false;this.writableEnded=false;this.chunks=[];}
  write(c){this.chunks.push(c);return true;}
  destroy(){this.destroyed=true;this.emit('close');}
  end(){this.writableEnded=true;}
 }
 const res=new Broken();
 function *faulty(){
  yield 'header';
  throw Error('DB cursor failed');
 }
 await assert.rejects(writeExportChunks(res,faulty()),/DB cursor failed/);
 assert.deepEqual(res.chunks,['header']);
 assert.equal(res.destroyed,true);
 assert.equal(res.writableEnded,false);
 assert.equal(res.listenerCount('close'),0);
});

test('F236: only one active export per account, different accounts remain independent',()=>{
 const db=openStore(':memory:');
 try{
  const user=randomUUID(),other=randomUUID();
  for(const [id,email] of [[user,'lease1@example.test'],[other,'lease2@example.test']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente',email,'hash','client','2026-11-11');
  const at=Date.now();
  const ownerToken=acquireOwnerExportLease(db,user,at);
  assert.throws(()=>acquireOwnerExportLease(db,user,at+5),e=>e.status===409&&/exportação em andamento/.test(e.message));
  const otherToken=acquireOwnerExportLease(db,other,at+5);
  assert.equal(db.prepare('SELECT count(*) AS n FROM account_export_leases').get().n,2);
  releaseOwnerExportLease(db,user,ownerToken);
  const newToken=acquireOwnerExportLease(db,user,at+10);
  assert.notEqual(newToken,ownerToken);
  releaseOwnerExportLease(db,user,ownerToken); // stale lease release cannot delete new ownership
  assert.equal(db.prepare('SELECT token FROM account_export_leases WHERE user_id=?').get(user).token,newToken);
  releaseOwnerExportLease(db,user,newToken);
  releaseOwnerExportLease(db,other,otherToken);
  assert.equal(db.prepare('SELECT count(*) AS n FROM account_export_leases').get().n,0);
 }finally{db.close();}
});

test('F236: an expired lease is recoverable after worker crash without stale-release takeover',()=>{
 const db=openStore(':memory:');
 try{
  const user=randomUUID();
  db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(user,'Cliente','crash@example.test','hash','client','2026-11-11');
  const start=Date.now(),dead=acquireOwnerExportLease(db,user,start);
  const revived=acquireOwnerExportLease(db,user,start+OWNER_EXPORT_LEASE_MS+1);
  assert.notEqual(dead,revived);
  releaseOwnerExportLease(db,user,dead);
  assert.equal(db.prepare('SELECT token FROM account_export_leases WHERE user_id=?').get(user).token,revived);
  releaseOwnerExportLease(db,user,revived);
 }finally{db.close();}
});

test('F237: indexed stale-lease pruning removes crashed workers, preserves live exports',()=>{
 const db=openStore(':memory:');
 try{
  const a=randomUUID(),b=randomUUID(),at=Date.now();
  for(const [id,email]of [[a,'expired@example.test'],[b,'active@example.test']])
   db.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente',email,'hash','client','2026-11-11');
  acquireOwnerExportLease(db,a,at-OWNER_EXPORT_LEASE_MS-1);
  const active=acquireOwnerExportLease(db,b,at);
  assert.equal(pruneExpiredOwnerExportLeases(db,at),1);
  assert.equal(db.prepare('SELECT count(*) AS n FROM account_export_leases').get().n,1);
  assert.equal(db.prepare('SELECT token FROM account_export_leases WHERE user_id=?').get(b).token,active);
  assert.equal(pruneExpiredOwnerExportLeases(db,at),0);
 }finally{db.close();}
});

test('F238: separate SQLite connections cannot bypass per-account export lease',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'imagine-export-lease-'));
 t.after(()=>rm(dir,{recursive:true,force:true}));
 const filename=join(dir,'portal.sqlite'),dbA=openStore(filename),dbB=openStore(filename);
 try{
  const id=randomUUID();
  dbA.prepare('INSERT INTO users VALUES(?,?,?,?,?,?)').run(id,'Cliente','concurrent@example.test','hash','client','2026-11-11');
  const first=acquireOwnerExportLease(dbA,id);
  assert.throws(()=>acquireOwnerExportLease(dbB,id),e=>e.status===409);
  assert.equal(dbB.prepare('SELECT token FROM account_export_leases WHERE user_id=?').get(id).token,first);
  releaseOwnerExportLease(dbA,id,first);
  const second=acquireOwnerExportLease(dbB,id);
  assert.notEqual(first,second);
  releaseOwnerExportLease(dbA,id,first);
  assert.equal(dbA.prepare('SELECT token FROM account_export_leases WHERE user_id=?').get(id).token,second);
  releaseOwnerExportLease(dbB,id,second);
  assert.equal(dbA.prepare('SELECT count(*) AS n FROM account_export_leases').get().n,0);
 }finally{dbB.close();dbA.close();}
});
