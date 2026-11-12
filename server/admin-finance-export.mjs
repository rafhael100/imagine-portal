// Administrative finance CSV. Use only after the admin authorization guard.
// Iterates SQLite rows lazily: avoids allocating one array for every payment.
import {safeCsvCell} from './account-export.mjs';

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
 const rows=db.prepare('SELECT p.*,o.title,u.name,u.email FROM payments p JOIN orders o ON o.id=p.order_id JOIN users u ON u.id=o.user_id ORDER BY p.due_date DESC')
  .iterate();
 for(const payment of rows){
  yield '\r\n'+paymentColumns(payment).map(safeCsvCell).join(';');
 }
}
