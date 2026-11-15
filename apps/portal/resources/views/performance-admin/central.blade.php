@php
    $money=fn($c)=>'R$ '.number_format($c/100,2,',','.');
    $date=fn($t)=>$t?gmdate('d/m/Y H:i',$t).' UTC':'Indisponível';
    $link=fn($m,$extra=[])=>route('performance-admin.index',array_merge(['module'=>$m,'tenant'=>$tenant],$extra));
    $names=['google_ads'=>'Google Ads','meta_ads'=>'Meta Ads','chatgpt_ads'=>'ChatGPT Ads'];
    $statuses=['active'=>'Ativo','onboarding'=>'Em implantação','paused'=>'Pausado','failed'=>'Com falha','pending'=>'Pendente','approved'=>'Aprovado em laboratório','rejected'=>'Rejeitado','revoked'=>'Revogado','consumed'=>'Recibo consumido','open'=>'Aberto','acknowledged'=>'Reconhecido','resolved'=>'Resolvido','unavailable'=>'Indisponível','simulated'=>'Simulado','monitored'=>'Monitorado (simulação)','alert'=>'Com alerta'];
    $filterStatuses=array_intersect_key($statuses,array_flip(match($module){'campaigns','reports'=>['monitored','alert'],'agents'=>['active','paused','failed'],'approvals'=>['pending','approved','rejected','revoked','consumed'],'incidents'=>['open','acknowledged','resolved'],'channels'=>['simulated','unavailable'],default=>['active','onboarding']}));
    $roles=['superadmin'=>'Superadministrador Imagine','operations'=>'Gestor de Operações','analyst'=>'Analista de Performance','ai_supervisor'=>'Supervisor de IA','finance'=>'Financeiro','auditor'=>'Auditor somente leitura'];
@endphp
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow,noarchive"><title>{{ $modules[$module] }} · Administração Performance AI</title>
<style>
:root{--accent:#F9941D;--ink:#292929;--muted:#686868;--edge:#e8e2d9;--bg:#f7f7f8}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.55 system-ui,sans-serif}a{color:inherit;text-decoration:none}a:hover{text-decoration:underline}button,input,select{font:inherit}button,.button{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;min-height:42px;border:1px solid #df8316;background:var(--accent);color:#242424;border-radius:9px;font-weight:750;cursor:pointer}.secondary{background:white;border-color:var(--edge)}:focus-visible{outline:3px solid #bd6a09;outline-offset:3px}.skip{position:absolute;left:-9999px}.skip:focus{left:15px;top:15px;background:white;padding:12px;z-index:9}.shell{display:grid;grid-template-columns:236px minmax(0,1fr);min-height:100vh}.side{background:#292929;color:#eee;padding:30px 20px;position:sticky;top:0;height:100vh;overflow:auto}.logo{background:white;padding:12px;border-radius:12px}.logo img{width:160px;max-width:100%;display:block}.side p{font-size:12px;color:#c9c9c9}.side nav{display:grid;gap:7px;margin-top:30px}.side nav a{padding:11px 14px;border-radius:9px;color:#d5d5d5}.side nav a[aria-current=page]{background:#f9941d;color:#242424;font-weight:800}.side small{display:block;margin-top:30px;color:#bbb}.main{padding:32px clamp(16px,3vw,48px);min-width:0;max-width:1700px;width:100%;margin:auto}.top{display:flex;justify-content:space-between;gap:18px;align-items:center;border-bottom:1px solid var(--edge);padding-bottom:22px}.top form{margin:0}.eyebrow{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#97520a}h1{font-size:clamp(28px,3vw,38px);line-height:1.15;letter-spacing:-.04em;margin:8px 0}h2{font-size:19px;letter-spacing:-.025em;margin:0 0 14px}h3{font-size:15px;margin:0 0 10px}p{margin:0 0 14px}.muted{color:var(--muted)}.notice{padding:16px 20px;border:1px solid #ebc797;background:#fff3e2;border-radius:12px;margin:24px 0;font-size:13px}.notice strong{display:block}.toolbar{display:flex;flex-wrap:wrap;gap:12px;align-items:end;margin:22px 0}.toolbar label{font-size:12px;font-weight:700;display:block}.toolbar input,.toolbar select{display:block;padding:10px;border:1px solid #d8d2c8;border-radius:8px;min-height:42px;background:white;max-width:100%}.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:22px 0}.card{background:white;border:1px solid var(--edge);border-radius:15px;padding:22px;min-width:0}.metric strong{font-size:29px;letter-spacing:-.04em;display:block}.metric small{font-size:11px;color:var(--muted)}.metric span{color:var(--muted);font-size:12px}.grid2{display:grid;grid-template-columns:1.2fr 1fr;gap:20px;margin:22px 0}.tag{display:inline-block;font-size:11px;background:#fff1de;color:#8c4b05;border:1px solid #edcfaa;padding:4px 8px;border-radius:6px;font-weight:750}.tablewrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left;font-size:13px}th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.04em}th,td{padding:15px 10px;border-bottom:1px solid var(--edge);vertical-align:top}td strong{display:block}td small{color:var(--muted);display:block}.rowlink{font-weight:750;color:#97520a}.barrow{display:grid;grid-template-columns:90px minmax(0,1fr) 35px;gap:10px;align-items:center;margin:16px 0;font-size:12px}.track{background:#f3ede4;border-radius:8px;height:12px;overflow:hidden}.track i{display:block;background:var(--accent);height:100%}.empty{text-align:center;padding:50px 22px;color:var(--muted);border:1px dashed #d6cbbd;border-radius:13px}.tabs{display:flex;flex-wrap:wrap;gap:9px;margin:20px 0}.tabs a{padding:9px 13px;background:#fff;border:1px solid var(--edge);border-radius:8px}.tabs a[aria-current=page]{border-color:var(--accent);background:#fff1de}.action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:18px}.actionform{padding:16px;border:1px solid var(--edge);border-radius:10px;background:#faf9f7}.actionform label{font-size:12px;display:flex;align-items:flex-start;gap:8px;margin:12px 0}.actionform button{width:100%;font-size:12px}.actionform p{font-size:12px;color:var(--muted)}dl{margin:0}dt{font-weight:750;font-size:12px}dd{margin:4px 0 14px;color:var(--muted);overflow-wrap:anywhere}.pager{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:18px}.foot{font-size:11px;color:var(--muted);margin-top:30px;padding-top:20px;border-top:1px solid var(--edge)}.timeline li{padding:12px 0;border-bottom:1px solid var(--edge);font-size:12px}.detail{margin-top:22px}.detail code{overflow-wrap:anywhere}svg{width:100%;height:auto}.mobile-title{display:none}@media(max-width:1100px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.grid2{grid-template-columns:1fr}.shell{grid-template-columns:200px minmax(0,1fr)}.side{padding:22px 14px}.main{padding:24px 18px}}@media(max-width:760px){.shell{grid-template-columns:minmax(0,1fr)}.side{position:static;height:auto;padding:18px}.side .logo{width:185px}.side nav{display:flex;overflow:auto;margin-top:16px;gap:7px;padding-bottom:5px}.side nav a{white-space:nowrap;flex:none}.side small,.side p{display:none}.main{padding:22px 16px}.top{align-items:flex-start;flex-direction:column}.top .button{font-size:12px}.cards{gap:10px}.card{padding:17px}.metric strong{font-size:25px}.toolbar>div{flex:1 1 130px;min-width:0}.toolbar input,.toolbar select{width:100%}.action-grid{grid-template-columns:1fr}.pager{flex-wrap:wrap}h1,h2,h3,td,p,span{overflow-wrap:anywhere}.metric small{display:block}.notice{padding:15px}.side nav{max-width:calc(100vw - 36px)}}
</style></head><body><a class="skip" href="#content">Pular para o conteúdo</a><div class="shell"><aside class="side"><div class="logo"><img src="/assets/logo-imagine.png" alt="Imagine Comunicação Digital"></div><p>Performance AI · Administração</p><nav aria-label="Módulos administrativos">
@foreach($modules as $key=>$label)
<a href="{{ $link($key) }}" @if($module===$key) aria-current="page" @endif><span aria-hidden="true">{{ str_pad((string)$loop->iteration,2,'0',STR_PAD_LEFT) }}</span> · {{ $label }}</a>
@endforeach
</nav><small>Centro de operações<br>Laboratório isolado · v1<br>Sem campanhas ou cobranças reais</small></aside><main id="content" class="main" tabindex="-1"><header class="top"><div><span class="eyebrow">Imagine Performance AI · Equipe Imagine</span><h1>{{ $modules[$module] }}</h1><span class="muted">Supervisão humana. Clareza para cada próximo passo.</span></div><div class="toolbar"><a class="button secondary" href="{{ route('portal-admin.home') }}">← Voltar à administração</a><form method="post" action="{{ route('portal-admin.logout') }}">@csrf<button class="secondary" type="submit">Sair</button></form></div></header>
<div class="notice" role="note"><strong>Homologação funcional · Dados sintéticos</strong>Operações internas são persistidas e auditadas neste laboratório. Nenhum anúncio, cobrança, mensagem ou agente externo é executado. Pausar um agente aqui não pausa campanhas em provedores.</div>
@if(session('perf_notice'))
<div class="notice" role="status">{{ session('perf_notice') }}</div>
@endif
@if($errors->any())
<div class="notice" role="alert">Revise os campos informados. A operação não foi executada.</div>
@endif
@if(!$state)
<div class="empty"><h2>Nenhuma empresa de laboratório autorizada</h2><p>O login administrativo não concede acesso automático a empresas. O operador deve atribuir um vínculo de laboratório válido.</p></div>
@else
<form class="toolbar" method="get" action="{{ route('performance-admin.index',['module'=>$module]) }}"><div><label for="tenant">Empresa autorizada</label><select id="tenant" name="tenant">
@foreach($memberships as $m)
<option value="{{ $m->tenant }}" @selected($m->tenant===$tenant)>{{ $m->tenant }}</option>
@endforeach
</select></div><div><label for="period">Período disponível</label><select id="period" name="period"><option value="2026-11">Novembro 2026 · sintético</option></select></div><button type="submit">Aplicar contexto</button><span class="tag">{{ $roles[$member->role] }} · revisão {{ $state['revision'] }}</span></form>
<p class="muted">Fonte: cenário do motor Performance v1 · Atualização: {{ $date($state['updated_at']) }} · Estado: simulado, sem verificação oficial · Escopo: {{ $state['name'] }}</p>
@if($module==='dashboard')
@php
    $metrics=[['Empresas autorizadas',$scopeCount],['Cliente ativo neste contexto',$state['client_status']==='active'?1:0],['Em implantação',$state['client_status']==='onboarding'?1:0],['Suspensos / cancelados','0 / 0'],['Canais simulados disponíveis',2],['Campanhas monitoradas',count($state['campaigns'])],['Agentes ativos',count(array_filter($state['agents'],fn($a)=>$a['status']==='active'))],['Agentes pausados / falhos',count(array_filter($state['agents'],fn($a)=>$a['status']==='paused')).' / '.count(array_filter($state['agents'],fn($a)=>$a['status']==='failed'))],['Aguardando aprovação',count(array_filter($state['approvals'],fn($a)=>$a['status']==='pending'))],['Campanhas com alerta',count(array_filter($state['campaigns'],fn($a)=>$a['status']==='alert'))],['Conexões oficiais','Indisponíveis'],['Pendências / incidentes',count(array_filter($state['incidents'],fn($a)=>$a['status']!=='resolved'))]];
@endphp
<section class="cards" aria-label="Indicadores do contexto selecionado">
@foreach($metrics as [$label,$value])
<article class="card metric"><span>{{ $label }}</span><strong>{{ $value }}</strong><small>Nov/2026 · cenário sintético</small></article>
@endforeach
</section>
@if($can('finance') && isset($finance['unverified_actual_media_cents']))
<div class="cards"><article class="card metric"><span>Mídia simulada · separada da gestão</span><strong>{{ $money($finance['unverified_actual_media_cents']) }}</strong><small>Não verificada pelo provedor</small></article><article class="card metric"><span>Gestão estimada · não é receita recebida</span><strong>{{ $money($finance['estimated_management_fee_cents']) }}</strong><small>20% / piso por canal no mês cheio</small></article><article class="card metric"><span>Ativações de referência</span><strong>{{ $money($finance['activation_reference_cents']) }}</strong><small>R$490/canal · não faturável</small></article></div>
@endif
<div class="grid2"><section class="card"><h2>Evolução ilustrativa de leads</h2><p class="muted">Histórico sintético semanal. Não representa desempenho real.</p>
@foreach($state['series'] as $point)
<div class="barrow"><span>{{ $point['label'] }}</span><div class="track"><i style="width:{{ $point['leads']*3 }}%"></i></div><strong>{{ $point['leads'] }}</strong></div>
@endforeach
</section><section class="card"><h2>Distribuição de campanhas</h2>
@foreach(['google_ads','meta_ads'] as $ch)
@php
$count=count(array_filter($state['campaigns'],fn($c)=>$c['channel']===$ch));
@endphp
<div class="barrow"><span>{{ $names[$ch] }}</span><div class="track"><i style="width:{{ (int)(100*$count/count($state['campaigns'])) }}%"></i></div><strong>{{ $count }}</strong></div>
@endforeach
<p class="muted">ChatGPT Ads: integração indisponível. Não conectado.</p><a class="rowlink" href="{{ $link('incidents') }}">Revisar pendências →</a></section></div>
@elseif($module==='clients')
<form method="get" class="toolbar"><input type="hidden" name="tenant" value="{{ $tenant }}"><div><label for="client-q">Pesquisar empresa</label><input id="client-q" name="q" value="{{ $filters['q']??'' }}" maxlength="80"></div><div><label for="client-status">Situação</label><select id="client-status" name="status"><option value="">Todas</option><option value="active" @selected(($filters['status']??'')==='active')>Ativo</option><option value="onboarding" @selected(($filters['status']??'')==='onboarding')>Em implantação</option></select></div><div><label for="client-sort">Ordenação</label><select id="client-sort" name="sort"><option value="name">Nome</option><option value="status" @selected(($filters['sort']??'')==='status')>Situação</option></select></div><button>Filtrar clientes</button></form>
<section class="card"><h2>Clientes no seu escopo</h2><div class="tablewrap"><table><thead><tr><th>Empresa</th><th>Situação</th><th>Campanhas</th><th>Detalhes</th></tr></thead><tbody>
@forelse($clientRows as $client)
<tr><td>{{ $client['name'] }}</td><td>{{ $statuses[$client['status']] }}</td><td>{{ $client['campaigns'] }}</td><td><a class="rowlink" href="{{ $link('clients',['tenant'=>$client['tenant']]) }}">Abrir cliente →</a></td></tr>
@empty
<tr><td colspan="4">Nenhum cliente corresponde aos filtros.</td></tr>
@endforelse
</tbody></table></div><nav class="pager" aria-label="Paginação de clientes"><span>Página {{ $page }} · {{ $total }} empresas autorizadas no filtro</span>
@if($page>1)
<a href="{{ $link('clients',array_merge($filters,['page'=>$page-1])) }}">← Anterior</a>
@endif
@if($page*10<$total)
<a href="{{ $link('clients',array_merge($filters,['page'=>$page+1])) }}">Próxima →</a>
@endif
</nav><p class="muted">Apenas vínculos ativos e não expirados do administrador autenticado.</p><div class="tabs">
@foreach($memberships as $m)
<a href="{{ $link('clients',['tenant'=>$m->tenant]) }}" @if($m->tenant===$tenant) aria-current="page" @endif>{{ $m->tenant }}</a>
@endforeach
</div><h2>{{ $state['name'] }}</h2><p>Identificação: {{ $tenant }} · Situação: {{ $statuses[$state['client_status']] }} · Contrato: somente laboratório.</p><nav class="tabs" aria-label="Detalhes do cliente">
@foreach(['overview'=>'Visão geral','campaigns'=>'Campanhas','channels'=>'Canais','agents'=>'IA','finance'=>'Financeiro','approvals'=>'Aprovações','reports'=>'Relatórios','history'=>'Histórico'] as $key=>$label)
@if($key!=='finance'||$can('finance'))
<a href="{{ $link('clients',['tab'=>$key]) }}" @if(($filters['tab']??'overview')===$key) aria-current="page" @endif>{{ $label }}</a>
@endif
@endforeach
</nav>
@if(($filters['tab']??'overview')==='history')
@include('performance-admin.history')
@elseif(($filters['tab']??'overview')==='overview')
<dl><dt>Canais</dt><dd>Google Ads e Meta Ads em simulação; ChatGPT Ads indisponível.</dd><dt>Integrações oficiais</dt><dd>Sem autorização de leitura ou escrita, sem sincronização.</dd><dt>Contratação / ativação</dt><dd>Não realizadas. Responsáveis: membros autorizados do laboratório.</dd><dt>Pendências</dt><dd>Extratos oficiais, IAM operacional, S2S e homologação dos provedores.</dd></dl>
@else
@php
$target=($filters['tab']??'overview');
@endphp
<p>Abra a visão operacional desta empresa para consultar registros, filtros e detalhes.</p><a class="button" href="{{ $link($target) }}">Abrir {{ $modules[$target]??$target }} →</a>
@endif
</section>
@elseif(in_array($module,['channels','campaigns','agents','approvals','incidents','reports'],true))
<form method="get" class="toolbar"><input type="hidden" name="tenant" value="{{ $tenant }}"><div><label for="q">Pesquisar</label><input id="q" name="q" value="{{ $filters['q']??'' }}" maxlength="80" placeholder="Nome ou título"></div><div><label for="channel">Canal</label><select id="channel" name="channel"><option value="">Todos os canais</option>
@foreach($names as $key=>$label)
<option value="{{ $key }}" @selected(($filters['channel']??'')===$key)>{{ $label }}</option>
@endforeach
</select></div><div><label for="sort">Ordenar</label><select id="sort" name="sort"><option value="name">Nome</option><option value="status" @selected(($filters['sort']??'')==='status')>Situação</option></select></div><div><label for="status">Situação</label><select id="status" name="status"><option value="">Todas</option>
@foreach($filterStatuses as $key=>$label)
<option value="{{ $key }}" @selected(($filters['status']??'')===$key)>{{ $label }}</option>
@endforeach
</select></div>
@if(in_array($module,['campaigns','reports'],true))
<div><label for="objective">Objetivo</label><select id="objective" name="objective"><option value="">Todos</option><option @selected(($filters['objective']??'')==='Leads')>Leads</option><option @selected(($filters['objective']??'')==='Visitas')>Visitas</option></select></div>
@endif
<button type="submit">Filtrar</button><a class="button secondary" href="{{ $link($module) }}">Limpar</a></form>
@if($module==='reports')
<div class="notice">Relatório de laboratório: dados incompletos, sem receita ou atribuição verificadas. ROI/ROAS e PDF indisponíveis. CSV respeita os filtros atuais e a empresa selecionada.</div><p><a class="button" href="{{ route('performance-admin.export',array_merge(['tenant'=>$tenant],array_intersect_key($filters,array_flip(['q','channel','status','objective','period'])))) }}">Exportar CSV filtrado ↓</a></p>
@endif
<section class="card"><h2>{{ $modules[$module] }} <span class="tag">{{ $total }} registros</span></h2><div class="tablewrap"><table><thead><tr><th scope="col">Registro</th><th scope="col">Canal / contexto</th><th scope="col">Situação</th><th scope="col">Detalhes</th></tr></thead><tbody>
@forelse($rows as $row)
<tr><td><strong>{{ $row['name']??$row['title'] }}</strong><small>{{ $row['id'] }} · fonte sintética</small></td><td>{{ $names[$row['channel']??$row['id']]??$tenant }}<small>{{ $row['level']??$row['risk']??$row['severity']??'Laboratório' }}</small></td><td><span class="tag">{{ $statuses[$row['status']]??$row['status'] }}</span></td><td><a class="rowlink" href="{{ $link($module,['resource'=>$row['id']]) }}">Ver detalhes →</a></td></tr>
@empty
<tr><td colspan="4"><div class="empty">Nenhum registro corresponde aos filtros.</div></td></tr>
@endforelse
</tbody></table></div><nav class="pager" aria-label="Paginação"><span>Página {{ $page }} · até 10 registros por página</span>
@if($page>1)
<a class="button secondary" href="{{ $link($module,array_merge($filters,['page'=>$page-1])) }}">← Anterior</a>
@endif
@if($page*10<$total)
<a class="button secondary" href="{{ $link($module,array_merge($filters,['page'=>$page+1])) }}">Próxima →</a>
@endif
</nav></section>
@if($detail)
<section class="card detail"><h2>{{ $detail['name']??$detail['title'] }}</h2><p class="muted">Empresa {{ $tenant }} · fonte sintética · revisão {{ $state['revision'] }} · {{ $date($state['updated_at']) }}</p><dl>
@if($module==='channels')
<dt>Conta de laboratório</dt><dd>{{ $detail['account'] }}</dd><dt>Orçamento planejado</dt><dd>{{ $money($detail['planned_cents']) }} · não aprovado para execução</dd><dt>Investimento verificado</dt><dd>Indisponível; nenhum extrato oficial.</dd><dt>Leitura / escrita</dt><dd>Não autorizadas. Última sincronização: indisponível.</dd><dt>Campanhas</dt><dd><a class="rowlink" href="{{ $link('campaigns',['channel'=>$detail['id']]) }}">Consultar campanhas deste canal →</a></dd>
@elseif(in_array($module,['campaigns','reports'],true))
<dt>Indicadores simulados</dt><dd>Mídia {{ $money($detail['spend_cents']) }} · Impressões {{ $detail['impressions'] }} · Cliques {{ $detail['clicks'] }} · Conversões {{ $detail['conversions'] }} · Leads {{ $detail['leads'] }} · CPL {{ $money((int)round($detail['spend_cents']/$detail['leads'])) }}</dd><dt>Diagnóstico</dt><dd>{{ $detail['diagnosis'] }}</dd><dt>Variações / CPA / ROI / ROAS</dt><dd>Indisponíveis: faltam séries oficiais, receita e atribuição verificadas.</dd><dt>Ações publicitárias</dt><dd>Bloqueadas. Não há conector de escrita.</dd>
@elseif($module==='agents')
<dt>Autonomia</dt><dd>{{ $detail['level'] }} · Observação. N2 exige aprovação por ação; N3 depende de escopo, prazo, risco e limites homologados.</dd><dt>Última / próxima execução</dt><dd>{{ $date($detail['last_run']) }} · {{ $date($detail['next_run']) }} · histórico sintético, sem modelo externo.</dd><dt>Recomendação</dt><dd>{{ $detail['recommendation'] }}</dd><dt>Estado interno</dt><dd>{{ $statuses[$detail['status']] }}. Agentes com falha exigem intervenção; retomada indisponível.</dd>
@elseif($module==='approvals')
<dt>Ação e campanha</dt><dd>{{ $detail['kind'] }} · {{ $detail['campaign'] }}</dd><dt>Antes / depois</dt><dd>{{ $detail['before'] }} → {{ $detail['after'] }}</dd><dt>Justificativa e riscos</dt><dd>{{ $detail['reason'] }} · {{ $detail['risk'] }}</dd><dt>Versão / validade</dt><dd>{{ $detail['version'] }} · {{ $date($detail['expires_at']) }}</dd><dt>Identidade imutável da proposta</dt><dd><code>{{ $detail['digest'] }}</code></dd><dt>Execução</dt><dd>Aprovação não executa anúncios ou finanças. Somente recibos internos de laboratório podem ser consumidos por outro operador. Alteração de versão, expiração, revogação ou conflito bloqueiam a operação.</dd>
@elseif($module==='incidents')
<dt>Severidade / responsável</dt><dd>{{ $detail['severity'] }} · {{ $detail['owner']??'Aguardando atribuição' }}</dd><dt>Evidência para resolução</dt><dd>{{ $detail['resolution_evidence']==='synthetic_verified'?'Verificada apenas no cenário sintético':'Indisponível; resolução bloqueada' }}</dd><dt>Histórico</dt><dd>Reconhecimento não significa resolução. Incidentes permanecem registrados.</dd>
@endif
</dl>
@php
$actions=match($module){'agents'=>$detail['status']==='active'?['agent.pause'=>'Pausar agente interno']:($detail['status']==='paused'?['agent.resume'=>'Retomar agente interno']:[]),'approvals'=>match($detail['status']){'pending'=>['approval.approve'=>'Aprovar proposta sintética','approval.reject'=>'Rejeitar proposta'],'approved'=>['approval.revoke'=>'Revogar aprovação','approval.consume'=>'Consumir recibo interno'],default=>[]},'incidents'=>match($detail['status']){'open'=>['incident.ack'=>'Reconhecer incidente'],'acknowledged'=>['incident.resolve'=>'Resolver com evidência'],default=>[]},default=>[]};
@endphp
<div class="action-grid">
@foreach($actions as $action=>$label)
@if($can($action))
<form class="actionform" method="post" action="{{ route('performance-admin.command',['tenant'=>$tenant]) }}">@csrf<input type="hidden" name="module" value="{{ $module }}"><input type="hidden" name="action" value="{{ $action }}"><input type="hidden" name="resource" value="{{ $detail['id'] }}"><input type="hidden" name="revision" value="{{ $state['revision'] }}"><input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}">
@if($module==='approvals')
<input type="hidden" name="proposal_version" value="{{ $detail['version'] }}"><input type="hidden" name="proposal_digest" value="{{ $detail['digest'] }}">
@endif
<strong>{{ $label }}</strong><p>Escopo: {{ $tenant }} / {{ $detail['id'] }}. O servidor revalida permissão, revisão e estado antes de registrar.</p><label><input type="checkbox" name="confirmed" value="1" required>Confirmo esta operação exclusivamente no laboratório.</label><button type="submit">{{ $label }}</button></form>
@endif
@endforeach
</div></section>
@endif
@elseif($module==='finance')
<section class="card"><h2>Conciliação e faturamento · visão de laboratório</h2><p>Mídia paga ao provedor é separada da remuneração Imagine. Cálculo ilustrativo para mês cheio, por canal disponível. Sem emissão de cobrança.</p><div class="tablewrap"><table><thead><tr><th>Canal</th><th>Mídia não verificada</th><th>Gestão estimada</th><th>Fonte / estado</th></tr></thead><tbody>
@foreach($finance['channels'] as $ch=>$value)
<tr><td>{{ $names[$ch] }}</td><td>{{ $money($value['unverified_actual_media_cents']) }}</td><td>{{ $money($value['unverified_earned_fee_cents']) }}</td><td>Sintético · não faturável</td></tr>
@endforeach
</tbody></table></div>@if(($finance['state']??'')==='partial_policy_pending')
<p class="notice">Período parcial: cálculo indisponível enquanto a política contratual não for homologada. Nenhum piso ou cobrança foi aplicado.</p>
@else
<p class="notice">20% do investimento verificado ou mínimo R$170 por canal ativo em mês cheio. Neste exemplo: Google R$200 + Meta R$170 = R$370. Períodos parciais aguardam política contratual: não aplicar automaticamente o piso.</p>
@endif
<dl><dt>Ativação por canal</dt><dd>R$490 · referência, sem contratação real.</dd><dt>Extratos / divergências</dt><dd>Pendentes de comprovação oficial. Valores das campanhas e da mídia do cenário não foram conciliados por provedor.</dd><dt>Faturas / pagamentos conciliados</dt><dd>Indisponíveis. Autoridade de cobrança permanece no Portal.</dd><dt>Asaas e C6</dt><dd>Sem credenciais, conexão ou cobrança. Configuração futura restrita ao backend.</dd></dl></section>
@elseif($module==='reports')
<section class="card"><h2>Relatório de campanhas</h2><p>Empresa selecionada: {{ $tenant }} · Novembro 2026 · dados simulados.</p><a class="button" href="{{ route('performance-admin.export',['tenant'=>$tenant]) }}">Exportar CSV de laboratório ↓</a><a class="button secondary" href="{{ $link('campaigns') }}">Filtrar campanhas</a><div class="notice">Dados incompletos: sem receita, vendas atribuídas ou extrato oficial. ROI e ROAS não calculados. PDF: arquitetura preparada por contrato de consulta, ainda indisponível.</div><p>Objetivos e canais podem ser consultados nos filtros de campanhas. A exportação contém o conjunto de campanhas da empresa selecionada, sem dados de outras empresas.</p></section>
@elseif($module==='settings')
<section class="card"><h2>Políticas e configurações</h2><p>Consulta somente leitura. Alterações de permissões, autonomia, parâmetros comerciais e integrações exigem fluxo futuro versionado; nenhum perfil contorna os bloqueios.</p><div class="tablewrap"><table><thead><tr><th>Papel candidato</th><th>Capacidades neste laboratório</th></tr></thead><tbody>
@foreach($roles as $role=>$label)
<tr><td>{{ $label }}</td><td>{{ match($role){'superadmin','operations'=>'Leitura e operação interna; sem execução externa','ai_supervisor'=>'Agentes e aprovações internas','analyst'=>'Leitura e reconhecimento de incidentes','finance'=>'Leitura financeira',default=>'Somente leitura'} }}</td></tr>
@endforeach
</tbody></table></div><p class="notice">N1: recomendações. N2: execução futura sob aprovação. N3: autonomia delimitada futura. Elevação de autonomia e mutações reais não estão habilitadas.</p><dl><dt>Contrato</dt><dd>imagine.performance.admin.v1</dd><dt>Integração</dt><dd>Adaptador de código local pinado, exclusivo de testes. Não é S2S operacional e não encaminha sessão do navegador.</dd><dt>Configurações técnicas / financeiras / comerciais</dt><dd>Separadas por permissões; sem formulários de segredos ou concessão de acesso.</dd><dt>Retenção / escala / alertas externos</dt><dd>Aguardam homologação. Consultas de registros paginadas em 10 itens; histórico limitado aos 50 eventos recentes.</dd></dl></section>
@endif
@if($module!=='clients'||($filters['tab']??'overview')!=='history')
<section class="card detail"><h2>Histórico auditável do contexto</h2>@include('performance-admin.history')</section>
@endif
@endif
<footer class="foot">Imagine Comunicação Digital · Tecnologia, estratégia e crescimento.<br>ADMIN-P0 · No máximo dados sintéticos. Sem deploy, cobrança ou escrita publicitária autorizada por esta interface.</footer></main></div></body></html>
