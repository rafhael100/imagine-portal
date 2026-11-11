// Explicit operator-only backup utility: no automatic production actions.
import {backup,DatabaseSync} from 'node:sqlite';
import {randomUUID} from 'node:crypto';
import {open,mkdir,link,stat,unlink} from 'node:fs/promises';
import {dirname,basename,join,resolve} from 'node:path';

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
  return {filename:basename(target),bytes:size,pages,verified:true};
 }finally{
  try{await unlink(temporary);}catch(error){if(error.code!=='ENOENT')throw error;}
 }
}
