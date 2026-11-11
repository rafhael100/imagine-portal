import {mkdtemp,rm,readdir} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {join,basename} from 'node:path';
import {migrateBriefing,MAX_FILE_BYTES} from '../server/briefing.mjs';
import test from 'node:test';
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {openStore,now} from '../server/store.mjs';
import {hashPassword} from '../server/security.mjs';
import {createApp,addDays} from '../server/app.mjs';
import {createAsaas} from '../server/asaas.mjs';
import {businessDue,migrateProduction} from '../server/production.mjs';
const publicDir=fileURLToPath(new URL('../',import.meta.url));
const fixed=new Date('2026-10-30T15:00:00Z');
test('Previsão de sete dias úteis pula fins de semana e atravessa o ano',()=>{assert.equal(businessDue('2026-10-30T15:00:00Z'),'2026-11-10');assert.equal(businessDue('2026-12-28T15:00:00Z'),'2027-01-06')});
function fakeAsaas(){const customers=[],payments=[],subscriptions=[],checkouts=[];return {configured:true,environment:'sandbox',customers,payments,subscriptions,checkouts,
 findCustomer:async ref=>customers.filter(c=>c.externalReference===ref),createCustomer:async d=>{const c={...d,id:'cus_'+randomUUID()};customers.push(c);return c},
 findPayments:async ref=>payments.filter(p=>p.externalReference===ref),createPayment:async d=>{const p={...d,id:'pay_'+randomUUID(),status:'PENDING',invoiceUrl:'https://sandbox.asaas.com/i/test'};payments.push(p);return p},getPayment:async id=>{const p=payments.find(p=>p.id===id);if(!p)throw Error('Not found');return p},
 createCheckout:async data=>{const c={...data,id:randomUUID(),status:'ACTIVE'};checkouts.push(c);return c},checkoutUrl:id=>'https://sandbox.asaas.com/checkoutSession/show?id='+id,
 cancelCheckout:async id=>{const c=checkouts.find(c=>c.id===id);if(c)c.status='CANCELED';return {}},listSubscriptions:async customer=>subscriptions.filter(s=>s.customer===customer),getSubscription:async id=>subscriptions.find(s=>s.id===id),subscriptionPayments:async id=>payments.filter(p=>p.subscription===id&&!p.deleted),cancelSubscription:async id=>{const s=subscriptions.find(s=>s.id===id);s.deleted=true;for(const p of payments)if(p.subscription===id&&['PENDING','OVERDUE'].includes(p.status))p.deleted=true;return {deleted:true}}}}
async function fixture(t,{trustProxy=false}={}){const uploadDir=await mkdtemp(join(tmpdir(),'imagine-upload-test-'));const db=openStore(':memory:'),gateway=fakeAsaas(),origin='http://127.0.0.1:4173',secret='a'.repeat(40);const app=createApp({newSiteBilling:'legacy',db,asaas:gateway,publicDir,origin,webhookToken:secret,clock:()=>fixed,uploadDir,trustProxy});await new Promise(r=>app.server.listen(0,'127.0.0.1',r));const url='http://127.0.0.1:'+app.server.address().port;const clients=[];async function client(name='Cliente',role='client'){const email=randomUUID()+'@example.test',password='uma-senha-de-teste-longa';if(role==='admin')db.prepare("INSERT INTO users VALUES(?,?,?,?,'admin',?)").run(randomUUID(),name,email,await hashPassword(password),now());const c={cookie:'',csrf:'',user:null};c.call=async(path,data,method)=>{const r=await fetch(url+path,{method:method||(data===undefined?'GET':'POST'),headers:{Origin:origin,'Content-Type':'application/json',Cookie:c.cookie,'X-CSRF-Token':c.csrf},body:data===undefined?undefined:JSON.stringify(data)});let body;const content=await r.text();try{body=JSON.parse(content)}catch{body=content}const cookie=r.headers.get('set-cookie');if(cookie)c.cookie=cookie.split(';')[0];if(body.csrf)c.csrf=body.csrf;if(body.user)c.user=body.user;return {status:r.status,body,headers:r.headers}};const result=await c.call(role==='admin'?'/api/login':'/api/register',{name,email,password});assert.ok([200,201].includes(result.status));clients.push(c);return c}t.after(async()=>{await new Promise(r=>app.server.close(r));db.close();assert.ok(basename(uploadDir).startsWith('imagine-upload-test-'));await rm(uploadDir,{recursive:true,force:true})});return {...app,db,gateway,client,url,origin,secret,uploadDir}}
function enableSite(db){db.prepare("UPDATE products SET enabled=1,scope=?,delivery=? WHERE id='site'").run('Site Onepage responsivo, com seções combinadas, contato e uma rodada de revisão.','Prazo de 15 dias úteis após recebimento do conteúdo.');}
async function order(c,product='site'){const result=await c.call('/api/orders',{product,brief:'Precisamos de um site para a nossa empresa.',requestId:randomUUID()});assert.equal(result.status,201);return result.body.order}
async function pay(c,o,extra={}){return c.call('/api/orders/'+o.id+'/checkout',{cpfCnpj:'52998224725',accepted:true,termsVersion:o.terms_version,...extra})}
test('Sessões legadas são limitadas por conta sem revogar outros clientes ou preservar entradas vencidas',async t=>{
 const f=await fixture(t),ana=await f.client('Ana'),bia=await f.client('Bia');
 const initial=ana.cookie,userId=ana.user.id,otherId=bia.user.id;
 // Existing cookie is deliberately older. The newest login must never be
 // discarded when a user reaches the cap, even during a burst of requests.
 f.db.prepare('UPDATE sessions SET expires_at=? WHERE user_id=?').run(Date.now()+3600000,userId);
 f.db.prepare('INSERT INTO sessions VALUES(?,?,?,?)').run('f'.repeat(64),otherId,'csrf-expired',Date.now()-1000);
 const password='uma-senha-de-teste-longa';
 for(let i=0;i<10;i++){
  const result=await ana.call('/api/login',{email:ana.user.email,password});
  assert.equal(result.status,200);
  assert.ok(result.body.csrf);
 }
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(userId).n,8);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(otherId).n,1);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE hash=?').get('f'.repeat(64)).n,0);
 const stale=await fetch(f.url+'/api/session',{headers:{Cookie:initial}});
 assert.equal((await stale.json()).user,null);
 assert.equal((await ana.call('/api/session')).body.user.id,userId);
 assert.equal((await bia.call('/api/session')).body.user.id,otherId);
});

test('Troca de senha segue revogando todas as sessões antigas após a limitação por usuário',async t=>{
 const f=await fixture(t),user=await f.client('Conta');
 const original=user.cookie,old='uma-senha-de-teste-longa',next='uma-nova-senha-mais-longa';
 assert.equal((await user.call('/api/login',{email:user.user.email,password:old})).status,200);
 const previous=user.cookie;
 assert.equal((await user.call('/api/password',{currentPassword:old,newPassword:next})).status,200);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(user.user.id).n,1);
 for(const cookie of [original,previous]){
  const response=await fetch(f.url+'/api/session',{headers:{Cookie:cookie}});
  assert.equal((await response.json()).user,null);
 }
 assert.equal((await user.call('/api/session')).body.user.id,user.user.id);
 assert.equal((await user.call('/api/login',{email:user.user.email,password:old})).status,401);
 assert.equal((await user.call('/api/login',{email:user.user.email,password:next})).status,200);
});

test('Rate limit distingue IPs atrás de proxy explicitamente confiável sem punir clientes diferentes',async t=>{
 const f=await fixture(t,{trustProxy:true});
 const request=ip=>fetch(f.url+'/api/register',{
  method:'POST',
  headers:{Origin:f.origin,'Content-Type':'application/json','X-Forwarded-For':ip},
  body:JSON.stringify({}),
 });
 // Invalid signup is rejected before hashing a password but still counts
 // toward the IP's real 8-per-hour rate limit.
 for(let i=0;i<8;i++)assert.equal((await request('198.51.100.11')).status,400);
 for(let i=0;i<8;i++)assert.equal((await request('198.51.100.12')).status,400);
 assert.equal((await request('198.51.100.11')).status,429);
 assert.equal((await request('198.51.100.12')).status,429);
 const buckets=f.db.prepare("SELECT key,count FROM rate_limits WHERE key LIKE 'register:%' ORDER BY key").all();
 assert.deepEqual(buckets.map(row=>row.count),[8,8]);
 assert.deepEqual(buckets.map(row=>row.key),
  ['register:198.51.100.11','register:198.51.100.12']);
});

test('Proxy local que acrescenta XFF bloqueia spoofing do IP mais à esquerda',async t=>{
 const f=await fixture(t,{trustProxy:true});
 const request=forwarded=>fetch(f.url+'/api/register',{
  method:'POST',
  headers:{Origin:f.origin,'Content-Type':'application/json','X-Forwarded-For':forwarded},
  body:JSON.stringify({}),
 });
 // Deliberate threat model: an untrusted client forges the first part of
 // the chain. The local proxy appends its verified peer as the LAST part.
 // The server must use the last value for the rate-limit identity.
 for(let i=0;i<8;i++){
  assert.equal((await request('198.51.100.'+(i+10)+', 203.0.113.40')).status,400);
 }
 assert.equal((await request('198.51.100.250, 203.0.113.40')).status,429);
 assert.equal((await request('198.51.100.250, 203.0.113.41')).status,400);
 const buckets=f.db.prepare("SELECT key,count FROM rate_limits WHERE key LIKE 'register:%' ORDER BY key").all();
 assert.deepEqual(buckets.map(row=>row.key),['register:203.0.113.40','register:203.0.113.41']);
 assert.deepEqual(buckets.map(row=>row.count),[8,1]);
});

test('Sem confiança de proxy, X-Forwarded-For manipulado nunca reseta o limite de cadastro',async t=>{
 const f=await fixture(t,{trustProxy:false});
 const request=ip=>fetch(f.url+'/api/register',{
  method:'POST',
  headers:{Origin:f.origin,'Content-Type':'application/json','X-Forwarded-For':ip},
  body:JSON.stringify({}),
 });
 for(let i=0;i<8;i++)assert.equal((await request('198.51.100.'+(i+10))).status,400);
 assert.equal((await request('203.0.113.201')).status,429);
 const buckets=f.db.prepare("SELECT key,count FROM rate_limits WHERE key LIKE 'register:%'").all();
 assert.equal(buckets.length,1);
 assert.equal(buckets[0].count,8);
 assert.ok(['register:127.0.0.1','register:::1'].includes(buckets[0].key));
});

test('Buckets de rate limit vencidos são eliminados sem resetar quotas ativas',async t=>{
 const f=await fixture(t);
 const expired=Date.now()-1000,active=Date.now()+3600000;
 f.db.prepare('INSERT INTO rate_limits VALUES(?,?,?)').run('login-email:expired-1',15,expired);
 f.db.prepare('INSERT INTO rate_limits VALUES(?,?,?)').run('register:expired-2',8,expired);
 f.db.prepare('INSERT INTO rate_limits VALUES(?,?,?)').run('login-email:still-active',3,active);
 const request=()=>fetch(f.url+'/api/register',{
  method:'POST',headers:{Origin:f.origin,'Content-Type':'application/json'},
  body:JSON.stringify({}),
 });
 assert.equal((await request()).status,400);
 assert.equal(f.db.prepare("SELECT count(*) AS n FROM rate_limits WHERE key LIKE '%expired-%'").get().n,0);
 const preserved=f.db.prepare('SELECT count,reset_at FROM rate_limits WHERE key=?').get('login-email:still-active');
 assert.equal(preserved.count,3);
 assert.equal(preserved.reset_at,active);
 for(let i=0;i<7;i++)assert.equal((await request()).status,400);
 assert.equal((await request()).status,429);
 const counters=f.db.prepare("SELECT count FROM rate_limits WHERE key LIKE 'register:%'").all();
 assert.deepEqual(counters.map(row=>row.count),[8]);
});

test('A conta encerra apenas outras sessões após senha atual e CSRF, sem afetar outro cliente',async t=>{
 const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia');
 const password='uma-senha-de-teste-longa',oldCookie=a.cookie;
 assert.equal((await a.call('/api/login',{email:a.user.email,password})).status,200);
 const latestCookie=a.cookie;
 assert.notEqual(latestCookie,oldCookie);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,2);
 assert.equal((await a.call('/api/sessions/revoke-others',{currentPassword:'senha-incorreta'})).status,400);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,2);
 const correctCsrf=a.csrf;a.csrf='csrf-adulterado';
 assert.equal((await a.call('/api/sessions/revoke-others',{currentPassword:password})).status,403);
 a.csrf=correctCsrf;
 assert.equal((await a.call('/api/sessions/revoke-others',{currentPassword:password})).status,200);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,1);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(b.user.id).n,1);
 const stale=await fetch(f.url+'/api/session',{headers:{Cookie:oldCookie}});
 assert.equal((await stale.json()).user,null);
 assert.equal((await a.call('/api/session')).body.user.id,a.user.id);
 assert.equal((await b.call('/api/session')).body.user.id,b.user.id);
 assert.ok(f.db.prepare("SELECT id FROM audit WHERE actor_id=? AND action='sessions.revoked_others'").get(a.user.id));
});

test('Membro da equipe pode encerrar outras sessões próprias, sem obter privilégio de clientes',async t=>{
 const f=await fixture(t),staff=await f.client('Atendimento','admin');
 f.db.prepare('INSERT INTO staff(user_id,profile,active) VALUES(?,?,1)').run(staff.user.id,'atendimento');
 const password='uma-senha-de-teste-longa';
 assert.equal((await staff.call('/api/login',{email:staff.user.email,password})).status,200);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(staff.user.id).n,2);
 assert.equal((await staff.call('/api/sessions/revoke-others',{currentPassword:password})).status,200);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(staff.user.id).n,1);
 assert.equal((await staff.call('/api/admin/finance.csv')).status,403);
});

test('Resumo de sessões mostra apenas contagem da própria conta e atualiza após revogação',async t=>{
 const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia');
 const password='uma-senha-de-teste-longa';
 const first=await a.call('/api/sessions/summary');
 assert.equal(first.status,200);
 assert.deepEqual(first.body,{active_sessions:1,other_sessions:0,max_active_sessions:8});
 assert.equal((await a.call('/api/login',{email:a.user.email,password})).status,200);
 const summary=(await a.call('/api/sessions/summary')).body;
 assert.deepEqual(summary,{active_sessions:2,other_sessions:1,max_active_sessions:8});
 assert.deepEqual((await b.call('/api/sessions/summary')).body,
  {active_sessions:1,other_sessions:0,max_active_sessions:8});
 assert.equal(JSON.stringify(summary).includes('hash'),false);
 assert.equal(JSON.stringify(summary).includes('csrf'),false);
 assert.equal(JSON.stringify(summary).includes(a.user.id),false);
 assert.equal((await a.call('/api/sessions/revoke-others',{currentPassword:password})).status,200);
 assert.deepEqual((await a.call('/api/sessions/summary')).body,
  {active_sessions:1,other_sessions:0,max_active_sessions:8});
 const anonymous=await fetch(f.url+'/api/sessions/summary');
 assert.equal(anonymous.status,401);
});

test('F170: resumo de sessões ignora expiradas, isola equipe e não permite cache',async t=>{
 const f=await fixture(t),a=await f.client('Cliente A'),b=await f.client('Cliente B');
 const password='uma-senha-de-teste-longa';
 assert.equal((await a.call('/api/login',{email:a.user.email,password})).status,200);
 // Expirar a sessão anterior sem encerrar o cookie recém-emitido.
 f.db.prepare('UPDATE sessions SET expires_at=? WHERE hash=(SELECT hash FROM sessions WHERE user_id=? ORDER BY expires_at ASC LIMIT 1)')
  .run(Date.now()-1000,a.user.id);
 // A sessão corrente continua válida mesmo que um registro antigo esteja vencido.
 // A contagem não pode incluir registros expirados nem os de outro cliente.
 const response=await a.call('/api/sessions/summary');
 assert.equal(response.status,200);
 assert.equal(response.body.active_sessions,1);
 assert.equal(response.body.other_sessions,response.body.active_sessions-1);
 assert.deepEqual(Object.keys(response.body).sort(),['active_sessions','max_active_sessions','other_sessions']);
 assert.deepEqual((await b.call('/api/sessions/summary')).body,
  {active_sessions:1,other_sessions:0,max_active_sessions:8});
 const raw=await fetch(f.url+'/api/sessions/summary',{headers:{Cookie:a.cookie}});
 assert.match(raw.headers.get('cache-control')||'',/no-store/);
 assert.equal(raw.headers.get('x-content-type-options'),'nosniff');
 const staff=await f.client('Equipe','admin');
 f.db.prepare('INSERT INTO staff(user_id,profile,active) VALUES(?,?,1)').run(staff.user.id,'atendimento');
 assert.deepEqual((await staff.call('/api/sessions/summary')).body,
  {active_sessions:1,other_sessions:0,max_active_sessions:8});
});

test('F171: recibo de revogação informa sessões próprias ativas, nunca contas de terceiros',async t=>{
 const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia');
 const password='uma-senha-de-teste-longa';
 assert.equal((await a.call('/api/login',{email:a.user.email,password})).status,200);
 const first=await a.call('/api/sessions/revoke-others',{currentPassword:password});
 assert.equal(first.status,200);
 assert.deepEqual(first.body,{ok:true,revoked_sessions:1,active_sessions:1,other_sessions:0,max_active_sessions:8});
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,1);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(b.user.id).n,1);
 assert.deepEqual((await a.call('/api/sessions/summary')).body,
  {active_sessions:1,other_sessions:0,max_active_sessions:8});
 const repeated=await a.call('/api/sessions/revoke-others',{currentPassword:password});
 assert.equal(repeated.status,200);
 assert.equal(repeated.body.revoked_sessions,0);
 assert.equal((await b.call('/api/sessions/summary')).body.active_sessions,1);
 assert.equal(Object.keys(repeated.body).some(k=>/hash|csrf|token|email|ip/.test(k)),false);
});

test('F172: histórico de segurança é próprio, limitado e não expõe dados de auditoria interna',async t=>{
 const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia');
 const password='uma-senha-de-teste-longa';
 f.db.prepare('INSERT INTO audit(actor_id,action,resource_id,created_at) VALUES(?,?,?,?)')
  .run(a.user.id,'billing.token_failed','segredo-interno',new Date().toISOString());
 assert.equal((await a.call('/api/sessions/revoke-others',{currentPassword:password})).status,200);
 const own=await a.call('/api/account/security-events');
 assert.equal(own.status,200);
 assert.equal(own.body.events.length,2);
 assert.deepEqual(own.body.events.map(e=>e.action),['sessions.revoked_others','account.created']);
 assert.ok(own.body.events.every(e=>Object.keys(e).sort().join(',')==='action,created_at'));
 assert.equal(JSON.stringify(own.body).includes(a.user.id),false);
 assert.equal(JSON.stringify(own.body).includes('segredo-interno'),false);
 assert.deepEqual((await b.call('/api/account/security-events')).body.events.map(e=>e.action),['account.created']);
 const response=await fetch(f.url+'/api/account/security-events');
 assert.equal(response.status,401);
 assert.match(response.headers.get('cache-control')||'',/no-store/);
 const staff=await f.client('Atendimento','admin');
 f.db.prepare('INSERT INTO staff(user_id,profile,active) VALUES(?,?,1)').run(staff.user.id,'atendimento');
 const staffHistory=await staff.call('/api/account/security-events');
 assert.equal(staffHistory.status,200);
 assert.deepEqual(staffHistory.body,{events:[]});
 assert.equal((await staff.call('/api/admin/finance.csv')).status,403);
});

test('F189: historico de seguranca paginado preserva isolamento e o formato antigo',async t=>{
 const f=await fixture(t),ana=await f.client('Ana'),bia=await f.client('Bia');
 const stmt=f.db.prepare('INSERT INTO audit(actor_id,action,resource_id,created_at) VALUES(?,?,?,?)');
 const anaId=ana.user.id,biaId=bia.user.id;
 for(let i=0;i<24;i++)stmt.run(anaId,'password.changed','internal-'+i,'2026-11-11T10:00:00Z');
 for(let i=0;i<8;i++)stmt.run(biaId,'password.changed','other-user-'+i,'2026-11-11T11:00:00Z');
 stmt.run(anaId,'payment.creditcard.secret','unapproved-action','2026-11-11T12:00:00Z');
 const legacy=await ana.call('/api/account/security-events');
 assert.equal(legacy.status,200);
 assert.equal(legacy.body.events.length,10);
 assert.deepEqual(Object.keys(legacy.body),['events']);
 const pages=[];
 for(let page=1;page<=3;page++){
  const result=await ana.call('/api/account/security-events?page='+page);
  assert.equal(result.status,200);
  assert.equal(result.body.page,page);
  assert.equal(result.body.events.length,page===3?5:10);
  assert.equal(result.body.has_more,page!==3);
  assert.ok(result.body.events.every(e=>Object.keys(e).sort().join(',')==='action,created_at'));
  pages.push(result.body.events);
 }
 const second=await bia.call('/api/account/security-events?page=1');
 assert.equal(second.status,200);assert.equal(second.body.events.length,9);
 assert.equal(second.body.has_more,false);
 for(const invalid of ['0','-1','1.1','01','31','NaN','999999']){
  assert.equal((await ana.call('/api/account/security-events?page='+invalid)).status,400);
 }
 const full=JSON.stringify(pages);
 for(const secret of ['internal-0','other-user','unapproved-action',anaId,biaId]){
  assert.equal(full.includes(secret),false,secret);
 }
 assert.equal((await fetch(f.url+'/api/account/security-events?page=1')).status,401);
});

test('F198: ultima pagina autorizada nao promete acesso a eventos fora do limite',async t=>{
 const f=await fixture(t),owner=await f.client('Responsável');
 const stmt=f.db.prepare('INSERT INTO audit(actor_id,action,resource_id,created_at) VALUES(?,?,?,?)');
 for(let i=0;i<310;i++)stmt.run(owner.user.id,'password.changed','internal','2026-11-11T10:00:00Z');
 const last=await owner.call('/api/account/security-events?page=30');
 assert.equal(last.status,200);
 assert.equal(last.body.page,30);
 assert.equal(last.body.events.length,10);
 assert.equal(last.body.has_more,false);
 assert.equal((await owner.call('/api/account/security-events?page=31')).status,400);
});

test('F173: reutilizar senha atual é rejeitado sem invalidar sessões; troca válida permanece auditada',async t=>{
 const f=await fixture(t),a=await f.client('Ana');
 const old='uma-senha-de-teste-longa',next='outra-senha-forte-12345';
 const oldCookie=a.cookie;
 assert.equal((await a.call('/api/login',{email:a.user.email,password:old})).status,200);
 const currentCookie=a.cookie;
 const same=await a.call('/api/password',{currentPassword:old,newPassword:old});
 assert.equal(same.status,400);
 assert.equal(a.cookie,currentCookie);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,2);
 assert.equal((await a.call('/api/session')).body.user.id,a.user.id);
 assert.deepEqual((await a.call('/api/account/security-events')).body.events.map(e=>e.action),['account.created']);
 const changed=await a.call('/api/password',{currentPassword:old,newPassword:next});
 assert.equal(changed.status,200);
 assert.notEqual(a.cookie,currentCookie);
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,1);
 assert.deepEqual((await a.call('/api/account/security-events')).body.events.map(e=>e.action),
  ['password.changed','account.created']);
 const stale=await fetch(f.url+'/api/session',{headers:{Cookie:oldCookie}});
 assert.equal((await stale.json()).user,null);
 assert.equal((await a.call('/api/login',{email:a.user.email,password:old})).status,401);
});

test('F174: duas ou mais alterações simultâneas não podem reabrir credenciais revogadas',async t=>{
 const f=await fixture(t),a=await f.client('Ana');
 const old='uma-senha-de-teste-longa',cookie=a.cookie;
 const candidates=['nova-senha-concorrente-a','nova-senha-concorrente-b','nova-senha-concorrente-c'];
 const headers={Origin:'http://127.0.0.1:4173','Content-Type':'application/json',Cookie:cookie,'X-CSRF-Token':a.csrf};
 const outcomes=await Promise.all(candidates.map(async newPassword=>{
  const r=await fetch(f.url+'/api/password',{method:'POST',headers,body:JSON.stringify({currentPassword:old,newPassword})});
  return {newPassword,status:r.status};
 }));
 const successful=outcomes.filter(r=>r.status===200);
 assert.equal(successful.length,1,JSON.stringify(outcomes));
 assert.ok(outcomes.filter(r=>r.status!==200).every(r=>[401,409].includes(r.status)));
 assert.equal(f.db.prepare('SELECT count(*) AS n FROM sessions WHERE user_id=?').get(a.user.id).n,1);
 assert.equal(f.db.prepare("SELECT count(*) AS n FROM audit WHERE actor_id=? AND action='password.changed'").get(a.user.id).n,1);
 assert.equal((await a.call('/api/login',{email:a.user.email,password:old})).status,401);
 assert.equal((await a.call('/api/login',{email:a.user.email,password:successful[0].newPassword})).status,200);
 assert.equal((await a.call('/api/account/security-events')).body.events[0].action,'password.changed');
});

test('F176: exportação do cadastro e dos projetos pertence exclusivamente ao cliente autenticado',async t=>{
 const f=await fixture(t),a=await f.client('Cliente A'),b=await f.client('Cliente B');
 const ownUserId=a.user.id;
 const first=await order(a),other=await order(b);
 f.db.prepare('INSERT INTO account_profiles(user_id,data,version,updated_at) VALUES(?,?,1,?)')
  .run(ownUserId,JSON.stringify({company:'Empresa A',api_secret:'NEVER_EXPORT_ME'}),new Date().toISOString());
 const own=await a.call('/api/account/export');
 assert.equal(own.status,200);
 assert.match(own.headers.get('content-disposition')||'',/attachment; filename="imagine-cadastro-projetos.json"/);
 assert.match(own.headers.get('cache-control')||'',/no-store/);
 assert.equal(own.body.schema_version,1);
 assert.equal(own.body.scope,'cadastro_e_projetos');
 assert.equal(own.body.profile.company,'Empresa A');
 assert.equal(JSON.stringify(own.body).includes('NEVER_EXPORT_ME'),false);
 assert.equal(own.body.user.email,a.user.email);
 assert.equal(own.body.projects.some(p=>p.id===first.id),true);
 assert.equal(own.body.projects.some(p=>p.id===other.id),false);
 assert.equal(own.body.projects.every(p=>!p.data?.password_hash),true);
 assert.equal(JSON.stringify(own.body).includes(b.user.email),false);
 for(const forbidden of ['password_hash','csrf','imagine_session','token_key']){
  assert.equal(JSON.stringify(own.body).includes(forbidden),false,forbidden);
 }
 const otherExport=await b.call('/api/account/export');
 assert.equal(otherExport.status,200);
 assert.equal(otherExport.body.projects.some(p=>p.id===other.id),true);
 assert.equal(otherExport.body.projects.some(p=>p.id===first.id),false);
 const anonymous=await fetch(f.url+'/api/account/export');
 assert.equal(anonymous.status,401);
 const staff=await f.client('Equipe','admin');
 f.db.prepare('INSERT INTO staff(user_id,profile,active) VALUES(?,?,1)').run(staff.user.id,'atendimento');
 assert.equal((await staff.call('/api/account/export')).status,403);
 assert.equal(f.db.prepare("SELECT count(*) AS n FROM audit WHERE actor_id=? AND action='account.data_exported'").get(ownUserId).n,1);
});

test('F177: CSV de projetos é isolado, seguro contra fórmulas e registrado na atividade da conta',async t=>{
 const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia');
 const first=await order(a),second=await order(b);
 const title='=HYPERLINK("https://example.test","abrir")';
 f.db.prepare('UPDATE orders SET title=? WHERE id=?').run(title,first.id);
 const own=await a.call('/api/account/projects.csv');
 assert.equal(own.status,200);
 assert.match(own.headers.get('content-type')||'',/text\/csv/);
 assert.match(own.headers.get('content-disposition')||'',/attachment; filename="imagine-projetos.csv"/);
 assert.match(own.headers.get('cache-control')||'',/no-store/);
 // fetch().text() intentionally consumes UTF-8 BOM; the serializer unit test checks raw BOM.
 assert.ok(own.body.startsWith('"ID";"Projeto";'));
 assert.ok(own.body.includes('"\'=HYPERLINK(""https://example.test"",""abrir"")"'));
 assert.ok(own.body.includes(first.id));
 assert.equal(own.body.includes(second.id),false);
 const other=await b.call('/api/account/projects.csv');
 assert.equal(other.status,200);
 assert.ok(other.body.includes(second.id));
 assert.equal(other.body.includes(first.id),false);
 assert.equal((await a.call('/api/account/security-events')).body.events[0].action,'account.projects_csv_exported');
 assert.equal((await fetch(f.url+'/api/account/projects.csv')).status,401);
 const staff=await f.client('Equipe','admin');
 f.db.prepare('INSERT INTO staff(user_id,profile,active) VALUES(?,?,1)').run(staff.user.id,'atendimento');
 assert.equal((await staff.call('/api/account/projects.csv')).status,403);
});

test('F178: quota compartilhada limita exportações sem afetar clientes diferentes',async t=>{
 const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia');
 const ownerId=a.user.id;
 for(let i=0;i<20;i++){
  const route=i%2?'/api/account/projects.csv':'/api/account/export';
  const result=await a.call(route);
  assert.equal(result.status,200,route+' #'+i);
 }
 const blocked=await a.call('/api/account/export');
 assert.equal(blocked.status,429);
 assert.equal((await a.call('/api/account/projects.csv')).status,429);
 assert.equal((await b.call('/api/account/export')).status,200);
 assert.equal(f.db.prepare("SELECT count(*) AS n FROM audit WHERE actor_id=? AND action IN ('account.data_exported','account.projects_csv_exported')").get(ownerId).n,20);
 const otherHistory=await b.call('/api/account/security-events');
 assert.equal(otherHistory.body.events[0].action,'account.data_exported');
 assert.equal(otherHistory.body.events.some(e=>e.action==='account.projects_csv_exported'),false);
});

test('Cadastro, login, autorização, CSRF e isolamento entre clientes',async t=>{const f=await fixture(t),a=await f.client('Ana'),b=await f.client('Bia'),o=await order(a);assert.equal((await b.call('/api/orders/'+o.id)).status,404);assert.equal((await b.call('/api/admin/dashboard')).status,403);assert.equal((await b.call('/api/admin/finance.csv')).status,403);assert.equal((await b.call('/api/orders/'+o.id+'/messages',{body:'invasão'})).status,404);const rows=await b.call('/api/orders');assert.equal(rows.body.orders.length,0);const attack=await fetch(f.url+'/api/orders',{method:'POST',headers:{Origin:'https://evil.test','Content-Type':'application/json',Cookie:a.cookie},body:'{}'});assert.equal(attack.status,403);const saved=a.csrf;a.csrf='errado';assert.equal((await a.call('/api/logout',{})).status,403);a.csrf=saved;assert.equal((await a.call('/api/session')).body.user.role,'client');assert.equal((await fetch(f.url+'/.env')).status,404);assert.equal((await fetch(f.url+'/var/portal.sqlite')).status,404);assert.equal((await fetch(f.url+'/server/app.mjs')).status,404)});
test('Preço vem do servidor; repetir checkout não duplica cobrança; proposta fica imutável',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),admin=await f.client('Gestor','admin'),o=await order(c);const first=await pay(c,o,{price:1,price_cents:1});assert.equal(first.status,200);assert.equal(f.gateway.payments.length,1);assert.equal(f.gateway.payments[0].value,990);assert.equal((await pay(c,o)).status,200);assert.equal(f.gateway.payments.length,1);assert.equal((await admin.call('/api/orders/'+o.id+'/proposal',{price_cents:1,scope:'Escopo adulterado depois de aceito.',delivery:'1 dia'})).status,409);assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM payments').get().n,1);assert.equal((await c.call('/api/orders/'+o.id)).body.order.payment_status,'PENDING')});
test('Timeout após cobrança criada exige conciliação e não permite cobrança duplicada',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c),create=f.gateway.createPayment;f.gateway.createPayment=async data=>{await create(data);throw Object.assign(Error('Timeout de teste'),{uncertain:true,status:502})};assert.equal((await pay(c,o)).status,502);assert.equal((await pay(c,o)).status,409);assert.equal(f.gateway.payments.length,1);assert.equal((await c.call('/api/orders/'+o.id+'/sync',{})).status,200);assert.equal((await c.call('/api/orders/'+o.id)).body.order.billing_state,'ready');assert.equal((await pay(c,o)).status,200);assert.equal(f.gateway.payments.length,1)});
test('Webhooks autenticados, idempotentes e resistentes a notificações fora de ordem',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c);await pay(c,o);const p=f.gateway.payments[0];const payload={id:'evt_test',event:'PAYMENT_RECEIVED',payment:{id:p.id,status:'RECEIVED'}};const webhook=body=>fetch(f.url+'/api/webhooks/asaas',{method:'POST',headers:{'Content-Type':'application/json','asaas-access-token':f.secret},body:JSON.stringify(body)});assert.equal((await fetch(f.url+'/api/webhooks/asaas',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})).status,401);p.status='RECEIVED';p.netValue=987;assert.equal((await webhook(payload)).status,200);assert.equal((await webhook(payload)).status,200);await f.drainEvents();assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM events').get().n,1);assert.equal((await c.call('/api/orders/'+o.id)).body.order.payment_status,'RECEIVED');await webhook({...payload,id:'evt_old',event:'PAYMENT_CREATED',payment:{id:p.id,status:'PENDING'}});await f.drainEvents();assert.equal((await c.call('/api/orders/'+o.id)).body.order.payment_status,'RECEIVED');assert.equal(f.metrics().received,99000);assert.equal(f.metrics().confirmed,0);p.status='REFUNDED';await webhook({...payload,id:'evt_refund',event:'PAYMENT_REFUNDED'});await f.drainEvents();assert.equal(f.metrics().received,0);assert.equal((await c.call('/api/orders/'+o.id)).body.order.payment_status,'REFUNDED')});
test('Manutenção é opcional; primeira cobrança após 60 dias, mensal no cartão; cancelamento',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c);await pay(c,o);assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM maintenance').get().n,0);assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true})).status,409);f.gateway.payments[0].status='RECEIVED';await c.call('/api/orders/'+o.id+'/sync',{});assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:false})).status,400);assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true})).status,200);assert.equal(f.gateway.checkouts.length,1);const co=f.gateway.checkouts[0];assert.equal(co.subscription.cycle,'MONTHLY');assert.equal(co.subscription.nextDueDate,'2026-12-29 12:00:00');assert.deepEqual(co.billingTypes,['CREDIT_CARD']);assert.equal(co.items[0].value,39.9);assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true})).status,200);assert.equal(f.gateway.checkouts.length,1);const customer=f.gateway.customers[0].id;f.gateway.subscriptions.push({id:'sub_test',customer,value:39.90,cycle:'MONTHLY',billingType:'CREDIT_CARD',status:'ACTIVE',deleted:false,nextDueDate:'2026-12-29'});f.gateway.payments.push({id:'pay_month',customer,subscription:'sub_test',value:39.9,status:'PENDING',dueDate:'2026-12-29',invoiceUrl:'https://sandbox.asaas.com/i/month'});await c.call('/api/orders/'+o.id+'/sync',{});assert.equal((await c.call('/api/orders/'+o.id)).body.order.maintenance.status,'active');assert.equal((await c.call('/api/orders/'+o.id+'/cancel-maintenance',{})).status,200);assert.equal(f.gateway.subscriptions[0].deleted,true);const canceled=(await c.call('/api/orders/'+o.id)).body.order;assert.equal(canceled.maintenance.status,'canceled');assert.equal(canceled.payments.find(p=>p.id==='pay_month').status,'DELETED');assert.equal(canceled.payments.find(p=>p.id!== 'pay_month').status,'RECEIVED')});
test('Cliente não promove privilégios; administrador envia propostas e cliente conversa',async t=>{const f=await fixture(t),c=await f.client(),admin=await f.client('Gestor','admin'),o=await order(c,'sistema');assert.equal(o.price_cents,null);assert.equal((await c.call('/api/orders/'+o.id+'/proposal',{price_cents:20})).status,403);const proposal=await admin.call('/api/orders/'+o.id+'/proposal',{price_cents:450000,scope:'Sistema de gestão com cadastro, relatórios e permissões. Escopo a detalhar.',delivery:'30 dias úteis após briefing.'});assert.equal(proposal.status,200);const updated=(await c.call('/api/orders/'+o.id)).body.order;assert.equal(updated.price_cents,450000);assert.equal((await pay(c,o)).status,409);assert.equal((await pay(c,updated)).status,200);assert.equal((await admin.call('/api/orders/'+o.id+'/phase',{phase:'entregue'})).status,409);assert.equal((await c.call('/api/orders/'+o.id+'/messages',{body:'<script>alert(1)</script>'})).status,201);assert.equal((await admin.call('/api/orders/'+o.id)).body.order.messages[0].body,'<script>alert(1)</script>');assert.equal((await c.call('/api/password',{currentPassword:'uma-senha-de-teste-longa',newPassword:'nova-senha-protegida-123'})).status,200);assert.equal((await c.call('/api/session')).body.user.role,'client')});
test('Cadastro seguro, rate limiting e snapshot de proposta',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c);const data={email:'teste@example.test',name:'Teste',password:'curta'};const response=await fetch(f.url+'/api/register',{method:'POST',headers:{Origin:f.origin,'Content-Type':'application/json'},body:JSON.stringify(data)});assert.equal(response.status,400);const hash=f.db.prepare('SELECT password_hash FROM users WHERE id=?').get(c.user.id).password_hash;assert.ok(!hash.includes('uma-senha'));assert.equal((await pay(c,o,{cpfCnpj:'11111111111'})).status,400);f.db.prepare("UPDATE products SET scope='Novo escopo para outros pedidos' WHERE id='site'").run();assert.ok((await c.call('/api/orders/'+o.id)).body.order.scope.startsWith('Site Onepage'));for(let n=0;n<16;n++)await c.call('/api/orders/'+o.id+'/messages',{body:'teste'});const before=c.cookie;await c.call('/api/logout',{});const stale=await fetch(f.url+'/api/orders',{headers:{Cookie:before}});assert.equal(stale.status,401)});
test('Datas de 60 dias atravessam mês e ano corretamente',()=>{assert.equal(addDays(fixed,60),'2026-12-29');assert.equal(addDays('2026-12-20T15:00:00Z',60),'2027-02-18')});
test('Adaptador Asaas usa Sandbox, autenticação só no servidor e não repete mutações',async()=>{const calls=[];const asaas=createAsaas({apiKey:'segredo-falso-para-teste',fetchImpl:async(url,options)=>{calls.push({url,options});return {ok:true,json:async()=>({id:'pay_test'})}}});await asaas.createPayment({value:990});assert.equal(calls.length,1);assert.equal(calls[0].url,'https://api-sandbox.asaas.com/v3/payments');assert.equal(calls[0].options.headers.access_token,'segredo-falso-para-teste');const faulty=createAsaas({apiKey:'fake',fetchImpl:async()=>{throw Error('timeout')}});await assert.rejects(faulty.createPayment({}),e=>e.uncertain===true);assert.equal(asaas.checkoutUrl('id'),'https://sandbox.asaas.com/checkoutSession/show?id=id')});
test('Cliques concorrentes criam só uma cobrança',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c),create=f.gateway.createPayment;let release;const gate=new Promise(r=>release=r);let signal;const entered=new Promise(r=>signal=r);f.gateway.createPayment=async data=>{signal();await gate;return create(data)};const first=pay(c,o);await entered;const second=await pay(c,o);assert.equal(second.status,409);release();assert.equal((await first).status,200);assert.equal(f.gateway.payments.length,1)});
test('Timeout no checkout de manutenção é recuperado pelo webhook sem recriar',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c);await pay(c,o);f.gateway.payments[0].status='RECEIVED';await c.call('/api/orders/'+o.id+'/sync',{});const create=f.gateway.createCheckout;f.gateway.createCheckout=async data=>{await create(data);throw Object.assign(Error('Timeout de teste'),{status:502,uncertain:true})};assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true})).status,502);assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true})).status,409);const co=f.gateway.checkouts[0];const response=await fetch(f.url+'/api/webhooks/asaas',{method:'POST',headers:{'Content-Type':'application/json','asaas-access-token':f.secret},body:JSON.stringify({id:'evt_checkout_recover',event:'CHECKOUT_CREATED',checkout:co})});assert.equal(response.status,200);await f.drainEvents();const recovered=(await c.call('/api/orders/'+o.id)).body.order.maintenance;assert.equal(recovered.status,'checkout');assert.ok(recovered.checkout_url.includes(co.id));assert.equal((await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true})).status,200);assert.equal(f.gateway.checkouts.length,1)});
test('Cancelamento antes da assinatura chama o Asaas uma só vez',async t=>{const f=await fixture(t);enableSite(f.db);const c=await f.client(),o=await order(c);await pay(c,o);f.gateway.payments[0].status='RECEIVED';await c.call('/api/orders/'+o.id+'/sync',{});await c.call('/api/orders/'+o.id+'/maintenance',{accepted:true});let calls=0;const cancel=f.gateway.cancelCheckout;f.gateway.cancelCheckout=async id=>{calls++;if(calls>1)throw Error('Já cancelado');return cancel(id)};assert.equal((await c.call('/api/orders/'+o.id+'/cancel-maintenance',{})).status,200);assert.equal(calls,1);assert.equal((await c.call('/api/orders/'+o.id)).body.order.maintenance.status,'canceled')});
const briefingData={company_name:'Empresa de teste',company_intro:'Apresentação fornecida pelo cliente.',services:'Sites e soluções digitais.',current_site:'https://example.test',contact_site:'https://example.test',domain:'example.test',email:'contato@example.test',phone:'8530320000',address:'Endereço comercial informado pelo cliente',whatsapp:'5585999990000',notes:'Usar a identidade original.',logo_unavailable:true,manual_unavailable:true,ai_support:false};
async function paidSite(f,c){enableSite(f.db);const o=await order(c);await pay(c,o);f.gateway.payments.at(-1).status='RECEIVED';await c.call('/api/orders/'+o.id+'/sync',{});return o}
async function uploadFile(f,c,id,name,body,kind='logo',headers={}){return fetch(f.url+'/api/orders/'+id+'/briefing-files',{method:'POST',headers:{Origin:f.origin,Cookie:c.cookie,'X-CSRF-Token':c.csrf,'X-File-Kind':kind,'X-File-Name':encodeURIComponent(name),'Content-Type':'application/octet-stream',...headers},body})}
test('Briefing só é liberado após pagamento e não fica acessível para outro cliente',async t=>{const f=await fixture(t),a=await f.client(),b=await f.client(),o=await order(a);assert.equal((await a.call('/api/orders/'+o.id+'/briefing')).status,403);assert.equal((await a.call('/api/orders/'+o.id+'/briefing',{data:briefingData,version:0})).status,403);assert.equal((await uploadFile(f,a,o.id,'logo.png',Buffer.from('fake'))).status,403);enableSite(f.db);const paid=await paidSite(f,a);assert.equal((await a.call('/api/orders/'+paid.id+'/briefing')).status,200);assert.equal((await b.call('/api/orders/'+paid.id+'/briefing')).status,404);assert.equal((await b.call('/api/orders/'+paid.id+'/briefing',{data:briefingData,version:0})).status,404)});
test('Rascunho, envio, pedido de complemento e aprovação preservam controle de versão',async t=>{const f=await fixture(t),c=await f.client(),admin=await f.client('Gestor','admin'),o=await paidSite(f,c);const save=await c.call('/api/orders/'+o.id+'/briefing',{data:briefingData,version:0});assert.equal(save.status,200);let b=save.body.briefing;assert.equal(b.data.address,briefingData.address);assert.equal((await c.call('/api/orders/'+o.id+'/briefing',{data:briefingData,version:0})).status,409);const submit=await c.call('/api/orders/'+o.id+'/submit-briefing',{version:b.version,confirmed:true});assert.equal(submit.status,200);b=submit.body.briefing;assert.equal(b.status,'submitted');assert.equal((await c.call('/api/orders/'+o.id+'/briefing',{data:briefingData,version:b.version})).status,409);assert.equal((await c.call('/api/orders/'+o.id+'/review-briefing',{version:b.version,status:'approved'})).status,403);const review=await admin.call('/api/orders/'+o.id+'/review-briefing',{version:b.version,status:'revision_requested',note:'Complemente os serviços disponíveis.'});assert.equal(review.status,200);b=review.body.briefing;const revised=await c.call('/api/orders/'+o.id+'/briefing',{version:b.version,data:{...briefingData,services:'Serviços revisados pelo cliente.'}});b=revised.body.briefing;b=(await c.call('/api/orders/'+o.id+'/submit-briefing',{version:b.version,confirmed:true})).body.briefing;const approved=await admin.call('/api/orders/'+o.id+'/review-briefing',{version:b.version,status:'approved',note:'Material conferido.'});assert.equal(approved.status,200);assert.equal(approved.body.briefing.status,'approved');assert.equal((await c.call('/api/orders/'+o.id+'/briefing',{version:approved.body.briefing.version,data:briefingData})).status,409)});
test('Upload privado, download autenticado, exclusão e estorno bloqueando acesso',async t=>{const f=await fixture(t),c=await f.client(),other=await f.client(),admin=await f.client('Gestor','admin'),o=await paidSite(f,c);const pdf=Buffer.from('%PDF-1.7\nMaterial de teste');const uploaded=await uploadFile(f,c,o.id,'Manual da empresa.pdf',pdf,'manual');assert.equal(uploaded.status,201);const b=(await uploaded.json()).briefing,file=b.files[0];assert.equal(file.original_name,'Manual da empresa.pdf');assert.ok(!('storage_name' in file));const url=f.url+'/api/orders/'+o.id+'/materials/'+file.id;assert.equal((await fetch(url)).status,401);assert.equal((await fetch(url,{headers:{Cookie:other.cookie}})).status,404);const download=await fetch(url,{headers:{Cookie:c.cookie}});assert.equal(download.status,200);assert.ok(download.headers.get('content-disposition').startsWith('attachment;'));assert.equal(download.headers.get('content-type'),'application/octet-stream');assert.deepEqual(Buffer.from(await download.arrayBuffer()),pdf);assert.equal((await fetch(url,{headers:{Cookie:admin.cookie}})).status,200);assert.equal((await fetch(f.url+'/var/uploads/'+file.id+'.bin')).status,404);const disk=await readdir(f.uploadDir);assert.equal(disk.length,1);assert.notEqual(disk[0],file.original_name);f.gateway.payments[0].status='REFUNDED';await c.call('/api/orders/'+o.id+'/sync',{});assert.equal((await fetch(url,{headers:{Cookie:c.cookie}})).status,403);f.gateway.payments[0].status='RECEIVED';await c.call('/api/orders/'+o.id+'/sync',{});assert.equal((await c.call('/api/orders/'+o.id+'/materials/'+file.id,{},'DELETE')).status,200);assert.equal((await readdir(f.uploadDir)).length,0)});
test('Formatos, nomes, tamanho e CSRF são conferidos no upload',async t=>{const f=await fixture(t),c=await f.client(),o=await paidSite(f,c);assert.equal((await uploadFile(f,c,o.id,'logo.png',Buffer.from('<html>fake</html>'))).status,400);assert.equal((await uploadFile(f,c,o.id,'../logo.pdf',Buffer.from('%PDF-1.7'))).status,400);assert.equal((await uploadFile(f,c,o.id,'script.svg',Buffer.from('<svg/>'))).status,400);assert.equal((await uploadFile(f,c,o.id,'logo.pdf',Buffer.from('%PDF-1.7'),'logo',{'X-CSRF-Token':'errado'})).status,403);assert.equal((await uploadFile(f,c,o.id,'manual.pdf',Buffer.alloc(MAX_FILE_BYTES+1),'manual')).status,413);assert.equal((await readdir(f.uploadDir)).length,0)});
test('Solicitação de apoio IA aceita apresentação ausente; campos e confirmação são exigidos',async t=>{const f=await fixture(t),c=await f.client(),o=await paidSite(f,c);let saved=await c.call('/api/orders/'+o.id+'/briefing',{data:{...briefingData,company_intro:'',ai_support:true},version:0});assert.equal(saved.status,200);assert.equal((await c.call('/api/orders/'+o.id+'/submit-briefing',{version:saved.body.briefing.version,confirmed:false})).status,400);assert.equal((await c.call('/api/orders/'+o.id+'/submit-briefing',{version:saved.body.briefing.version,confirmed:true})).status,200);const second=await paidSite(f,c);saved=await c.call('/api/orders/'+second.id+'/briefing',{version:0,data:{...briefingData,company_intro:'',ai_support:false}});assert.equal((await c.call('/api/orders/'+second.id+'/submit-briefing',{version:saved.body.briefing.version,confirmed:true})).status,400);assert.equal((await c.call('/api/orders/'+second.id+'/briefing',{version:saved.body.briefing.version,data:{...briefingData,current_site:'javascript:alert(1)'}})).status,400)});
test('Migração de briefing é idempotente e preserva pedidos existentes',async t=>{const f=await fixture(t),c=await f.client(),o=await order(c);migrateBriefing(f.db);migrateBriefing(f.db);assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM schema_version WHERE version=2').get().n,1);assert.equal(f.db.prepare('SELECT title FROM orders WHERE id=?').get(o.id).title,'Site Onepage')});

test('Jornada exige materiais validados, titular e aprovação da versão antes de publicar',async t=>{
 const f=await fixture(t),c=await f.client(),other=await f.client(),admin=await f.client('Gestor','admin'),o=await paidSite(f,c),path='/api/orders/'+o.id+'/production';
 assert.equal((await other.call(path,{action:'start',version:0})).status,404);
 assert.equal((await c.call(path,{action:'start',version:0})).status,403);
 assert.equal((await admin.call(path,{action:'start',version:0})).status,409);
 let b=(await c.call('/api/orders/'+o.id+'/briefing',{data:briefingData,version:0})).body.briefing;
 b=(await c.call('/api/orders/'+o.id+'/submit-briefing',{version:b.version,confirmed:true})).body.briefing;
 await admin.call('/api/orders/'+o.id+'/review-briefing',{status:'approved',version:b.version});
 let r=await admin.call(path,{action:'start',version:0});assert.equal(r.status,200);let p=r.body.production;assert.equal(p.stage,'production');assert.ok(p.due_date);
 assert.equal((await admin.call(path,{action:'progress',version:0,progress:25,note:'Estrutura concluída.'})).status,409);
 assert.equal((await admin.call('/api/orders/'+o.id+'/phase',{phase:'entregue'})).status,409);
 r=await admin.call(path,{action:'progress',version:p.version,progress:50,note:'Layout e conteúdo montados.'});assert.equal(r.status,200);p=r.body.production;
 assert.equal((await admin.call(path,{action:'preview',version:p.version,url:'javascript:alert(1)',note:'Prévia pronta.'})).status,400);
 r=await admin.call(path,{action:'preview',version:p.version,url:'https://preview.example.test/v1',note:'Primeira prévia disponível.'});assert.equal(r.status,200);p=r.body.production;
 assert.equal((await admin.call(path,{action:'publish',version:p.version,url:'https://example.test'})).status,409);
 assert.equal((await admin.call(path,{action:'approve',version:p.version,release:1,confirmed:true})).status,403);
 assert.equal((await c.call(path,{action:'approve',version:p.version,release:0,confirmed:true})).status,409);
 r=await c.call(path,{action:'request-changes',version:p.version,release:1,confirmed:true,note:'Trocar a imagem principal e revisar o telefone.'});assert.equal(r.status,200);p=r.body.production;assert.equal(p.adjustments,1);
 r=await admin.call(path,{action:'preview',version:p.version,url:'https://preview.example.test/v2',note:'Ajustes da primeira rodada concluídos.'});p=r.body.production;assert.equal(p.release,2);
 r=await c.call(path,{action:'approve',version:p.version,release:2,confirmed:true});assert.equal(r.status,200);p=r.body.production;assert.equal(p.approved_release,2);
 r=await admin.call(path,{action:'publish',version:p.version,url:'https://example.test'});assert.equal(r.status,200);assert.equal(r.body.production.stage,'published');assert.equal((await c.call('/api/orders/'+o.id)).body.order.phase,'entregue');
 assert.equal(r.body.production.events.filter(e=>e.type==='approve').length,1);migrateProduction(f.db);assert.equal(f.db.prepare('SELECT COUNT(*) n FROM schema_version WHERE version=3').get().n,1);
});
test('Jornada bloqueia ações sem pagamento, após estorno e fora dos limites de ajustes',async t=>{
 const f=await fixture(t),c=await f.client(),admin=await f.client('Gestor','admin'),o=await order(c),path='/api/orders/'+o.id+'/production';assert.equal((await admin.call(path,{action:'start',version:0})).status,409);
 const po=await paidSite(f,c),pp='/api/orders/'+po.id+'/production';
 f.db.prepare("INSERT INTO productions(order_id,stage,release,adjustments,updated_at) VALUES(?,'review',3,2,?)").run(po.id,now());
 assert.equal((await c.call(pp,{action:'request-changes',version:0,release:3,confirmed:true,note:'Uma nova rodada de alterações.'})).status,409);
 assert.equal((await c.call(pp,{action:'approve',version:0,release:3,confirmed:false})).status,400);
 f.db.prepare("UPDATE orders SET payment_status='REFUNDED' WHERE id=?").run(po.id);
 assert.equal((await c.call(pp,{action:'approve',version:0,release:3,confirmed:true})).status,409);
});


test('F200: account and fiscal APIs whitelist persisted profile, fiscal and paid briefing fields',async t=>{
 const f=await fixture(t),owner=await f.client('Titular'),other=await f.client('Outro cliente'),id=owner.user.id;
 f.db.prepare('INSERT INTO account_profiles(user_id,data,version,updated_at) VALUES(?,?,?,?)')
  .run(id,JSON.stringify({company:'Minha empresa',whatsapp:'11999999999',phone:{token:'PROFILE_NESTED_SECRET'},website:'https://site.example',internal_oauth_token:'PROFILE_PRIVATE_SECRET',version:777}),6,now());
 f.db.prepare('INSERT INTO fiscal_customers(user_id,data,version,updated_at) VALUES(?,?,?,?)')
  .run(id,JSON.stringify({legal_name:'Minha empresa Ltda',document:'52998224725',email:'financeiro@example.test',whatsapp:{token:'FISCAL_NESTED_SECRET'},internal_bank_token:'FISCAL_PRIVATE_SECRET',version:999}),4,now());
 const project=await order(owner);
 f.db.prepare("UPDATE orders SET payment_status='RECEIVED' WHERE id=?").run(project.id);
 f.db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?) ON CONFLICT(order_id) DO UPDATE SET data=excluded.data,updated_at=excluded.updated_at')
  .run(project.id,JSON.stringify({company_name:'Empresa autorizada',domain:'empresa.test',notes:'Notas permitidas',internal_secret:'BRIEF_PRIVATE_SECRET',api_key:{secret:'BRIEF_NESTED_SECRET'},services:['ARRAY_NOT_FOR_CLIENT']}),now());
 const own=await owner.call('/api/account');
 assert.equal(own.status,200);
 assert.equal(own.body.profile.version,6);
 assert.equal(own.body.profile.company,'Minha empresa');
 assert.equal(own.body.profile.phone,'');
 assert.equal(own.body.profile.internal_oauth_token,undefined);
 assert.equal(own.body.fiscal.version,4);
 assert.equal(own.body.fiscal.legal_name,'Minha empresa Ltda');
 assert.equal(own.body.fiscal.whatsapp,'');
 assert.equal(own.body.fiscal.internal_bank_token,undefined);
 assert.equal(own.body.projects[0].data.company_name,'Empresa autorizada');
 assert.equal(own.body.projects[0].data.notes,'Notas permitidas');
 assert.equal(own.body.projects[0].data.internal_secret,undefined);
 assert.equal(own.body.projects[0].data.api_key,undefined);
 assert.equal(own.body.projects[0].data.services,undefined);
 const fiscal=await owner.call('/api/fiscal/customers/'+id);
 assert.equal(fiscal.status,200);
 assert.equal(fiscal.body.customer.version,4);
 assert.equal(fiscal.body.customer.internal_bank_token,undefined);
 assert.equal((await other.call('/api/fiscal/customers/'+id)).status,403);
 assert.equal((await other.call('/api/account')).body.projects.length,0);
 const blob=JSON.stringify(own)+JSON.stringify(fiscal.body);
 for(const secret of ['PROFILE_PRIVATE_SECRET','PROFILE_NESTED_SECRET','FISCAL_PRIVATE_SECRET','FISCAL_NESTED_SECRET','BRIEF_PRIVATE_SECRET','BRIEF_NESTED_SECRET','ARRAY_NOT_FOR_CLIENT'])
  assert.equal(blob.includes(secret),false,secret);
});

test('F201: bounded own-project endpoint paginates, filters and conceals unpaid materials',async t=>{
 const f=await fixture(t),owner=await f.client('Arquivo do cliente'),other=await f.client('Outro cliente');
 const insert=f.db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
 const paidNames=new Set([3,6,9,12,15,18,21]);
 const ids=[];
 for(let i=1;i<=21;i++){
  const id=randomUUID(),when='2026-11-11T10:'+String(i).padStart(2,'0')+':00Z';
  ids.push(id);
  insert.run(id,owner.user.id,'Site '+String(i).padStart(2,'0'),paidNames.has(i)?'RECEIVED':'PENDING',when,when);
  if(i===21||i===19){
   f.db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)')
    .run(id,JSON.stringify({company_name:'Empresa SECRETA'+i+(i===21?' Café São João':''),domain:'dominio-'+i+'.test',internal_token:'NEVER_BRIEF_TOKEN'}),when);
  }
 }
 const otherId=randomUUID();
 insert.run(otherId,other.user.id,'OTHER_PRIVATE_ORDER','PENDING','2026-11-12','2026-11-12');
 const first=await owner.call('/api/account/projects');
 assert.equal(first.status,200);assert.equal(first.body.total,21);assert.equal(first.body.pages,3);
 assert.equal(first.body.page_size,8);assert.equal(first.body.projects.length,8);
 assert.equal(first.body.projects[0].title,'Site 21');assert.equal(first.body.has_more,true);
 assert.equal(first.body.projects[0].data.company_name,'Empresa SECRETA21 Café São João');
 assert.equal(first.body.projects[0].data.internal_token,undefined);
 assert.equal(first.body.projects[2].title,'Site 19');
 assert.deepEqual(first.body.projects[2].data,{});
 const third=await owner.call('/api/account/projects?page=3');
 assert.equal(third.status,200);assert.equal(third.body.projects.length,5);assert.equal(third.body.has_more,false);
 const paid=await owner.call('/api/account/projects?payment=paid');
 assert.equal(paid.body.total,7);
 assert.ok(paid.body.projects.every(p=>p.payment_status==='RECEIVED'));
 const pending=await owner.call('/api/account/projects?payment=other');
 assert.equal(pending.body.total,14);
 assert.ok(pending.body.projects.every(p=>p.payment_status==='PENDING'));
 const name=await owner.call('/api/account/projects?search=site%2021');
 assert.equal(name.body.total,1);assert.equal(name.body.projects[0].id,ids[20]);
 const accents=await owner.call('/api/account/projects?search=CAFE%20SAO');
 assert.equal(accents.body.total,1);
 assert.equal(accents.body.projects[0].id,ids[20]);
 const domain=await owner.call('/api/account/projects?search=dominio-21.test');
 assert.equal(domain.body.total,1);
 const hidden=await owner.call('/api/account/projects?search=SECRETA19');
 assert.equal(hidden.body.total,0);
 const wildcard=await owner.call('/api/account/projects?search=%25');
 assert.equal(wildcard.body.total,0);
 const otherPage=await other.call('/api/account/projects');
 assert.equal(otherPage.body.total,1);assert.equal(otherPage.body.projects[0].id,otherId);
 const empty=await owner.call('/api/account/projects?page=4');
 assert.equal(empty.body.total,21);assert.equal(empty.body.projects.length,0);
 for(const params of ['page=0','page=1001','page=-1','page=01','page=x','payment=alll','search='+('x'.repeat(121))]){
  assert.equal((await owner.call('/api/account/projects?'+params)).status,400,params);
 }
 assert.equal((await fetch(f.url+'/api/account/projects')).status,401);
 const staff=await f.client('Colaborador','admin');
 assert.equal((await staff.call('/api/account/projects')).status,403);
 assert.equal(JSON.stringify(first.body).includes('NEVER_BRIEF_TOKEN'),false);
 assert.equal(JSON.stringify(first.body).includes('OTHER_PRIVATE_ORDER'),false);
});

test('F202: compact account view retains full project count and latest authorized paid briefing',async t=>{
 const f=await fixture(t),owner=await f.client('Responsável'),foreign=await f.client('Outro cliente');
 const stmt=f.db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
 const paid=randomUUID(),pending=randomUUID(),foreignId=randomUUID();
 stmt.run(paid,owner.user.id,'Projeto pago','RECEIVED','2026-11-10','2026-11-10');
 stmt.run(pending,owner.user.id,'Projeto mais recente','PENDING','2026-11-11','2026-11-11');
 stmt.run(foreignId,foreign.user.id,'Projeto estrangeiro','RECEIVED','2026-11-12','2026-11-12');
 f.db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)').run(paid,JSON.stringify({company_name:'Minha marca',domain:'marca.test',internal_key:'PRIVATE_INTERNAL'}),'2026-11-10');
 f.db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)').run(foreignId,JSON.stringify({company_name:'Outra marca',domain:'another.test'}),'2026-11-12');
 const compact=await owner.call('/api/account?compact=1');
 assert.equal(compact.status,200);assert.equal(compact.body.projects_total,2);
 assert.equal(compact.body.projects.length,1);
 assert.equal(compact.body.projects[0].id,paid);
 assert.equal(compact.body.projects[0].data.company_name,'Minha marca');
 assert.equal(compact.body.projects[0].data.internal_key,undefined);
 const regular=await owner.call('/api/account');
 assert.equal(regular.status,200);
 assert.equal(regular.body.projects.length,2);
 assert.equal(regular.body.projects_total,undefined);
 assert.equal((await foreign.call('/api/account?compact=1')).body.projects_total,1);
 assert.equal(JSON.stringify(compact.body).includes('Outra marca'),false);
 assert.equal(JSON.stringify(compact.body).includes('PRIVATE_INTERNAL'),false);
});

test('F205: compact fallback skips blank latest paid briefings and uses the latest real company suggestion',async t=>{
 const f=await fixture(t),owner=await f.client('Marca');
 const insert=f.db.prepare("INSERT INTO orders(id,user_id,product_id,title,brief,scope,delivery,payment_status,created_at,updated_at) VALUES(?,?,'site',?,'','','',?,?,?)");
 const oldId=randomUUID(),blankId=randomUUID(),pendingId=randomUUID();
 insert.run(oldId,owner.user.id,'Site antigo','RECEIVED','2026-11-09','2026-11-09');
 insert.run(blankId,owner.user.id,'Site novo pago','RECEIVED','2026-11-10','2026-11-10');
 insert.run(pendingId,owner.user.id,'Site pendente','PENDING','2026-11-11','2026-11-11');
 const insertBrief=f.db.prepare('INSERT INTO briefings(order_id,data,updated_at) VALUES(?,?,?)');
 insertBrief.run(oldId,JSON.stringify({company_name:'Empresa histórica',whatsapp:'11999999999'}),'2026-11-09');
 insertBrief.run(blankId,JSON.stringify({company_name:'',domain:'novo.test'}),'2026-11-10');
 const compact=await owner.call('/api/account?compact=1');
 assert.equal(compact.status,200);assert.equal(compact.body.projects_total,3);
 assert.equal(compact.body.projects.length,1);assert.equal(compact.body.projects[0].id,oldId);
 assert.equal(compact.body.projects[0].data.company_name,'Empresa histórica');
 const all=await owner.call('/api/account');
 assert.equal(all.body.projects.length,3);
 assert.equal(all.body.projects[0].id,pendingId);
});
