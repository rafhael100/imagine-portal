// Export-only bounded projection of a customer's projects. No LIMIT/OFFSET
// truncation: the cursor iterates DB rows one at a time for large exports.
// Do not use outside a route that already authenticated the owner.
import {publicProjectData} from './account-export.mjs';

const paid=new Set(['CONFIRMED','RECEIVED','RECEIVED_IN_CASH']);
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
 for(const chunk of chunks){
  if(res.destroyed||res.writableEnded)return;
  if(!res.write(chunk)){
   await new Promise((resolve,reject)=>{
    const cleanup=()=>{res.off('drain',onReady);res.off('close',onReady);res.off('error',onError);};
    const onReady=()=>{cleanup();resolve();};
    const onError=error=>{cleanup();reject(error);};
    res.once('drain',onReady);res.once('close',onReady);res.once('error',onError);
   });
  }
 }
 if(!res.destroyed&&!res.writableEnded)res.end();
}
