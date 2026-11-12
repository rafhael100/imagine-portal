// Explicit operator-only backup utility: no automatic production actions.
import {backup,DatabaseSync} from 'node:sqlite';
import {randomUUID,createHash} from 'node:crypto';
import {createReadStream} from 'node:fs';
import {open,mkdir,link,stat,unlink,lstat} from 'node:fs/promises';
import {dirname,basename,join,resolve} from 'node:path';

async function sha256Of(path){
 const hash=createHash('sha256');
 for await(const chunk of createReadStream(path))hash.update(chunk);
 return hash.digest('hex');
}
export async function createVerifiedSqliteSnapshot(sourceDb,destination){
 if(typeof destination!=='string'||!destination.trim())throw new TypeError('Informe um destino válido.');
 const target=resolve(destination);
 await mkdir(dirname(target),{recursive:true,mode:0o700});
 const temporary=join(dirname(target),'.'+basename(target)+'.'+randomUUID()+'.tmp');
 // File must be 0600 from creation, not only after backup completion.
 const placeholder=await open(temporary,'wx',0o600);
 await placeholder.close();
 try{
  const pages=await backup(sourceDb,temporary);
  const restored=new DatabaseSync(temporary,{readOnly:true});
  try{
   const integrity=restored.prepare('PRAGMA integrity_check').get();
   if(integrity?.integrity_check!=='ok')throw Error('Backup não passou na verificação SQLite.');
  }finally{restored.close();}
  const size=(await stat(temporary)).size;
  // Hard link is atomic and fails if the destination exists. No silent
  // overwrites, partial published files or cross-directory rename races.
  await link(temporary,target);
  return {filename:basename(target),bytes:size,pages,verified:true,sha256:await sha256Of(target)};
 }finally{
  try{await unlink(temporary);}catch(error){if(error.code!=='ENOENT')throw error;}
 }
}

/** F209: read-only release-gate verification; never restores or changes
 * the destination database. Counts are aggregated, not customer records. */
export async function verifyPortalSqliteSnapshot(path,{expectedSha256=null}={}){
 if(typeof path!=='string'||!path.trim())throw new TypeError('Informe o caminho do backup.');
 if(expectedSha256!==null&&(typeof expectedSha256!=='string'||!/^[a-f0-9]{64}$/.test(expectedSha256)))
  throw new TypeError('Checksum SHA-256 inválido.');
 const source=resolve(path);
 const attrs=await lstat(source);
 if(!attrs.isFile()||attrs.isSymbolicLink())throw Error('Backup deve ser arquivo regular, sem link simbólico.');
 const db=new DatabaseSync(source,{readOnly:true});
 try{
  if(db.prepare('PRAGMA integrity_check').get()?.integrity_check!=='ok')
   throw Error('A integridade do backup falhou.');
  const violations=db.prepare('PRAGMA foreign_key_check').all();
  if(violations.length)throw Error('O backup apresenta referências inválidas.');
  const required=['users','sessions','orders','schema_version'];
  const actual=db.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name IN ('users','sessions','orders','schema_version')").all().map(r=>r.name);
  if(required.some(name=>!actual.includes(name)))
   throw Error('Arquivo não é um backup do Portal SQLite esperado.');
  const counts=Object.fromEntries(['users','orders'].map(table=>[
   table,db.prepare('SELECT count(*) AS total FROM '+table).get().total,
  ]));
  const sha256=await sha256Of(source);
  if(expectedSha256!==null&&sha256!==expectedSha256)throw Error('SHA-256 do backup diferente do checksum esperado.');
  return {filename:basename(source),verified:true,foreign_key_violations:0,table_counts:counts,sha256};
 }finally{db.close();}
}
