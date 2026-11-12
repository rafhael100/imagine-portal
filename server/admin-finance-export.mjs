// Administrative finance CSV. Use only after the admin authorization guard.
// Iterates SQLite rows lazily with an explicit column allowlist: neither
// billing provider identifiers nor other internal payment columns are loaded.
import {safeCsvCell} from './account-export.mjs';
import {writeOwnerExportWithinLease} from './owner-project-export.mjs';

const FINANCE_HEADERS=['Pedido','Cliente','Email','Serviço','Tipo','Status',
 'Valor bruto BRL','Valor líquido BRL','Vencimento','Pagamento'];

function paymentColumns(p){
 return [p.order_id,p.name,p.email,p.title,p.kind,p.status,
  (p.value_cents/100).toFixed(2),
  p.net_cents===null?'':(p.net_cents/100).toFixed(2),
  p.due_date,p.paid_at];
}

export function *financeCsvChunks(db){
 yield '\uFEFF'+FINANCE_HEADERS.map(safeCsvCell).join(';');
 const rows=db.prepare('SELECT p.order_id,p.kind,p.status,p.value_cents,p.net_cents,p.due_date,p.paid_at,o.title,u.name,u.email FROM payments p JOIN orders o ON o.id=p.order_id JOIN users u ON u.id=o.user_id ORDER BY p.due_date DESC')
  .iterate();
 for(const payment of rows){
  yield '\r\n'+paymentColumns(payment).map(safeCsvCell).join(';');
 }
}

// F251: cap a stalled admin download; a disconnected/slow browser must not
// retain SQLite cursor resources indefinitely. Owner exports keep their
// existing independent 14-minute deadline and per-account lease.
export const ADMIN_FINANCE_MAX_STREAM_MS=5*60*1000;
export async function streamAdminFinanceCsv(res,db,maxMs=ADMIN_FINANCE_MAX_STREAM_MS){
 return writeOwnerExportWithinLease(res,financeCsvChunks(db),maxMs);
}
