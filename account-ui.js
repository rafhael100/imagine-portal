// Browser download with explicit feedback for HTTP 401, 429 and server errors.
// Unlike a direct <a download>, error JSON is never offered as a downloaded file.
async function downloadAccountFile(kind){
 const resources={
  json:{path:'/api/account/export',filename:'imagine-cadastro-projetos.json',type:'application/json'},
  csv:{path:'/api/account/projects.csv?columns=stage',filename:'imagine-projetos.csv',type:'text/csv'},
 };
 const resource=Object.hasOwn(resources,kind)?resources[kind]:null;
 if(!resource)throw Error('Formato de exportação inválido.');
 const response=await fetch(resource.path,{credentials:'same-origin',headers:{Accept:resource.type}});
 if(!response.ok){
  let details;
  try{details=await response.json();}catch{details={};}
  if(response.status===401&&state.user){state.user=null;renderAuth();}
  throw Error(response.status===401?'Sua sessão expirou. Entre novamente para baixar seus dados.':details.error||'Não foi possível exportar seus dados.');
 }
 if(!(response.headers.get('content-type')||'').includes(resource.type))
  throw Error('O servidor retornou um arquivo em formato inesperado.');
 const blob=await response.blob();
 const url=URL.createObjectURL(blob);
 const link=document.createElement('a');
 link.href=url;
 link.download=resource.filename;
 document.body.appendChild(link);
 try{link.click();}finally{link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);}
}
// F188: limit DOM rendering for large accounts; text filters only the
// authenticated account's already-authorized project view.
const ACCOUNT_PROJECTS_PER_PAGE=8;
function filteredAccountProjects(projects,query='',payment='all',requestedPage=1,phase='all',sort='newest'){
 const norm=value=>String(value??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLocaleLowerCase('pt-BR');
 const term=norm(query).trim().slice(0,120);
 const paid=new Set(['CONFIRMED','RECEIVED','RECEIVED_IN_CASH']);
 const matches=(Array.isArray(projects)?projects:[]).filter(p=>{
  const isPaid=paid.has(p.payment_status);
  if(payment==='paid'&&!isPaid||payment==='other'&&isPaid)return false;
  if(payment!=='all'&&payment!=='paid'&&payment!=='other')return false;
  if(phase!=='all'&&p.phase!==phase)return false;
  return !term||[p.title,p.id,p.data?.company_name,p.data?.domain].some(v=>norm(v).includes(term));
 });
 matches.sort((a,b)=>{
  // Historical in-memory callers can omit dates; preserve their stable input order.
  const aDate=String(a.created_at||''),bDate=String(b.created_at||'');
  const chronological=aDate&&bDate?(aDate.localeCompare(bDate)||String(a.id).localeCompare(String(b.id))):0;
  return sort==='oldest'?chronological:-chronological;
 });
 const pages=Math.max(1,Math.ceil(matches.length/ACCOUNT_PROJECTS_PER_PAGE));
 const page=Math.min(pages,Math.max(1,Number.isSafeInteger(requestedPage)?requestedPage:1));
 return {items:matches.slice((page-1)*ACCOUNT_PROJECTS_PER_PAGE,page*ACCOUNT_PROJECTS_PER_PAGE),count:matches.length,page,pages};
}
function accountProjectPaymentLabel(code){
 const labels={RECEIVED:'Pago',RECEIVED_IN_CASH:'Pago',CONFIRMED:'Pagamento confirmado',PENDING:'Pagamento pendente',OVERDUE:'Pagamento em atraso',REFUNDED:'Pagamento estornado',NOT_CREATED:'Pagamento não iniciado'};
 return Object.hasOwn(labels,code)?labels[code]:'Pagamento em análise';
}
function accountProjectPhaseLabel(code){
 const labels={solicitado:'Solicitado',planejamento:'Em planejamento',desenvolvimento:'Em desenvolvimento',revisao:'Em revisão',entregue:'Entregue'};
 return Object.hasOwn(labels,code)?labels[code]:'Etapa a confirmar';
}
function accountProjectsSummary(summary){
 const fields=[['solicitado','Solicitados'],['planejamento','Planejamento'],['desenvolvimento','Desenvolvimento'],['revisao','Revisão'],['entregue','Entregues']];
 return fields.map(([key,label])=>'<span><strong>'+escapeHtml(Number(summary?.[key]||0))+'</strong> '+label+'</span>').join(' · ');
}
function accountProductionLabel(stage){
 const labels={preparation:'Preparação',production:'Em produção',changes:'Ajustes em andamento',review:'Aguardando revisão',approved:'Aprovado para publicação',published:'Publicado'};
 return Object.hasOwn(labels,stage)?labels[stage]:'Acompanhamento indisponível';
}
function accountProjectCards(items){
 return items.map(o=>{const data=o.data||{};return `<article><div class="panel-head"><h3>${escapeHtml(o.title)}</h3><span class="fine">#${escapeHtml(o.id.slice(0,8))} · ${escapeHtml(accountProjectPaymentLabel(o.payment_status))} · ${escapeHtml(accountProjectPhaseLabel(o.phase))}</span></div>${o.production?`<div class="account-production"><span>${escapeHtml(accountProductionLabel(o.production.stage))} · ${escapeHtml(o.production.progress)}%</span><progress value="${escapeHtml(o.production.progress)}" max="100" aria-label="Progresso da produção do site"></progress>${o.production.due_date?`<span class="fine">Previsão: ${escapeHtml(o.production.due_date)}</span>`:''}</div>`:''}<dl class="account-data">${[['Empresa',data.company_name],['Domínio',data.domain],['E-mail de contato',data.email],['WhatsApp',data.whatsapp],['Telefone',data.phone],['Endereço da empresa',data.address],['Site atual',data.current_site]].map(([label,value])=>`<div><dt>${label}</dt><dd>${escapeHtml(value||'Não informado')}</dd></div>`).join('')}</dl><details><summary>Apresentação, serviços e observações</summary><h4>Apresentação da empresa</h4><p>${escapeHtml(data.company_intro||'Não informada')}</p><h4>Serviços</h4><p>${escapeHtml(data.services||'Não informados')}</p>${data.notes?`<h4>Observações</h4><p>${escapeHtml(data.notes)}</p>`:''}</details><button class="btn secondary" type="button" data-account-project="${escapeHtml(o.id)}">Abrir materiais e projeto ↗</button></article>`}).join('');
}
// F190: one client/staff renderer for a bounded, owner-only audit timeline.
// The backend filters the action allowlist and never returns audit identifiers.
function bindAccountSecurityHistory(selector){
 const target=document.querySelector(selector);
 let page=0,events=[],more=false;
 const labels={'account.created':'Conta criada','password.changed':'Senha atualizada','sessions.revoked_others':'Outras sessões encerradas','account.email.changed':'E-mail de acesso alterado','account.data_exported':'Cadastro exportado','account.projects_csv_exported':'Planilha de projetos exportada'};
 const render=()=>{
  if(!target?.isConnected||state.tab!=='account')return;
  target.innerHTML=events.length?'<ul>'+events.map(e=>{
   const timestamp=new Date(e.created_at);
   const when=Number.isNaN(timestamp.valueOf())?'Data não disponível':new Intl.DateTimeFormat('pt-BR',{timeZone:'America/Sao_Paulo',dateStyle:'short',timeStyle:'short'}).format(timestamp);
   return '<li>'+escapeHtml(labels[e.action]||'Evento de segurança')+' · '+escapeHtml(when)+'</li>';
  }).join('')+'</ul>':'Nenhuma alteração de segurança registrada.';
  if(more&&page<30)target.innerHTML+='<button class="btn secondary" type="button" data-account-history-more>Mostrar atividades anteriores</button>';
 };
 const loadMore=async()=>{
  if(!target?.isConnected||state.tab!=='account'||page>=30)return;
  try{
   const result=await api('/api/account/security-events?page='+(page+1));
   if(!target.isConnected||state.tab!=='account')return;
   events.push(...result.events);
   page=result.page;
   more=result.has_more;
   render();
  }catch{
   if(target.isConnected&&!events.length)target.textContent='Histórico indisponível no momento.';
   else if(target.isConnected)notify('Não foi possível consultar as atividades anteriores.',true);
  }
 };
 if(target)target.onclick=e=>{
  const button=e.target.closest('[data-account-history-more]');
  if(button&&target.contains(button))void task(button,loadMore);
 };
 return async function refreshHistory(){
  page=0;events=[];more=false;
  if(target?.isConnected)target.textContent='Carregando histórico…';
  await loadMore();
 };
}
function bindAccountProjectArchive(projects,{remote=false,allCount=projects.length}={}){
 const projectResults=document.querySelector('#account-project-results');
 const projectSearch=document.querySelector('#account-project-search');
 const projectPayment=document.querySelector('#account-project-payment');
 const projectPhase=document.querySelector('#account-project-phase');
 const projectSort=document.querySelector('#account-project-sort');
 if(!projectResults||!projectSearch||!projectPayment)return;
 let projectPage=1,generation=0,searchTimer=null;
 const show=(result)=>{
  const items=result.items,count=result.count,pages=result.pages;
  projectPage=result.page;
  projectResults.innerHTML=count?
   '<p class="fine">'+count+' projeto(s) encontrado(s) · Página '+result.page+' de '+pages+'</p><div class="account-projects">'+accountProjectCards(items)+'</div>'+
   (pages>1?'<div class="account-project-pagination"><button class="btn secondary" type="button" data-account-page="previous" '+(result.page===1?'disabled':'')+'>← Anterior</button> <button class="btn secondary" type="button" data-account-page="next" '+(result.page===pages?'disabled':'')+'>Próxima →</button></div>':'')+
   (result.limited?'<p class="fine">A navegação exibe até 8.000 projetos. Para consultar todos os registros, use a exportação completa de projetos.</p>':''):
   '<div class="empty">'+(allCount?'Nenhum projeto encontrado com os filtros informados.':'Os dados enviados nos seus projetos aparecerão aqui.')+'</div>';
 };
 const renderProjectPage=()=>{
  if(!projectResults.isConnected)return;
  const request=++generation;
  if(!remote){show(filteredAccountProjects(projects,projectSearch.value,projectPayment.value,projectPage,projectPhase?.value||'all',projectSort?.value||'newest'));return;}
  const url='/api/account/projects?page='+projectPage+'&payment='+encodeURIComponent(projectPayment.value)+'&search='+encodeURIComponent(projectSearch.value.trim())+'&phase='+encodeURIComponent(projectPhase?.value||'all')+'&sort='+encodeURIComponent(projectSort?.value||'newest');
  projectResults.textContent='Consultando seus projetos…';
  return api(url).then(result=>{
   if(request!==generation||!projectResults.isConnected)return;
   show({items:result.projects,count:result.total,page:result.page,pages:result.pages,limited:result.limited});
  }).catch(error=>{
   if(request===generation&&projectResults.isConnected){
    const throttled=typeof error?.message==='string'&&/Muitas tentativas/i.test(error.message);
    projectResults.innerHTML='<div class="empty">'+(throttled?
     'Muitas consultas em pouco tempo. Aguarde alguns minutos para pesquisar novamente.':
     'Não foi possível consultar os projetos.')+
     ' <button class="btn secondary" type="button" data-account-page="retry">Tentar novamente</button></div>';
   }
  });
 };
 projectSearch.oninput=()=>{
  projectPage=1;generation++;
  if(searchTimer!==null)clearTimeout(searchTimer);
  if(remote)searchTimer=setTimeout(()=>{searchTimer=null;void renderProjectPage();},200);
  else renderProjectPage();
 };
 const onProjectFilterChange=()=>{
  projectPage=1;generation++;
  if(searchTimer!==null){clearTimeout(searchTimer);searchTimer=null;}
  void renderProjectPage();
 };
 projectPayment.onchange=onProjectFilterChange;
 if(projectPhase)projectPhase.onchange=onProjectFilterChange;
 if(projectSort)projectSort.onchange=onProjectFilterChange;
 projectResults.onclick=e=>{
  const project=e.target.closest('[data-account-project]');
  if(project&&projectResults.contains(project)){openOrder(project.dataset.accountProject,'materials');return;}
  const button=e.target.closest('[data-account-page]');
  if(button&&projectResults.contains(button)&&!button.disabled){
   if(button.dataset.accountPage==='next')projectPage++;
   if(button.dataset.accountPage==='previous')projectPage--;
   void renderProjectPage();
  }
 };
 void renderProjectPage();
}
function clientAccountMarkup(){return (state.config.environment==='sandbox'?'<p class="cj-test-badge">Ambiente de demonstração · confira seus dados antes de salvar.</p>':'')+head('Minha conta, por completo.','Seus dados de acesso, contatos, informações fiscais e projetos, organizados em um só lugar.','<button class="btn secondary" id="account-refresh">Atualizar ↻</button> <button class="btn secondary" type="button" data-account-export="json">Baixar cadastro e projetos ↓</button> <button class="btn secondary" type="button" data-account-export="csv">Planilha dos projetos ↓</button>')+'<div id="account-content" aria-live="polite">Carregando seu cadastro…</div>';}
async function loadClientAccount(){const box=document.querySelector('#account-content');if(!box)return;
 document.querySelectorAll('[data-account-export]').forEach(button=>{
  button.onclick=()=>task(button,async()=>{
   await downloadAccountFile(button.dataset.accountExport);
   notify('Arquivo preparado para download.');
  });
 });
 try{const d=await api('/api/account?compact=1');if(!box.isConnected||state.tab!=='account')return;const p=d.profile,f=d.fiscal,latest=d.projects.find(o=>o.data.company_name)?.data||{},v={company:p.company||latest.company_name||'',whatsapp:p.whatsapp||f.whatsapp||latest.whatsapp||'',phone:p.phone||latest.phone||'',website:p.website||latest.contact_site||latest.current_site||''},suggested=!p.version&&!!Object.values(v).some(Boolean),address=[f.street,f.number,f.complement,f.district,f.city,f.uf,(f.zip||'').replace(/^(\d{5})(\d{3})$/,'$1-$2')].filter(Boolean).join(' · '),filled=[d.user.name,d.user.email,v.company,f.document,v.whatsapp,address].filter(Boolean).length;
 box.innerHTML=`<section class="account-identity"><span class="account-monogram" aria-hidden="true">${escapeHtml(d.user.name.slice(0,1).toUpperCase())}</span><div><span class="eyebrow">Seu cadastro Imagine</span><h2>${escapeHtml(d.user.name)}</h2><p>${escapeHtml(d.user.email)}</p><small>Cliente desde ${date(d.created_at)} · ${d.projects_total??d.projects.length} projeto(s)</small></div><div class="account-completeness"><strong>${filled} / 6</strong><span>Informações principais identificadas</span><progress value="${filled}" max="6" aria-label="Informações principais identificadas"></progress></div></section><nav class="cf-shortcuts" aria-label="Seções da minha conta"><a href="#account-contact">Dados e contato ↓</a><a href="#account-fiscal">Dados fiscais ↓</a><a href="#account-projects">Dados dos projetos ↓</a><a href="#account-security">Segurança ↓</a></nav><p class="fine account-project-summary" aria-label="Resumo dos projetos">${accountProjectsSummary(d.project_summary)}</p><div class="account-columns"><section class="panel" id="account-contact"><span class="eyebrow">Acesso e relacionamento</span><h2>Seus dados e contatos</h2>${suggested?'<p class="account-source">Alguns contatos abaixo foram recuperados dos seus materiais e dados fiscais. Confira e salve para confirmar seu cadastro.</p>':''}<form id="account-profile-form"><div class="account-fields">${[['name','Nome completo do responsável',d.user.name,'text',true],['email','E-mail de acesso',d.user.email,'email',true],['company','Empresa',v.company,'text',false],['whatsapp','WhatsApp com DDD',v.whatsapp,'tel',false],['phone','Telefone com DDD',v.phone,'tel',false],['website','Site da empresa',v.website,'url',false]].map(([key,label,value,type,required])=>`<label class="field"><span>${label}${required?' *':''}</span><input name="${key}" type="${type}" value="${escapeHtml(value)}" maxlength="${({name:120,email:254,company:150,whatsapp:30,phone:30,website:250})[key]}" ${required?'required':''} ${type==='url'?'placeholder="https://suaempresa.com.br"':''}></label>`).join('')}</div><label class="field"><span>Senha atual · necessária somente para alterar o e-mail de acesso</span><input name="currentPassword" type="password" autocomplete="current-password" maxlength="128"></label><p class="fine">O e-mail fiscal pode ser diferente do e-mail de acesso. Alterar estes contatos não modifica os dados publicados nos seus sites.</p><button class="btn orange">Salvar meu cadastro →</button></form></section><section class="panel" id="account-fiscal"><span class="eyebrow">Notas e cobranças</span><h2>Dados fiscais</h2><dl class="account-data"><div><dt>Nome / razão social</dt><dd>${escapeHtml(f.legal_name||'Não informado')}</dd></div><div><dt>CPF/CNPJ</dt><dd>${f.document?escapeHtml(CheckoutValidation.documentMask(f.document)):'Não informado'}</dd></div><div><dt>E-mail fiscal</dt><dd>${escapeHtml(f.email||'Não informado')}</dd></div><div><dt>WhatsApp de cobrança</dt><dd>${escapeHtml(f.whatsapp?CheckoutValidation.phoneMask(f.whatsapp):'Não informado')}</dd></div><div><dt>Endereço fiscal</dt><dd>${escapeHtml(address||'Não informado')}</dd></div></dl><button class="btn secondary" id="account-edit-fiscal">Editar dados fiscais ↗</button><p class="fine">Estes são os dados usados para emissão das notas. Informações de titularidade e tributação não são verificadas apenas pelo preenchimento do cadastro.</p><button class="text-link" id="account-open-finance">Consultar pagamentos e assinaturas ↗</button></section></div><section class="panel" id="account-projects"><div class="panel-head"><div><span class="eyebrow">O que você já informou</span><h2>Dados dos seus projetos</h2></div><span class="fine">Cada site mantém seus próprios materiais e contatos.</span></div><div class="account-project-controls"><label class="field"><span>Buscar projeto ou empresa</span><input id="account-project-search" type="search" maxlength="120" placeholder="Nome, domínio ou identificador" autocomplete="off"></label><label class="field"><span>Situação do pagamento</span><select id="account-project-payment"><option value="all">Todos</option><option value="paid">Pagos ou confirmados</option><option value="other">Outras situações</option></select></label><label class="field"><span>Etapa do projeto</span><select id="account-project-phase"><option value="all">Todas as etapas</option><option value="solicitado">Solicitado</option><option value="planejamento">Planejamento</option><option value="desenvolvimento">Desenvolvimento</option><option value="revisao">Revisão</option><option value="entregue">Entregue</option></select></label><label class="field"><span>Ordenar por</span><select id="account-project-sort"><option value="newest">Mais recentes</option><option value="oldest">Mais antigos</option></select></label></div><div id="account-project-results" aria-live="polite"></div></section><section class="panel" id="account-security"><span class="eyebrow">Seu acesso protegido</span><h2>Segurança da conta</h2><p class="muted">Atualize sua senha quando precisar. As outras sessões serão encerradas após a alteração.</p><form id="account-password-form"><div class="account-fields"><label class="field"><span>Senha atual *</span><input name="currentPassword" type="password" autocomplete="current-password" required maxlength="128"></label><label class="field"><span>Nova senha · 12 a 128 caracteres *</span><input name="newPassword" type="password" autocomplete="new-password" required minlength="12" maxlength="128"></label></div><button class="btn secondary">Atualizar minha senha</button></form><hr class="section-divider"><h3>Dispositivos conectados</h3><p class="muted">Encerre acessos em outros aparelhos sem sair desta sessão. Sua senha atual confirma a solicitação.</p><p id="account-session-summary" class="fine" aria-live="polite">Consultando sessões ativas…</p><form id="account-revoke-sessions-form"><label class="field"><span>Senha atual *</span><input name="currentPassword" type="password" autocomplete="current-password" required maxlength="128"></label><button class="btn secondary" type="submit">Encerrar sessões em outros aparelhos</button></form><hr class="section-divider"><h3>Atividades de segurança da conta</h3><div id="account-security-history" class="fine" aria-live="polite">Carregando histórico…</div><p class="fine">Os dados completos do cartão e o código de segurança não ficam salvos no cadastro. A recorrência é gerenciada em Pagamentos ou Manutenção.</p></section>`;
 bindAccountProjectArchive(d.projects,{remote:true,allCount:d.projects_total??d.projects.length});
 const refreshSessionSummary=async()=>{
  const target=document.querySelector('#account-session-summary');
  if(!target||!target.isConnected||state.tab!=='account')return;
  try{
   const counts=await api('/api/sessions/summary');
   if(target.isConnected&&state.tab==='account')
    target.textContent='Sessões ativas: '+counts.active_sessions+' · Outros aparelhos: '+counts.other_sessions+' · Limite: '+counts.max_active_sessions;
  }catch{
   if(target.isConnected)target.textContent='Não foi possível consultar as sessões agora.';
  }
 };
 void refreshSessionSummary();
 const refreshSecurityHistory=bindAccountSecurityHistory('#account-security-history');
 void refreshSecurityHistory();
 const form=document.querySelector('#account-profile-form');for(const key of ['whatsapp','phone']){form.elements[key].value=CheckoutValidation.phoneMask(form.elements[key].value);form.elements[key].oninput=()=>form.elements[key].value=CheckoutValidation.phoneMask(form.elements[key].value);}form.onsubmit=e=>{e.preventDefault();task(e.submitter,async()=>{const data=Object.fromEntries(new FormData(form));data.version=p.version;const r=await post('/api/account?compact=1',data);form.elements.currentPassword.value='';state.user={...state.user,...r.user};render();notify('Seu cadastro foi atualizado.');});};document.querySelector('#account-edit-fiscal').onclick=()=>fiscalCustomerDialog(state.user.id);document.querySelector('#account-open-finance').onclick=()=>{state.tab='finance';render();};document.querySelector('#account-password-form').onsubmit=e=>{e.preventDefault();task(e.submitter,async()=>{const r=await post('/api/password',Object.fromEntries(new FormData(e.target)));state.csrf=r.csrf;e.target.reset();await refreshSessionSummary();await refreshSecurityHistory();notify('Senha atualizada. As outras sessões foram encerradas.');});};document.querySelector('#account-revoke-sessions-form').onsubmit=e=>{e.preventDefault();task(e.submitter,async()=>{const result=await post('/api/sessions/revoke-others',Object.fromEntries(new FormData(e.target)));e.target.reset();await refreshSessionSummary();await refreshSecurityHistory();notify(result.revoked_sessions?`${result.revoked_sessions} sessão(ões) em outros aparelhos encerrada(s).`:'Nenhuma outra sessão ativa para encerrar.');});};document.querySelector('#account-refresh').onclick=()=>loadClientAccount();
 }catch(e){if(box.isConnected)box.innerHTML=`<div class="empty">${escapeHtml(e.message)}</div>`;}}
