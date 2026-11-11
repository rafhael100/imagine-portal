import {audit,now} from './store.mjs';
import {validEmail,verifyPassword,cleanText,publicUser} from './security.mjs';
import validation from '../checkout-validation.js';
import {publicProjectData} from './account-export.mjs';
const fail=(status,message)=>{throw Object.assign(Error(message),{status});};
// F200: Do not expose unknown persisted JSON fields through the regular API.
const ACCOUNT_PROFILE_LIMITS={company:150,whatsapp:30,phone:30,website:250};
export function safeAccountProfile(row){
 const raw=row?JSON.parse(row.data):{};
 const data=raw&&typeof raw==='object'&&!Array.isArray(raw)?raw:{};
 const profile=Object.fromEntries(Object.entries(ACCOUNT_PROFILE_LIMITS).map(([key,max])=>[
  key,typeof data[key]==='string'?data[key].slice(0,max):'',
 ]));
 return row?{...profile,version:row.version,updated_at:row.updated_at}:{...profile,version:0};
}
export function createAccount(db,fiscal){
 db.exec(`CREATE TABLE IF NOT EXISTS account_profiles(user_id TEXT PRIMARY KEY REFERENCES users(id),data TEXT NOT NULL DEFAULT '{}',version INTEGER NOT NULL DEFAULT 1,updated_at TEXT NOT NULL);INSERT OR IGNORE INTO schema_version VALUES(12);`);

 function view(user,{compact=false}={}){
  const u=db.prepare('SELECT * FROM users WHERE id=?').get(user.id);
  const row=db.prepare('SELECT * FROM account_profiles WHERE user_id=?').get(user.id);
  // F202: short profile requests need only the most recent paid
  // briefing for optional contact suggestions, not the entire archive.
  const sql=`SELECT o.id,o.title,o.created_at,o.payment_status,b.data,b.status
   FROM orders o LEFT JOIN briefings b ON b.order_id=o.id WHERE o.user_id=?
   ${compact?"AND o.payment_status IN ('CONFIRMED','RECEIVED','RECEIVED_IN_CASH') AND b.data IS NOT NULL":''}
   ORDER BY o.created_at DESC,o.id DESC ${compact?'LIMIT 1':''}`;
  const projects=db.prepare(sql).all(user.id).map(o=>({
   ...o,
   data:['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'].includes(o.payment_status)?
    publicProjectData(JSON.parse(o.data||'{}')):{},
  }));
  const response={
   user:publicUser(u),created_at:u.created_at,
   profile:safeAccountProfile(row),fiscal:fiscal.customer(user,user.id),projects,
  };
  if(compact)response.projects_total=db.prepare('SELECT count(*) AS n FROM orders WHERE user_id=?').get(user.id).n;
  return response;
 }
 async function save(user,data){const current=view(user);if(data.version!==current.profile.version)fail(409,'Seu cadastro mudou em outra tela. Atualize antes de salvar.');const fields={company:150,whatsapp:30,phone:30,website:250},profile={};for(const[k,max]of Object.entries(fields)){if(typeof(data[k]??'')!=='string'||String(data[k]||'').length>max)fail(400,'Revise o campo '+k);profile[k]=cleanText(data[k],max);}profile.whatsapp=validation.phoneDigits(profile.whatsapp);profile.phone=validation.phoneDigits(profile.phone);if(profile.whatsapp&&!validation.validPhone(profile.whatsapp)||profile.phone&&!validation.validPhone(profile.phone))fail(400,'Informe telefones brasileiros válidos com DDD.');if(profile.website){let url;try{url=new URL(profile.website);}catch{fail(400,'Informe o site completo, começando por https://.');}if(!['https:','http:'].includes(url.protocol)||url.username||url.password)fail(400,'Informe um endereço de site válido.');profile.website=url.href;}
 const name=cleanText(data.name,120),email=cleanText(data.email,254).toLowerCase();if(name.length<2||!validEmail(email))fail(400,'Confira nome e e-mail de acesso.');const u=db.prepare('SELECT * FROM users WHERE id=?').get(user.id);if(email!==u.email){if(!await verifyPassword(data.currentPassword,u.password_hash))fail(400,'Informe a senha atual para alterar o e-mail de acesso.');if(db.prepare('SELECT id FROM users WHERE email=? AND id<>?').get(email,user.id))fail(409,'Não é possível utilizar este e-mail.');}
 db.exec('BEGIN IMMEDIATE');try{const fresh=db.prepare('SELECT version FROM account_profiles WHERE user_id=?').get(user.id);if((fresh?.version||0)!==data.version)fail(409,'Cadastro alterado em outra tela.');if(email!==u.email){
  // Password verification happens asynchronously before BEGIN IMMEDIATE.
  // Revalidate both the password revision and the original session inside
  // the transaction to prevent a concurrent password rotation from granting
  // a stale request permission to change the login address.
  const active=db.prepare('SELECT 1 FROM sessions WHERE user_id=? AND hash=? AND expires_at>?').get(user.id,user.hash,Date.now());
  if(!active)fail(409,'Sua sessão foi encerrada. Entre novamente antes de alterar o e-mail.');
  const changed=db.prepare('UPDATE users SET name=?,email=? WHERE id=? AND password_hash=?').run(name,email,user.id,u.password_hash);
  if(changed.changes!==1)fail(409,'Sua senha foi alterada em outra sessão. Entre novamente para mudar o e-mail.');
 }else db.prepare('UPDATE users SET name=? WHERE id=?').run(name,user.id);db.prepare('INSERT INTO account_profiles VALUES(?,?,1,?) ON CONFLICT(user_id) DO UPDATE SET data=excluded.data,version=version+1,updated_at=excluded.updated_at').run(user.id,JSON.stringify(profile),now());if(email!==u.email)db.prepare('DELETE FROM sessions WHERE user_id=? AND hash<>?').run(user.id,user.hash||'');audit(db,user.id,'account.profile.updated',user.id);if(email!==u.email)audit(db,user.id,'account.email.changed',user.id);db.exec('COMMIT');}catch(e){db.exec('ROLLBACK');throw e;}return view(user);
 }
 return {view,save};
}
