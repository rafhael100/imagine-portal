@php
    // F116: no HTTP route and no live source; the passed label is presentation
    // text only and must always be HTML-escaped by Blade.
    $companyLabel = isset($companyLabel) && is_string($companyLabel)
        ? $companyLabel : 'Empresa Aurora (fictícia)';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <title>SEO Engine · Painel de demonstração · Imagine</title>
    <style>
        :root{color-scheme:light;--orange:#F9941D;--orange-ink:#9b5200;--nav:#101b2b;--ink:#182538;--muted:#5d6c80;--line:#e5eaf0;--bg:#f6f8fb;--paper:#fff;--soft:#fff5e8}
        *{box-sizing:border-box}body{margin:0;background:var(--bg);font:15px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif;color:var(--ink)}
        .is-layout{min-height:100vh;display:grid;grid-template-columns:242px minmax(0,1fr)}
        .is-side{padding:30px 23px;background:var(--nav);color:#fff}
        .is-brand{font-size:27px;font-weight:850;letter-spacing:-.07em}.is-brand span{color:var(--orange)}
        .is-side-desc{font-size:12px;color:#bdc9db;margin-top:0}
        .is-side-label{margin-top:44px;text-transform:uppercase;font-size:11px;letter-spacing:.13em;color:#93a8bf;font-weight:800}
        .is-side-nav{margin-top:12px;display:grid;gap:8px}
        .is-side-nav span{padding:12px 13px;color:#c4d1e1;border-radius:9px}
        .is-side-nav .is-selected{color:#fff;background:#27384f;border-left:3px solid var(--orange);font-weight:800}
        .is-side-bottom{border-top:1px solid #32435a;padding-top:18px;margin-top:40px;font-size:12px;color:#b5c4d7}
        .is-main{padding:36px clamp(18px,4vw,55px) 55px;width:100%;max-width:1420px;margin:0 auto}
        .is-eyebrow{color:var(--orange-ink);font-size:11px;font-weight:850;letter-spacing:.13em;text-transform:uppercase}
        .is-top{display:flex;justify-content:space-between;gap:20px;align-items:start}
        h1{font-size:clamp(29px,3vw,38px);letter-spacing:-.045em;line-height:1.14;margin:7px 0 8px}
        h2{font-size:19px;letter-spacing:-.025em;margin:0}
        .is-muted{color:var(--muted)}.is-small{font-size:13px}
        .is-tag{border:1px solid #f5d7ad;border-radius:100px;padding:7px 13px;color:var(--orange-ink);font-size:11px;letter-spacing:.02em;font-weight:800;background:var(--soft);white-space:nowrap}
        .is-warning{border:1px solid #f1d4a8;background:#fff8ed;border-radius:13px;padding:17px 20px;margin:25px 0 23px}
        .is-warning strong{color:#895006}.is-warning p{margin:5px 0 0;font-size:13px;color:#79542a}
        .is-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:15px}
        .is-card{border:1px solid var(--line);border-radius:15px;padding:22px;background:var(--paper);box-shadow:0 6px 25px #15263d05}
        .is-status{display:inline-block;padding:5px 9px;border-radius:6px;font-weight:800;font-size:11px;background:#eef2f6;color:#516377}
        .is-card-title{font-size:15px;font-weight:800;margin:14px 0 4px}.is-card p{margin:0;font-size:13px;color:var(--muted)}
        .is-section{margin-top:25px}.is-heading{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px}
        .is-funnel{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
        .is-funnel article{border:1px solid var(--line);background:var(--paper);border-radius:15px;padding:24px;position:relative}
        .is-funnel article:before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:var(--orange);border-radius:15px 15px 0 0}
        .is-step{font-size:11px;color:var(--orange-ink);text-transform:uppercase;letter-spacing:.1em;font-weight:850}
        .is-funnel h3{margin:7px 0;font-size:19px;letter-spacing:-.03em}
        .is-number{font-size:34px;letter-spacing:-.05em;font-weight:850;margin:5px 0 1px}
        .is-foot{border-top:1px solid var(--line);margin-top:32px;padding-top:15px;font-size:12px;color:var(--muted)}
        .is-detail{margin-top:22px;border:1px solid var(--line);background:#fff;border-radius:15px;padding:22px}
        .is-detail h2{margin-bottom:7px}.is-checks{margin:14px 0 0;padding:0;list-style:none;display:grid;gap:9px}
        .is-checks li{border-top:1px solid #edf0f4;padding-top:10px;font-size:13px}
        .is-checks strong{display:block;color:var(--ink)}
        @media(max-width:1020px){.is-layout{grid-template-columns:1fr}.is-side{padding:18px 24px}.is-side-nav,.is-side-label,.is-side-bottom{display:none}}
        @media(max-width:690px){.is-top{display:block}.is-tag{margin-top:12px}.is-grid,.is-funnel{grid-template-columns:1fr}.is-main{padding:23px 16px 36px}}
        @media(prefers-reduced-motion:reduce){*{scroll-behavior:auto}}
    </style>
</head>
<body>
    <div class="is-layout">
        <aside class="is-side" aria-label="Navegação ilustrativa">
            <div class="is-brand" aria-label="Imagine">imagine<span>.</span></div>
            <p class="is-side-desc">Tecnologia, marketing e inteligência</p>
            <div class="is-side-label">Portal do Cliente</div>
            <nav class="is-side-nav" aria-label="Seções demonstrativas">
                <span>Visão geral</span>
                <span>Meus serviços</span>
                <span class="is-selected" aria-current="page">SEO Engine</span>
                <span>Relatórios</span>
                <span>Suporte</span>
            </nav>
            <p class="is-side-bottom">Protótipo interno. Navegação ilustrativa sem acesso à conta do cliente.</p>
        </aside>
        <main class="is-main" id="seo-content">
            <header class="is-top">
                <div>
                    <div class="is-eyebrow">Inteligência orgânica · Portal Imagine</div>
                    <h1>SEO Engine</h1>
                    <p class="is-muted">Visão de exemplo para {{ $companyLabel }}. Nenhuma conexão está ativa.</p>
                </div>
                <span class="is-tag">DEMONSTRAÇÃO INTERNA</span>
            </header>

            <section class="is-warning" role="note" aria-label="Aviso de ambiente fictício">
                <strong>Dados fictícios — sem conexão com Google Search Console, Analytics ou CRM</strong>
                <p>Este painel é apenas uma prévia visual. Não contém resultados reais, autenticação, consentimento OAuth ou acesso a dados de clientes. Nenhuma operação de conexão pode ser iniciada aqui.</p>
            </section>

            <section aria-labelledby="seo-integrations-title">
                <div class="is-heading">
                    <h2 id="seo-integrations-title">Conexões de dados</h2>
                    <span class="is-status">Aguardando homologação</span>
                </div>
                <div class="is-grid">
                    <article class="is-card">
                        <span class="is-status">Não conectado</span>
                        <div class="is-card-title">Google Search Console</div>
                        <p>Autorizações por empresa e propriedade precisarão ser verificadas no próprio Portal.</p>
                    </article>
                    <article class="is-card">
                        <span class="is-status">Não conectado</span>
                        <div class="is-card-title">Analytics do site</div>
                        <p>Visitas precisam de uma fonte autorizada de analytics. Cliques de busca não equivalem a visitas.</p>
                    </article>
                    <article class="is-card">
                        <span class="is-status">Não conectado</span>
                        <div class="is-card-title">CRM Imagine</div>
                        <p>Leads e clientes dependem de eventos e atribuição próprios do CRM, sem compartilhamento de login.</p>
                    </article>
                </div>
            </section>

            <section class="is-section" aria-labelledby="seo-method-title">
                <div class="is-heading">
                    <h2 id="seo-method-title">Metodologia Imagine</h2>
                    <span class="is-status">Visitas → Leads → Clientes</span>
                </div>
                <div class="is-funnel">
                    <article>
                        <div class="is-step">01 · Atrair</div>
                        <h3>Visitas</h3>
                        <div class="is-number" aria-label="Dados de visitas indisponíveis">N/D</div>
                        <p class="is-muted is-small">Sessões do site: fonte de analytics ainda não autorizada.</p>
                    </article>
                    <article>
                        <div class="is-step">02 · Converter</div>
                        <h3>Leads</h3>
                        <div class="is-number" aria-label="Dados de leads indisponíveis">N/D</div>
                        <p class="is-muted is-small">Leads atribuídos: dados do CRM ainda indisponíveis.</p>
                    </article>
                    <article>
                        <div class="is-step">03 · Crescer</div>
                        <h3>Clientes</h3>
                        <div class="is-number" aria-label="Dados de clientes indisponíveis">N/D</div>
                        <p class="is-muted is-small">Conversões confirmadas: sem atribuição verificada.</p>
                    </article>
                </div>
            </section>

            <section class="is-detail" aria-labelledby="seo-discovery-title">
                <h2 id="seo-discovery-title">Descoberta orgânica</h2>
                <p class="is-muted is-small">Cliques e impressões do Google Search Console são indicadores de pesquisa e não representam automaticamente visitas, leads ou clientes.</p>
                <div class="is-grid" style="margin-top:14px">
                    <div><div class="is-small is-muted">Cliques orgânicos</div><div class="is-number">N/D</div></div>
                    <div><div class="is-small is-muted">Impressões</div><div class="is-number">N/D</div></div>
                    <div><div class="is-small is-muted">CTR orgânico</div><div class="is-number">N/D</div></div>
                </div>
            </section>

            <section class="is-detail" aria-labelledby="seo-next-title">
                <h2 id="seo-next-title">Etapas de ativação</h2>
                <ul class="is-checks">
                    <li><strong>1. Segurança e identidade</strong>Login e MFA reais do Portal, permissões por empresa e revogação auditada.</li>
                    <li><strong>2. Autorizar a propriedade</strong>OAuth Google readonly e confirmação da propriedade com consentimento efetivo.</li>
                    <li><strong>3. Habilitar resultados</strong>Analytics, CRM e atribuição válidos antes de exibir qualquer métrica aos clientes.</li>
                </ul>
            </section>
            <footer class="is-foot">Imagine SEO Engine · F116 · Prévia interna não publicada · Sem dados reais e sem acesso a produção</footer>
        </main>
    </div>
</body>
</html>
