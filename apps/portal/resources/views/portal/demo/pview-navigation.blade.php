{{-- PVIEW-03: Blade-only demonstration. No HTTP route, auth, DB, grants or live integrations. --}}
<x-portal.pview-shell>
    @foreach (\App\Presentation\PviewSection::cases() as $section)
        <section class="pi-demo-screen"
                 id="pview-{{ $section->value }}"
                 data-screen="{{ $section->value }}"
                 tabindex="-1"
                 aria-labelledby="pview-heading-{{ $section->value }}">
            <div class="pi-screen-head">
                <div>
                    <span class="pi-eyebrow">Ecossistema Imagine · dados ilustrativos</span>
                    <h1 class="pi-screen-title" id="pview-heading-{{ $section->value }}">{{ $section->label() }}</h1>
                    <p class="pi-lede">{{ $section->description() }}</p>
                </div>
                <span class="pi-pill gray">Demonstração</span>
            </div>

            @if ($section === \App\Presentation\PviewSection::Overview)
                <div class="pi-grid four" aria-label="Indicadores fictícios">
                    <article class="pi-card"><div class="pi-metric-label">Serviços no exemplo</div><div class="pi-metric-value">02</div><div class="pi-metric-note">Não representa contratações</div></article>
                    <article class="pi-card"><div class="pi-metric-label">Solicitações ilustrativas</div><div class="pi-metric-value">03</div><div class="pi-metric-note">Sem chamados reais</div></article>
                    <article class="pi-card"><div class="pi-metric-label">Etapas demonstrativas</div><div class="pi-metric-value">04</div><div class="pi-metric-note">Sem execução operacional</div></article>
                    <article class="pi-card"><div class="pi-metric-label">Avisos fictícios</div><div class="pi-metric-value">03</div><div class="pi-metric-note">Sem destinatários</div></article>
                </div>
                <div class="pi-feature pi-section-gap">
                    <div class="pi-hero"><h2>Sua operação, mais simples.</h2><p>Visualize o ecossistema Imagine em um painel. Os dados desta prévia são inteiramente fictícios.</p><a href="#pview-services">Explorar meus serviços de exemplo</a></div>
                    <div class="pi-soft"><h2>Conheça as soluções Imagine</h2><p>Sites, CRM, Performance AI e Universidade em uma experiência unificada, com contratos e motores independentes.</p><a href="#pview-catalog">Explorar catálogo ilustrativo</a></div>
                </div>
                <div class="pi-card pi-section-gap">
                    <div class="pi-rowhead"><h2>Atividades recentes de exemplo</h2><span class="pi-pill gray">Fictícias</span></div>
                    <div class="pi-listitem"><div class="pi-listcontent"><span class="pi-symbol" aria-hidden="true">◈</span><div><b>Briefing demonstrativo organizado</b><small>Exemplo de projeto Onepage</small></div></div><span class="pi-pill green">Ilustrativo</span></div>
                    <div class="pi-listitem"><div class="pi-listcontent"><span class="pi-symbol" aria-hidden="true">▤</span><div><b>Solicitação de exemplo</b><small>Nenhum chamado foi aberto</small></div></div><span class="pi-pill gray">Demo</span></div>
                </div>

            @elseif ($section === \App\Presentation\PviewSection::Services)
                <div class="pi-grid">
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◈</span><h3>Imagine Sites</h3><p>Onepage fictícia, com briefing e produção somente ilustrativos.</p><span class="pi-pill amber">Em preparação (exemplo)</span><a class="pi-link" href="#pview-requests">Ver solicitações fictícias</a></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◎</span><h3>Imagine Performance AI</h3><p>Painel visual sem contas, campanhas, métricas ou gastos conectados.</p><span class="pi-pill gray">Sem conexão</span><a class="pi-link" href="#pview-performance">Visualizar módulo</a></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◇</span><h3>Imagine CRM</h3><p>Produto independente do Portal, com login, sessão e dados próprios.</p><span class="pi-pill gray">Sem SSO</span><a class="pi-link" href="#pview-catalog">Conhecer catálogo</a></article>
                </div>

            @elseif ($section === \App\Presentation\PviewSection::Catalog)
                <div class="pi-grid">
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◈</span><h3>Imagine Sites</h3><p>Onepage e Landing Page independentes, com jornada de produção própria.</p><span class="pi-pill gray">Sem contratação</span></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◇</span><h3>Imagine CRM</h3><p>Gestão de relacionamento e oportunidades com autenticação separada.</p><span class="pi-pill gray">Sem contratação</span></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◎</span><h3>Imagine Performance AI</h3><p>Google Ads + Meta (Instagram e/ou Facebook) com motor técnico independente.</p><span class="pi-pill gray">Sem contratação</span></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◉</span><h3>Universidade Imagine</h3><p>Benefício exclusivo para clientes com serviços Imagine elegíveis e ativos.</p><span class="pi-pill gray">Acesso não avaliado</span></article>
                </div>

            @elseif ($section === \App\Presentation\PviewSection::Requests)
                <div class="pi-card">
                    <div class="pi-rowhead"><h2>Solicitações demonstrativas</h2><span class="pi-pill gray">Sem abertura real</span></div>
                    <div class="pi-tablewrap"><table><thead><tr><th scope="col">Solicitação fictícia</th><th scope="col">Categoria</th><th scope="col">Estado ilustrativo</th></tr></thead>
                        <tbody>
                            <tr><td>Atualização de conteúdo de exemplo</td><td>Imagine Sites</td><td><span class="pi-pill amber">Em análise</span></td></tr>
                            <tr><td>Orientação sobre catálogo</td><td>Atendimento</td><td><span class="pi-pill gray">Recebida</span></td></tr>
                            <tr><td>Revisão de briefing fictício</td><td>Produção</td><td><span class="pi-pill green">Concluído (exemplo)</span></td></tr>
                        </tbody>
                    </table></div>
                </div>
                <div class="pi-soft pi-section-gap"><h2>Não há chamados em funcionamento</h2><p>Fluxo sem envio, SLA, atribuição a profissionais ou persistência. A implementação real dependerá de autenticação, isolamento multiempresa e permissões.</p></div>

            @elseif ($section === \App\Presentation\PviewSection::Contracts)
                <div class="pi-empty"><h2>Nenhum contrato conectado</h2><p>Esta demonstração não carrega informações financeiras, assinaturas, documentos jurídicos, cartões ou pagamentos.</p><a class="pi-link" href="#pview-catalog">Explorar produtos ilustrativos</a></div>

            @elseif ($section === \App\Presentation\PviewSection::Performance)
                <x-portal.pview-performance-detail />

            @elseif ($section === \App\Presentation\PviewSection::University)
                <div class="pi-grid">
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◉</span><h3>Marketing e vendas</h3><p>Orientações futuras para negócios, sem conteúdo entregue nesta prévia.</p></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">✧</span><h3>Inteligência artificial</h3><p>Exemplo de temática educacional sem curso ativo.</p></article>
                    <article class="pi-card pi-tile"><span class="pi-symbol" aria-hidden="true">◇</span><h3>Produtividade</h3><p>Aprendizado empresarial sem notas, provas nem certificados.</p></article>
                </div>
                <div class="pi-soft pi-section-gap"><h2>Benefício associado aos serviços Imagine</h2><p>Somente clientes com serviços elegíveis ativos poderão ter acesso. A elegibilidade não é verificada nesta prévia.</p></div>

            @elseif ($section === \App\Presentation\PviewSection::Notices)
                <div class="pi-card">
                    <div class="pi-rowhead"><h2>Avisos de demonstração</h2><span class="pi-pill gray">Sem envio</span></div>
                    <div class="pi-listitem"><div class="pi-listcontent"><span class="pi-symbol" aria-hidden="true">◈</span><div><b>Orientação sobre briefing</b><small>Aviso fictício do Imagine Sites, sem destinatário</small></div></div><span class="pi-pill gray">Demo</span></div>
                    <div class="pi-listitem"><div class="pi-listcontent"><span class="pi-symbol" aria-hidden="true">◎</span><div><b>Acompanhamento de mídia</b><small>Exemplo de comunicado Performance AI</small></div></div><span class="pi-pill gray">Demo</span></div>
                    <div class="pi-listitem"><div class="pi-listcontent"><span class="pi-symbol" aria-hidden="true">◉</span><div><b>Conteúdo de aprendizagem</b><small>Sem cursos ou notificações reais</small></div></div><span class="pi-pill gray">Demo</span></div>
                </div>
            @endif
        </section>
    @endforeach
</x-portal.pview-shell>
