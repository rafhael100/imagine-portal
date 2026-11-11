// Client-only CSV view. Call with the already authorized accountProfile.view
// projects, never with unrestricted database rows.
const HEADERS=['ID','Projeto','Criado em','Situação do pagamento','Empresa','Domínio'];
export function safeCsvCell(value){
 const valueText=String(value??'').replace(/\r\n?/g,'\n');
 // Spreadsheet programs may evaluate cells beginning with formulas, even
 // when a field is surrounded by CSV quotes. An apostrophe in the first
 // position ensures it remains data (including with preceding whitespace).
 const safe=/^[\s\uFEFF]*[=+\-@]/u.test(valueText)?"'"+valueText:valueText;
 return '"'+safe.replace(/"/g,'""')+'"';
}
export function projectsCsv(projects){
 if(!Array.isArray(projects))throw new TypeError('Projetos inválidos.');
 const rows=[HEADERS,...projects.map(p=>[
  p.id,p.title,p.created_at,p.payment_status,
  p.data?.company_name||'',p.data?.domain||'',
 ])];
 return '\uFEFF'+rows.map(row=>row.map(safeCsvCell).join(';')).join('\r\n')+'\r\n';
}
