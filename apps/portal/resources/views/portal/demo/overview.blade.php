<x-portal.layout current="overview">
    @php
        $demoOverview = \App\Presentation\DemoClientOverview::summary();
    @endphp
    <x-portal.message title="DEMONSTRAÇÃO — registros fictícios" message="Este painel não consulta clientes, contratos ou solicitações reais. As situações abaixo não comprovam prestação, assinatura ou elegibilidade." />
    <div class="pi-grid">
        <x-portal.indicator label="Serviços no exemplo" :value="$demoOverview->serviceCount" />
        <x-portal.indicator label="Solicitações de exemplo" :value="$demoOverview->requestCount" />
        <x-portal.indicator label="Contratos ilustrativos" :value="$demoOverview->contractCount" detail="Sem validade jurídica" />
    </div>
    <x-portal.panel title="Bem-vinda, Empresa Aurora">
        <p>Este resumo mostra como os serviços Imagine poderão ser organizados no Portal, sem conexões operacionais.</p>
        <x-portal.list label="Resumo dos serviços fictícios" :items="array_map(fn ($service) => $service->product . ' · exemplo fictício', \App\Presentation\DemoServiceRequests::services())" />
    </x-portal.panel>
    @if ($demoOverview->isConsistent())
        <x-portal.panel title="Acompanhamento unificado — somente exemplo">
            <p>Serviços independentes, solicitações e contratos reconciliados exclusivamente em memória, sem acesso concedido ou operações habilitadas.</p>
            <x-portal.table caption="Resumo integrado fictício — sem vínculo operacional"
                :columns="['Referência', 'Serviço', 'Solicitação ilustrativa', 'Contrato ilustrativo']"
                :rows="\App\Presentation\DemoClientOverview::rows()" />
        </x-portal.panel>
    @else
        <x-portal.message title="Resumo ilustrativo indisponível" message="Os registros demonstrativos não são consistentes. Nenhum dado parcial será apresentado." tone="error" />
    @endif
    <x-portal.panel title="Atividades recentes"><x-portal.empty-state title="Nenhuma atividade demonstrativa" message="As atualizações dos seus serviços poderão aparecer aqui." /></x-portal.panel>
</x-portal.layout>
