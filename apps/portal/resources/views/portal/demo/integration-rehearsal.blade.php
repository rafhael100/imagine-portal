<x-portal.layout title="Homologação funcional — prévia integrada PG18" current="preparation">
    <x-portal.message title="LABORATÓRIO DESCARTÁVEL — NÃO É ÁREA DO CLIENTE"
        message="Esta prévia contém somente organizações, serviços, checklists e solicitações sintéticos. Foi gerada na CI com PostgreSQL 18. Não existe login, publicação, cobrança, sessão ou atendimento." tone="warning" />
    <nav class="pi-panel" aria-label="Navegação interna da prévia integrada">
        <p class="pi-eyebrow">Explorar esta prévia offline</p>
        <a class="pi-badge pi-tone-info" href="#pi-pg18-summary">Resumo</a>
        <a class="pi-badge pi-tone-info" href="#pi-pg18-details">Projetos</a>
        <a class="pi-badge pi-tone-info" href="#pi-pg18-projects">Preparação</a>
        <a class="pi-badge pi-tone-info" href="#pi-pg18-requests">Solicitações</a>
    </nav>
    <section id="pi-pg18-summary" aria-label="Resumo integrado dos serviços fictícios">
        <x-portal.panel title="Visão geral dos serviços — dados fictícios">
            <p>Indicadores calculados a partir das mesmas projeções PostgreSQL 18 apresentadas abaixo. Nenhum número representa clientes ou serviços em produção.</p>
            <div class="pi-grid" role="group" aria-label="Indicadores demonstrativos">
                <div class="pi-panel">
                    <p class="pi-metric-label">Projetos demonstrativos</p>
                    <p class="pi-number">{{ $overview['projects'] }}</p>
                </div>
                <div class="pi-panel">
                    <p class="pi-metric-label">Itens ilustrativos revisados</p>
                    <p class="pi-number">{{ $overview['checkpoints_reviewed'] }}/{{ $overview['checkpoints_total'] }}</p>
                </div>
                <div class="pi-panel">
                    <p class="pi-metric-label">Solicitações fictícias</p>
                    <p class="pi-number">{{ $overview['requests'] }}</p>
                </div>
            </div>
        </x-portal.panel>
    </section>
    <section id="pi-pg18-details" aria-label="Detalhes fictícios de cada serviço">
        <x-portal.panel title="Acompanhe cada projeto — demonstração integrada">
            <p>As fichas relacionam os projetos, os itens de preparação e suas solicitações usando somente registros sintéticos previamente conciliados. As próximas etapas são orientações ilustrativas, não tarefas reais.</p>
            <div class="pi-grid">
                @foreach ($projectCards as $card)
                    <article class="pi-panel" aria-label="Projeto fictício {{ $card['reference'] }}">
                        <p class="pi-eyebrow">{{ $card['product'] }}</p>
                        <h3>{{ $card['reference'] }}</h3>
                        <p><strong>Preparação:</strong> {{ $card['preparation'] }}</p>
                        <p><strong>Progresso:</strong> {{ $card['progress'] }}</p>
                        <p><strong>Pendências:</strong> {{ $card['pending'] }}</p>
                        <p><strong>Solicitação:</strong> {{ $card['request'] }}</p>
                        <p><strong>Estado da solicitação:</strong> {{ $card['request_status'] }}</p>
                        <p class="pi-muted">{{ $card['request_revision'] }}</p>
                        <p><strong>Próxima etapa ilustrativa:</strong> {{ $card['next_step'] }}</p>
                    </article>
                @endforeach
            </div>
        </x-portal.panel>
    </section>
    <section id="pi-pg18-projects" aria-label="Preparação fictícia dos projetos">
    <x-portal.panel title="Projetos e preparação — dados de teste persistidos">
        <p>Os registros abaixo foram consultados diretamente do PostgreSQL 18 descartável por leitores exclusivos de teste, com empresa e projeto verificados em uma transação somente de leitura. Itens revisados são eventos fictícios, não aprovação de entrega.</p>
        <x-portal.table caption="Checklists do PostgreSQL 18 — demonstração não operacional"
            :columns="['Produto', 'Referência do projeto', 'Situação fictícia', 'Itens revisados', 'Itens ainda pendentes']"
            :rows="$preparationRows" />
    </x-portal.panel>
    </section>
    <section id="pi-pg18-requests" aria-label="Solicitações fictícias dos projetos">
    <x-portal.panel title="Solicitações e histórico — dados de teste persistidos">
        <p>Cada solicitação representa um fluxo fictício com revisões registradas em transação e verificadas contra a trilha de auditoria antes da apresentação. Não há botões de abertura, edição, envio, reenvio ou suporte.</p>
        <x-portal.table caption="Solicitações do PostgreSQL 18 — histórico conciliado"
            :columns="['Solicitação', 'Projeto', 'Estado fictício', 'Revisão registrada']"
            :rows="$requestRows" />
    </x-portal.panel>
    </section>
    <div class="pi-grid pi-grid-two">
        <x-portal.panel title="Identidade Imagine preservada">
            <p>Interface baseada no Portal Imagine aprovado, com logomarca oficial, navegação leve, cores laranja e grafite e visual responsivo. A imagem desta prévia é incorporada ao HTML para abertura offline, sem carregamento externo.</p>
        </x-portal.panel>
        <x-portal.panel title="Gates de segurança ainda fechados">
            <p>Não há permissões SQL para o usuário público, nem acesso real a CRM, Sites, Performance AI ou Universidade. A liberação exige MFA, contratos, isolamento autorizado, witness independente de revogação e revisão AppSec/DBA.</p>
        </x-portal.panel>
    </div>
    <x-portal.unavailable feature="Acesso, edição, revisão real, publicação, anúncios, contratos ou pagamentos" />
</x-portal.layout>
