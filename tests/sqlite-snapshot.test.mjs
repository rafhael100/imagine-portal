import test from 'node:test';
import assert from 'node:assert/strict';
import {DatabaseSync} from 'node:sqlite';
import {mkdtemp,rm,readFile,stat,readdir,symlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import {createVerifiedSqliteSnapshot,verifyPortalSqliteSnapshot} from '../server/snapshot.mjs';
import {openStore} from '../server/store.mjs';
import {runSnapshotCli} from '../server/backup-cli.mjs';

test('F208: verified SQLite snapshot is private, consistent and refuses overwriting a previous recovery point',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'imagine-snapshot-'));
 t.after(()=>rm(dir,{recursive:true,force:true}));
 const original=new DatabaseSync(':memory:');
 try{
  original.exec("CREATE TABLE examples(id INTEGER PRIMARY KEY, text TEXT); INSERT INTO examples(text) VALUES('primeiro');");
  const dest=join(dir,'backups','snapshot.sqlite');
  const result=await createVerifiedSqliteSnapshot(original,dest);
  assert.equal(result.verified,true);
  assert.equal(result.filename,'snapshot.sqlite');
  assert.ok(result.bytes>0&&result.pages>0);
  assert.match(result.sha256,/^[a-f0-9]{64}$/);
  const attrs=await stat(dest);
  assert.equal(attrs.mode&0o077,0,'Snapshot must not be readable by group/others');
  original.exec("INSERT INTO examples(text) VALUES('segundo')");
  const readOnly=new DatabaseSync(dest,{readOnly:true});
  try{
   assert.equal(readOnly.prepare('PRAGMA integrity_check').get().integrity_check,'ok');
   assert.equal(readOnly.prepare('SELECT count(*) AS n FROM examples').get().n,1);
  }finally{readOnly.close();}
  await assert.rejects(createVerifiedSqliteSnapshot(original,dest),error=>error.code==='EEXIST');
  const again=new DatabaseSync(dest,{readOnly:true});
  try{assert.equal(again.prepare('SELECT count(*) AS n FROM examples').get().n,1);}
  finally{again.close();}
  assert.deepEqual(await readdir(join(dir,'backups')),['snapshot.sqlite']);
 }finally{original.close();}
});

test('F208: CLI requires explicit source/destination, respects read-only source and blocks symlinks',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'imagine-snapshot-cli-'));
 t.after(()=>rm(dir,{recursive:true,force:true}));
 const source=join(dir,'source.sqlite'),destination=join(dir,'output.sqlite');
 const sourceDb=new DatabaseSync(source);
 sourceDb.exec("CREATE TABLE data(message TEXT); INSERT INTO data VALUES('cópia de segurança');");
 sourceDb.close();
 await assert.rejects(runSnapshotCli([]),/Uso:/);
 const result=await runSnapshotCli([source,destination]);
 assert.equal(result.verified,true);
 assert.ok((await stat(destination)).size>0);
 const linked=join(dir,'source-link.sqlite');
 await symlink(source,linked);
 await assert.rejects(runSnapshotCli([linked,join(dir,'blocked.sqlite')]),/sem link simbólico/);
 const command=spawnSync(process.execPath,['server/backup-cli.mjs',source,join(dir,'third.sqlite')],{cwd:process.cwd(),encoding:'utf8'});
 assert.equal(command.status,0,command.stderr);
 const printed=JSON.parse(command.stdout.trim());
 assert.equal(printed.status,'ok');
 assert.equal(printed.verified,true);
 assert.equal(printed.filename,'third.sqlite');
 assert.ok(!command.stdout.includes('cópia de segurança'),'CLI must not print private contents');
 const restored=new DatabaseSync(join(dir,'third.sqlite'),{readOnly:true});
 try{assert.equal(restored.prepare('SELECT message FROM data').get().message,'cópia de segurança');}
 finally{restored.close();}
});

test('F209: release verification rejects foreign formats and counts Portal tables read-only',async t=>{
 const dir=await mkdtemp(join(tmpdir(),'imagine-snapshot-verify-'));
 t.after(()=>rm(dir,{recursive:true,force:true}));
 const db=openStore(':memory:');
 const target=join(dir,'portal-snapshot.sqlite');
 try{
  db.prepare("INSERT INTO users VALUES('user-verification','Responsável','verify@example.test','hash','client','2026-11-11')").run();
  await createVerifiedSqliteSnapshot(db,target);
  const verified=await verifyPortalSqliteSnapshot(target);
  assert.match(verified.sha256,/^[a-f0-9]{64}$/);
  assert.deepEqual(await verifyPortalSqliteSnapshot(target,{expectedSha256:verified.sha256}),verified);
  await assert.rejects(verifyPortalSqliteSnapshot(target,{expectedSha256:'f'.repeat(64)}),/SHA-256/);
  await assert.rejects(verifyPortalSqliteSnapshot(target,{expectedSha256:'invalid'}),/Checksum/);
  assert.equal(verified.verified,true);
  assert.equal(verified.foreign_key_violations,0);
  assert.equal(verified.table_counts.users,1);
  assert.equal(verified.table_counts.orders,0);
  const cli=await runSnapshotCli(['--verify',target]);
  const cliWithHash=await runSnapshotCli(['--verify',target,verified.sha256]);
  assert.deepEqual(cliWithHash,verified);
  assert.deepEqual(cli,verified);
  const status=spawnSync(process.execPath,['server/backup-cli.mjs','--verify',target],{cwd:process.cwd(),encoding:'utf8'});
  assert.equal(status.status,0,status.stderr);
  assert.equal(JSON.parse(status.stdout).table_counts.users,1);
  assert.equal((await stat(target)).size>0,true);
 }finally{db.close();}
 const notPortal=join(dir,'other.sqlite'),foreign=new DatabaseSync(notPortal);
 foreign.exec('CREATE TABLE unrelated(value INTEGER)');
 foreign.close();
 await assert.rejects(verifyPortalSqliteSnapshot(notPortal),/não é um backup do Portal/);
});
