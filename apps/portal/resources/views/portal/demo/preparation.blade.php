<x-portal.layout current="preparation">
    <x-portal.message title="DEMONSTRAÇÃO — preparação sem operações reais"
        message="Esta tela organiza requisitos ilustrativos de Onepage, Landing Page e Performance AI. Não recebe documentos, ativa serviços, publica sites, conecta anúncios nem define prazos." />
    <x-portal.panel title="O que falta preparar em cada serviço">
        <p>Os próximos passos são derivados das mesmas solicitações e contratos fictícios exibidos no painel. Não há ordem cronológica, prazo prometido ou conclusão comprovada.</p>
        @php($demoReadiness = \App\Presentation\DemoServicePreparation::rows())
        @if (count($demoReadiness) === 3)
            <x-portal.table caption="Preparação por serviço — exemplo sem ativação"
                :columns="['Serviço', 'Solicitação', 'Situação ilustrativa', 'Próxima etapa', 'Requisitos futuros']"
                :rows="$demoReadiness" />
        @else
            <x-portal.empty-state title="Preparação ilustrativa indisponível"
                message="As referências de serviços e solicitações não estão consistentes. Nenhuma preparação parcial será exibida." />
        @endif
    </x-portal.panel>
    <div class="pi-grid pi-grid-two">
        <x-portal.panel title="Sites Imagine">
            <p>Onepage e Landing Page são produtos independentes, com conteúdo, objetivos e aprovações separados.</p>
            <x-portal.list label="O que será validado antes da publicação"
                :items="['Briefing e identidade visual do projeto', 'Revisão e aprovação documentada do conteúdo', 'Propriedade do domínio, SSL e destino dos formulários', 'Bloqueios de publicação sem autorização explícita']" />
        </x-portal.panel>
        <x-portal.panel title="Performance AI">
            <p>As campanhas Google Ads e Meta pertencem ao motor de Performance, separado da administração do Portal.</p>
            <x-portal.list label="O que será validado antes de iniciar mídia"
                :items="['Objetivos e métricas aprovadas pelo cliente', 'Contas de anúncios e permissões verificadas', 'Criativos aprovados por peça', 'Orçamento e execução autorizados fora desta prévia']" />
        </x-portal.panel>
    </div>
    <x-portal.panel title="Segurança e acesso entre produtos">
        <x-portal.list label="Regras de acesso e ativação"
            :items="\App\Presentation\DemoServicePreparation::safeguards()" />
        <p class="pi-muted">Estes itens são critérios de preparação, não confirmação de elegibilidade, conta ativa, assinatura ou acesso concedido.</p>
    </x-portal.panel>
    <x-portal.unavailable feature="O envio de documentos, ativação, publicação e provisionamento de serviços" />
</x-portal.layout>
