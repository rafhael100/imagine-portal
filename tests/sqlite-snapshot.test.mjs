import test from 'node:test';
import assert from 'node:assert/strict';
import {DatabaseSync} from 'node:sqlite';
import {mkdtemp,rm,readFile,stat,readdir,symlink} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';
import {createVerifiedSqliteSnapshot} from '../server/snapshot.mjs';
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
