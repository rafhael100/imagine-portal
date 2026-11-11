// Export-only bounded projection of a customer's projects. No LIMIT/OFFSET
// truncation: the cursor iterates DB rows one at a time for large exports.
// Do not use outside a route that already authenticated the owner.
import {publicProjectData} from './account-export.mjs';
import {randomUUID} from 'node:crypto';

const paid=new Set(['CONFIRMED','RECEIVED','RECEIVED_IN_CASH']);
export const OWNER_EXPORT_LEASE_MS=15*60*1000;
/** F236: an account may hold one active export lease across Node processes
 * using the shared SQLite database; expiration recovers from process crashes. */
export function acquireOwnerExportLease(db,userId,at=Date.now()){
 if(typeof userId!=='string'||!userId)throw new TypeError('Titular inválido.');
 const token=randomUUID();
 db.exec('BEGIN IMMEDIATE');
 try{
  db.prepare('DELETE FROM account_export_leases WHERE user_id=? AND expires_at<=?').run(userId,at);
  const result=db.prepare('INSERT OR IGNORE INTO account_export_leases(user_id,token,expires_at) VALUES(?,?,?)')
   .run(userId,token,at+OWNER_EXPORT_LEASE_MS);
  if(result.changes!==1)
   throw Object.assign(Error('Já existe uma exportação em andamento nesta conta. Conclua ou aguarde antes de tentar novamente.'),{status:409});
  db.exec('COMMIT');
  return token;
 }catch(error){db.exec('ROLLBACK');throw error;}
}
export function releaseOwnerExportLease(db,userId,token){
 db.prepare('DELETE FROM account_export_leases WHERE user_id=? AND token=?').run(userId,token);
}

/** F232: fail before HTTP 200 and before streaming any personal data
 * when an authorized paid briefing holds invalid JSON. Private rows from
 * other accounts must not block an export. */
export function preflightOwnerProjectExport(db,user){
 if(user?.role!=='client'||user.staff_profile||typeof user.id!=='string')
  throw Object.assign(Error('Exportação exclusiva do titular.'),{status:403});
 const invalid=db.prepare(`SELECT o.id FROM orders o JOIN briefings b ON b.order_id=o.id
  WHERE o.user_id=? AND o.payment_status IN ('CONFIRMED','RECEIVED','RECEIVED_IN_CASH')
    AND b.data IS NOT NULL AND b.data<>'' AND json_valid(b.data)=0 LIMIT 1`).get(user.id);
 if(invalid)throw Object.assign(Error('Dados de projeto temporariamente indisponíveis para exportação.'),{status:503});
}

export function *iterateOwnerExportProjects(db,user){
 if(user?.role!=='client'||user.staff_profile||typeof user.id!=='string')
  throw Object.assign(Error('Exportação exclusiva do titular.'),{status:403});
 const cursor=db.prepare(`SELECT o.id,o.title,o.created_at,o.phase,o.payment_status,b.data,b.status
  FROM orders o LEFT JOIN briefings b ON b.order_id=o.id
  WHERE o.user_id=? ORDER BY o.created_at DESC,o.id DESC`).iterate(user.id);
 for(const row of cursor){
  yield {
   id:row.id,title:row.title,created_at:row.created_at,phase:row.phase,
   payment_status:row.payment_status,status:row.status,
   data:paid.has(row.payment_status)?publicProjectData(JSON.parse(row.data||'{}')):{},
  };
 }
}

// Respect Writable backpressure and disconnects, without accumulating
// unbounded response buffers for accounts with thousands of projects.
export async function writeExportChunks(res,chunks){
 let disconnected=false;
 const onDisconnect=()=>{disconnected=true;};
 res.on('close',onDisconnect);
 try{
  for(const chunk of chunks){
   if(disconnected||res.destroyed||res.writableEnded)return;
   if(!res.write(chunk)){
    const signal=await new Promise((resolve,reject)=>{
     const cleanup=()=>{res.off('drain',onDrain);res.off('close',onClose);res.off('error',onError);};
     const onDrain=()=>{cleanup();resolve('drain');};
     const onClose=()=>{cleanup();resolve('close');};
     const onError=error=>{cleanup();reject(error);};
     res.once('drain',onDrain);res.once('close',onClose);res.once('error',onError);
    });
    if(signal==='close')return;
   }
  }
  if(!disconnected&&!res.destroyed&&!res.writableEnded)res.end();
 }catch(error){
  // Never complete an HTTP 200 with a syntactically valid, truncated CSV if
  // the database cursor or the output stream fails partway through.
  if(res.headersSent&&!res.destroyed&&typeof res.destroy==='function')res.destroy();
  throw error;
 }finally{
  res.off('close',onDisconnect);
 }
}
