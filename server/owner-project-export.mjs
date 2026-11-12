// Export-only bounded projection of a customer's projects. No LIMIT/OFFSET
// truncation: the cursor iterates DB rows one at a time for large exports.
// Do not use outside a route that already authenticated the owner.
import {publicProjectData} from './account-export.mjs';
import {randomUUID} from 'node:crypto';

const paid=new Set(['CONFIRMED','RECEIVED','RECEIVED_IN_CASH']);
export const OWNER_EXPORT_LEASE_MS=15*60*1000;
export function pruneExpiredOwnerExportLeases(db,time=Date.now()){
 return db.prepare('DELETE FROM account_export_leases WHERE expires_at<=?').run(time).changes;
}
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
 // F263: avoid loading raw unpaid briefing payloads before our projection.
 const cursor=db.prepare(`SELECT o.id,o.title,o.created_at,o.phase,o.payment_status,CASE WHEN o.payment_status IN ('CONFIRMED','RECEIVED','RECEIVED_IN_CASH') THEN b.data ELSE NULL END AS data,b.status
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

// F244: ServerResponse.end() only marks writableEnded; buffered data may still
// be flushing. Hold the export lease until finish or disconnect, and keep the
// transport error handler alive throughout this final phase.
async function finishExportResponse(res){
 // Lightweight EventEmitter test doubles have no Writable completion contract.
 // Actual Node HTTP ServerResponse instances expose writableFinished.
 if(typeof res.writableFinished!=='boolean'){res.end();return;}
 await new Promise((resolve,reject)=>{
  const cleanup=()=>{
   res.off('finish',onFinish);res.off('close',onClose);res.off('error',onError);
  };
  const onFinish=()=>{cleanup();resolve();};
  const onClose=()=>{cleanup();resolve();};
  const onError=error=>{cleanup();reject(error);};
  res.once('finish',onFinish);res.once('close',onClose);res.once('error',onError);
  try{
   res.end();
   if(res.writableFinished)onFinish();
   else if(res.destroyed)onClose();
  }catch(error){cleanup();reject(error);}
 });
}

// Respect Writable backpressure and disconnects, without accumulating
// unbounded response buffers for accounts with thousands of projects.
export async function writeExportChunks(res,chunks){
 let disconnected=false,responseError=null;
 const onDisconnect=()=>{disconnected=true;};
 // F242: streams may emit error synchronously inside write()/end().
 // Keep a listener for the entire export so an EventEmitter error cannot
 // crash the worker before the route releases its per-user lease.
 const onResponseError=error=>{responseError=error;};
 res.on('close',onDisconnect);
 res.on('error',onResponseError);
 try{
  // F246: a response can close before the writer starts. A for..of loop
  // pulls its first SQLite cursor row before entering the loop body, so
  // reject an already-closed transport before opening that cursor at all.
  if(disconnected||res.destroyed||res.closed||res.writableEnded||res.writableFinished)return;
  for(const chunk of chunks){
   if(disconnected||res.destroyed||res.writableEnded)return;
   const writable=res.write(chunk);
   if(responseError)throw responseError;
   // F240: a Writable can close during write() even when write() returns true.
   // Stop before pulling the next DB cursor row, not on the next iteration.
   if(disconnected||res.destroyed||res.writableEnded)return;
   if(!writable){
    // A Writable may close synchronously inside write(). In that case its
    // close event already fired, so waiting for a future drain would hang.
    const signal=await new Promise((resolve,reject)=>{
     const cleanup=()=>{res.off('drain',onDrain);res.off('close',onClose);res.off('error',onError);};
     const onDrain=()=>{cleanup();resolve('drain');};
     const onClose=()=>{cleanup();resolve('close');};
     const onError=error=>{cleanup();reject(error);};
     res.once('drain',onDrain);res.once('close',onClose);res.once('error',onError);
    });
    // A drain may be followed immediately by close before the continuation
    // resumes. Do not advance the cursor after either event.
    if(signal==='close'||disconnected||res.destroyed||res.writableEnded)return;
   }
  }
  if(!disconnected&&!res.destroyed&&!res.writableEnded){
   await finishExportResponse(res);
   if(responseError)throw responseError;
  }
 }catch(error){
  // Never complete an HTTP 200 with a syntactically valid, truncated CSV if
  // the database cursor or the output stream fails partway through.
  if(res.headersSent&&!res.destroyed&&typeof res.destroy==='function')res.destroy();
  throw error;
 }finally{
  res.off('close',onDisconnect);
  res.off('error',onResponseError);
 }
}

// F241: the SQLite lease has a fixed expiration. A stalled client must not
// retain an unfinished export beyond that lease and allow a second download.
// Abort one minute early so the route's finally can release the owner token.
export const OWNER_EXPORT_MAX_STREAM_MS=OWNER_EXPORT_LEASE_MS-60*1000;
export async function writeOwnerExportWithinLease(res,chunks,maxMs=OWNER_EXPORT_MAX_STREAM_MS){
 if(!Number.isSafeInteger(maxMs)||maxMs<1||maxMs>=OWNER_EXPORT_LEASE_MS)
  throw new RangeError('Prazo máximo da exportação inválido.');
 const timer=setTimeout(()=>{
  // ServerResponse.destroy() triggers close, releasing the streaming cursor.
  // writableEnded means end() was called, NOT that pending bytes flushed.
  // Close a transport still awaiting finish before its lease can expire.
  if(!res.destroyed&&res.writableFinished!==true&&typeof res.destroy==='function')res.destroy();
 },maxMs);
 try{return await writeExportChunks(res,chunks);}
 finally{clearTimeout(timer);}
}
