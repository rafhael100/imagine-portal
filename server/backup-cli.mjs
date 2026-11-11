// Manual-only CLI: source and output path are both mandatory.
import {DatabaseSync} from 'node:sqlite';
import {lstat} from 'node:fs/promises';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {createVerifiedSqliteSnapshot,verifyPortalSqliteSnapshot} from './snapshot.mjs';

export async function runSnapshotCli(args){
 if(args.length!==2)throw Error('Uso: node server/backup-cli.mjs CAMINHO_BANCO CAMINHO_BACKUP | --verify CAMINHO_BACKUP');
 if(args[0]==='--verify')return verifyPortalSqliteSnapshot(args[1]);
 const source=resolve(args[0]),destination=resolve(args[1]);
 if(source===destination)throw Error('O backup deve ter destino diferente da origem.');
 const sourceStat=await lstat(source);
 if(!sourceStat.isFile()||sourceStat.isSymbolicLink())throw Error('A origem deve ser um arquivo SQLite regular, sem link simbólico.');
 const db=new DatabaseSync(source,{readOnly:true});
 try{return await createVerifiedSqliteSnapshot(db,destination);}
 finally{db.close();}
}
if(process.argv[1]&&import.meta.url===pathToFileURL(process.argv[1]).href){
 runSnapshotCli(process.argv.slice(2)).then(result=>{
  console.log(JSON.stringify({status:'ok',...result}));
 }).catch(error=>{
  console.error('Backup não concluído: '+(error.code==='EEXIST'?'destino já existe':error.message));
  process.exitCode=1;
 });
}
