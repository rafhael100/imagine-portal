// Bounded, read-only projects listing for the currently authenticated
// customer. SQL always includes the owner ID; no client-provided tenant key.
import {publicProjectData} from './account-export.mjs';

export const ACCOUNT_PROJECT_PAGE_SIZE=8;
export const ACCOUNT_PROJECT_MAX_PAGE=1000;
export function boundedProjectPages(total){
 const requested=Math.max(1,Math.ceil(total/ACCOUNT_PROJECT_PAGE_SIZE));
 return {pages:Math.min(requested,ACCOUNT_PROJECT_MAX_PAGE),limited:requested>ACCOUNT_PROJECT_MAX_PAGE};
}
const paid="('CONFIRMED','RECEIVED','RECEIVED_IN_CASH')";
export const ACCOUNT_PROJECT_PHASES=['solicitado','planejamento','desenvolvimento','revisao','entregue'];
const fail=(status,message)=>{throw Object.assign(Error(message),{status});};
export function accountProjectsPage(db,user,{page='1',payment='all',search='',phase='all',sort='newest',attention='all'}={}){
 if(user?.role!=='client'||user.staff_profile||!user.id)fail(403,'Arquivo de projetos exclusivo do cliente.');
 if(typeof page!=='string'||!/^[1-9][0-9]{0,3}$/.test(page)||Number(page)>1000)fail(400,'Página de projetos inválida.');
 if(!['all','paid','other'].includes(payment))fail(400,'Filtro de pagamento inválido.');
 if(phase!=='all'&&!ACCOUNT_PROJECT_PHASES.includes(phase))fail(400,'Etapa do projeto inválida.');
 if(!['newest','oldest'].includes(sort))fail(400,'Ordenação dos projetos inválida.');
 if(!['all','review'].includes(attention))fail(400,'Filtro de revisão inválido.');
 if(typeof search!=='string'||search.length>120)fail(400,'Busca de projetos inválida.');
 const term=search.trim();
 const where=['o.user_id=?'],params=[user.id];
 if(payment==='paid')where.push(`o.payment_status IN ${paid}`);
 else if(payment==='other')where.push(`o.payment_status NOT IN ${paid}`);
 if(phase!=='all'){where.push('o.phase=?');params.push(phase);}
 if(attention==='review')where.push(`o.product_id='site' AND o.payment_status IN ${paid} AND pr.stage='review'`);
 if(term){
  // INSTR treats user input as literal text, not LIKE wildcards.
  // Briefing lookup is limited to paid/confirmed orders.
  const safeField=field=>`CASE WHEN json_valid(b.data) THEN json_extract(b.data,'$.${field}') ELSE '' END`;
  where.push(`(instr(imagine_fold(o.title),imagine_fold(?))>0 OR instr(imagine_fold(o.id),imagine_fold(?))>0 OR
   (o.payment_status IN ${paid} AND
    (instr(imagine_fold(${safeField('company_name')}),imagine_fold(?))>0 OR
     instr(imagine_fold(${safeField('domain')}),imagine_fold(?))>0)))`);
  params.push(term,term,term,term);
 }
 const from='FROM orders o LEFT JOIN briefings b ON b.order_id=o.id LEFT JOIN productions pr ON pr.order_id=o.id WHERE '+where.join(' AND ');
 const total=Number(db.prepare('SELECT count(*) AS n '+from).get(...params).n);
 const current=Number(page),{pages,limited}=boundedProjectPages(total);
 const direction=sort==='oldest'?'ASC':'DESC';
 const rows=db.prepare('SELECT o.id,o.title,o.created_at,o.phase,o.payment_status,o.product_id,b.data,b.status,pr.stage AS production_stage,pr.progress AS production_progress,pr.due_date AS production_due_date '+from+
  ` ORDER BY o.created_at ${direction},o.id ${direction} LIMIT ? OFFSET ?`)
  .all(...params,ACCOUNT_PROJECT_PAGE_SIZE,(current-1)*ACCOUNT_PROJECT_PAGE_SIZE);
 const projects=rows.map(row=>({
  id:row.id,title:row.title,created_at:row.created_at,phase:row.phase,payment_status:row.payment_status,status:row.status,
  data:['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'].includes(row.payment_status)?
    publicProjectData(JSON.parse(row.data||'{}')):{},
  production:row.product_id==='site'&&['CONFIRMED','RECEIVED','RECEIVED_IN_CASH'].includes(row.payment_status)&&
   ['preparation','production','changes','review','approved','published'].includes(row.production_stage)?{
    stage:row.production_stage,
    progress:Number.isSafeInteger(row.production_progress)?Math.max(0,Math.min(100,row.production_progress)):0,
    due_date:typeof row.production_due_date==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(row.production_due_date)?row.production_due_date:null,
   }:null,
 }));
 return {projects,page:current,page_size:ACCOUNT_PROJECT_PAGE_SIZE,pages,total,has_more:current<pages,limited};
}
