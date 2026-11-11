// Client-only CSV view. Call with the already authorized accountProfile.view
// projects, never with unrestricted database rows.
const HEADERS=['ID','Projeto','Criado em','Situação do pagamento','Empresa','Domínio'];
export function safeCsvCell(value){
 const valueText=String(value??'').replace(/\r\n?/g,'\n');
 // Spreadsheet programs may evaluate cells beginning with formulas, even
 // when a field is surrounded by CSV quotes. Guard leading invisible Unicode
 // format/control characters as well as regular whitespace. An apostrophe in the first
 // position ensures it remains data (including with preceding whitespace).
 const safe=/^[\s\u0000-\u001F\u007F-\u009F\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]*[=+\-@]/u.test(valueText)?"'"+valueText:valueText;
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

const PROFILE_FIELDS=['company','whatsapp','phone','website','version','updated_at'];
const FISCAL_FIELDS=['legal_name','email','document','whatsapp','street','number','district','city','municipality','uf','zip','complement','version'];
const PROJECT_FIELDS=['company_name','domain','email','whatsapp','phone','address','current_site','contact_site','company_intro','services','notes'];
function pickFields(value,fields){
 const source=value&&typeof value==='object'&&!Array.isArray(value)?value:{};
 return Object.fromEntries(fields.filter(key=>{
  if(!Object.hasOwn(source,key))return false;
  const v=source[key];
  return v===null||typeof v==='string'||typeof v==='boolean'||(typeof v==='number'&&Number.isFinite(v));
 }).map(key=>[key,source[key]]));
}
// Fixed privacy contract: never spread whole DB rows or arbitrary material
// keys into an export. Unknown fields (including future internal additions)
// are excluded by default, regardless of what the account UI can render.
export function accountDataExport(account,exportedAt=new Date().toISOString()){
 if(!account||!Array.isArray(account.projects))throw new TypeError('Cadastro inválido.');
 return {
  schema_version:1,
  exported_at:exportedAt,
  scope:'cadastro_e_projetos',
  user:pickFields(account.user,['name','email']),
  created_at:account.created_at,
  profile:pickFields(account.profile,PROFILE_FIELDS),
  fiscal:pickFields(account.fiscal,FISCAL_FIELDS),
  projects:account.projects.map(p=>({
   ...pickFields(p,['id','title','created_at','payment_status','status']),
   data:pickFields(p.data,PROJECT_FIELDS),
  })),
 };
}
