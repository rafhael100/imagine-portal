<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Imagine Performance AI — Painel de demonstração</title>
    <style>
        :root{color-scheme:light;--ink:#142239;--muted:#637188;--edge:#e3eaf1;--bg:#f5f8fb;--nav:#101e33;--white:#fff;--accent:#225fc2;--soft:#edf4ff}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
        .pd-layout{display:grid;grid-template-columns:225px minmax(0,1fr);min-height:100vh}
        .pd-side{background:var(--nav);color:#f6f8ff;padding:30px 22px}
        .pd-brand{font-size:21px;font-weight:800;letter-spacing:-.04em}
        .pd-brand span{color:#79affb}.pd-side p{margin:0;color:#b8c9df;font-size:12px}
        .pd-nav{margin-top:45px;display:grid;gap:9px}
        .pd-nav span{padding:11px 12px;border-radius:8px;color:#bfd1e6;font-size:14px}
        .pd-nav .pd-current{background:#253d5b;color:#fff;font-weight:700}
        .pd-side-note{margin-top:36px;padding-top:18px;border-top:1px solid #334a67;color:#b8c9df;font-size:12px}
        .pd-main{max-width:1320px;width:100%;margin:0 auto;padding:34px clamp(18px,4vw,55px) 55px}
        .pd-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px}
        h1{font-size:clamp(25px,3vw,34px);letter-spacing:-.035em;margin:6px 0 4px}
        h2{font-size:18px;letter-spacing:-.02em;margin:0}
        .pd-muted{color:var(--muted)}.pd-eyebrow{font-size:11px;text-transform:uppercase;letter-spacing:.1em;font-weight:800;color:var(--accent)}
        .pd-pill{display:inline-block;background:var(--soft);border:1px solid #c8dafa;color:#18529c;border-radius:100px;padding:6px 11px;font-size:12px;font-weight:700;white-space:nowrap}
        .pd-disclaimer{margin-top:25px;border:1px solid #bcd7f4;background:#eef6ff;border-radius:12px;padding:16px 19px}
        .pd-disclaimer strong{display:block;color:#164b87}.pd-disclaimer p{margin:4px 0 0;font-size:13px;color:#365779}
        .pd-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-top:25px}
        .pd-card{border:1px solid var(--edge);background:var(--white);border-radius:14px;padding:20px;box-shadow:0 6px 22px #12243c06}
        .pd-metric-title{color:var(--muted);font-size:13px}.pd-number{font-size:30px;letter-spacing:-.04em;font-weight:800;margin:7px 0 2px}
        .pd-sub{color:var(--muted);font-size:12px}.pd-row{display:grid;grid-template-columns:1.4fr 1fr;gap:16px;margin-top:16px}
        .pd-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:16px}
        .pd-tag{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.015em;background:#f0f4f8;border-radius:5px;padding:4px 8px;color:#52637c}
        .pd-detail{display:grid;grid-template-columns:145px 1fr;gap:12px;padding:11px 0;border-bottom:1px solid #edf0f4}
        .pd-detail:last-child{border-bottom:0}.pd-detail dt{font-size:13px;color:var(--muted)}.pd-detail dd{margin:0;font-size:14px;font-weight:600}
        .pd-status{color:#946414;font-size:12px;font-weight:700}
        .pd-table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;text-align:left}
        th{color:var(--muted);font-weight:700;font-size:12px;border-bottom:1px solid var(--edge);padding:13px 8px}
        td{padding:15px 8px;border-bottom:1px solid #edf0f4;font-size:13px}
        tr:last-child td{border-bottom:0}.pd-table-name{font-weight:700;color:var(--ink)}.pd-blank{color:var(--muted);font-size:13px;padding:12px 0}
        .pd-footer{margin-top:24px;font-size:12px;color:var(--muted);text-align:center}
        @media(max-width:1000px){.pd-layout{grid-template-columns:1fr}.pd-side{padding:18px 22px}.pd-nav,.pd-side-note{display:none}.pd-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pd-row{grid-template-columns:1fr}}
        @media(max-width:600px){.pd-top{display:block}.pd-top .pd-pill{margin-top:10px}.pd-grid{grid-template-columns:1fr 1fr;gap:10px}.pd-card{padding:16px}.pd-number{font-size:25px}.pd-detail{grid-template-columns:1fr;gap:2px}}
    </style>
</head>
<body>
    <div class="pd-layout">
        <aside class="pd-side" aria-label="Navegação ilustrativa">
            <div class="pd-brand">imagine<span>.</span></div>
            <p>Performance AI · Portal Imagine</p>
            <div class="pd-nav" aria-label="Seções do painel demonstrativo">
                <span class="pd-current">Visão geral</span>
                <span>Minhas campanhas</span>
                <span>Solicitações</span>
                <span>Planos e contratos</span>
                <span>Relatórios</span>
            </div>
            <div class="pd-side-note">Navegação apenas ilustrativa. Nenhuma opção executa ações reais.</div>
        </aside>
        <main class="pd-main">
            <header class="pd-top">
                <div>
                    <div class="pd-eyebrow">Área do cliente · Protótipo</div>
                    <h1>Performance AI</h1>
                    <div class="pd-muted">Acompanhamento fictício da organização {{ $dashboard->organizationId->value }}</div>
                </div>
                <span class="pd-pill">AMBIENTE DEMONSTRATIVO</span>
            </header>

            <section class="pd-disclaimer" role="note" aria-label="Aviso de dados fictícios">
                <strong>Dados totalmente simulados — sem conexão com Google Ads ou Meta</strong>
                <p>Indicadores, campanhas e solicitações são exemplos. Nenhum valor representa resultados reais, contratação, aprovação, pagamento ou mídia em execução.</p>
            </section>

            <section aria-label="Indicadores fictícios">
                <div class="pd-grid">
                    @foreach ($dashboard->metrics as $metric)
                        <article class="pd-card">
                            <div class="pd-metric-title">{{ $metric->key->label() }}</div>
                            <div class="pd-number">{{ number_format($metric->value, 0, ',', '.') }}</div>
                            <div class="pd-sub">Dado fictício · DEMO</div>
                        </article>
                    @endforeach
                    <article class="pd-card">
                        <div class="pd-metric-title">Valor do serviço</div>
                        <div class="pd-number">N/D</div>
                        <div class="pd-sub">Preço ainda não homologado</div>
                    </article>
                </div>
            </section>

            <div class="pd-row">
                <section class="pd-card" aria-labelledby="pd-plan-title">
                    <div class="pd-section-title"><h2 id="pd-plan-title">Plano demonstrativo</h2><span class="pd-tag">Não contratável</span></div>
                    <dl>
                        <div class="pd-detail"><dt>Modalidade</dt><dd>
                            @if ($dashboard->proposal->modality === \App\Domain\Catalog\PerformanceModality::SingleChannel)
                                Canal Único
                            @elseif ($dashboard->proposal->modality === \App\Domain\Catalog\PerformanceModality::Multichannel)
                                Google Ads + Meta (Instagram e/ou Facebook)
                            @else
                                Operação Ampliada
                            @endif
                        </dd></div>
                        <div class="pd-detail"><dt>Canais incluídos</dt><dd>
                            @foreach ($dashboard->proposal->channels as $channel)
                                {{ $channel === \App\Domain\Catalog\PerformanceChannel::GoogleAds ? 'Google Ads' : 'Meta (Instagram e/ou Facebook)' }}@if(! $loop->last), @endif
                            @endforeach
                        </dd></div>
                        <div class="pd-detail"><dt>Versão da oferta</dt><dd>{{ $dashboard->proposal->offeringVersion }}</dd></div>
                        <div class="pd-detail"><dt>Situação</dt><dd><span class="pd-status">Somente demonstração, sem contratação</span></dd></div>
                    </dl>
                </section>

                <section class="pd-card" aria-labelledby="pd-onboarding-title">
                    <div class="pd-section-title"><h2 id="pd-onboarding-title">Onboarding</h2><span class="pd-tag">Fictício</span></div>
                    <dl>
                        <div class="pd-detail"><dt>Etapa</dt><dd><span class="pd-status">Aguardando revisão simulada</span></dd></div>
                        <div class="pd-detail"><dt>Objetivo</dt><dd>
                            @switch($dashboard->onboarding->goal)
                                @case(\App\Domain\PerformanceOnboarding\DemoBusinessGoal::LeadGeneration) Geração de leads (exemplo) @break
                                @case(\App\Domain\PerformanceOnboarding\DemoBusinessGoal::Sales) Vendas (exemplo) @break
                                @default Reconhecimento de marca (exemplo)
                            @endswitch
                        </dd></div>
                        <div class="pd-detail"><dt>Contas publicitárias</dt><dd>Não conectadas</dd></div>
                        <div class="pd-detail"><dt>Provisionamento</dt><dd>Não executado</dd></div>
                    </dl>
                </section>
            </div>

            <section class="pd-card" style="margin-top:16px" aria-labelledby="pd-campaigns-title">
                <div class="pd-section-title"><h2 id="pd-campaigns-title">Campanhas de exemplo</h2><span class="pd-tag">Dados fictícios</span></div>
                <div class="pd-table-wrap">
                    <table>
                        <thead><tr><th scope="col">Campanha</th><th scope="col">Canal</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                            @forelse ($dashboard->campaigns as $campaign)
                                <tr>
                                    <td class="pd-table-name">{{ $campaign->title }}</td>
                                    <td>{{ $campaign->channelLabel() }}</td>
                                    <td>{{ $campaign->stage->label() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="pd-blank">Nenhuma campanha demonstrativa cadastrada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="pd-card" style="margin-top:16px" aria-labelledby="pd-requests-title">
                <div class="pd-section-title"><h2 id="pd-requests-title">Solicitações e aprovações ilustrativas</h2><span class="pd-tag">Somente leitura</span></div>
                <div class="pd-table-wrap">
                    <table>
                        <thead><tr><th scope="col">Solicitação</th><th scope="col">Estado</th></tr></thead>
                        <tbody>
                            @forelse ($dashboard->requests as $request)
                                <tr><td class="pd-table-name">{{ $request->title }}</td><td>{{ $request->stage->label() }}</td></tr>
                            @empty
                                <tr><td colspan="2" class="pd-blank">Nenhuma solicitação demonstrativa cadastrada.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="pd-footer">Imagine Performance AI · P02-D · Protótipo interno não publicado · {{ $dashboard->source }}</footer>
        </main>
    </div>
</body>
</html>
