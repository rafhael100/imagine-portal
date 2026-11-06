<x-portal.layout current="overview">
    <div class="pi-grid">
        <x-portal.indicator label="Serviços no exemplo" :value="count(\App\Presentation\DemoServiceRequests::services())" />
        <x-portal.indicator label="Solicitações de exemplo" :value="count(\App\Presentation\DemoServiceRequests::requests())" />
        <x-portal.indicator label="Contratos ilustrativos" value="1" detail="Sem validade jurídica" />
    </div>
    <x-portal.panel title="Bem-vinda, Empresa Aurora">
        <p>Este resumo mostra como os serviços Imagine poderão ser organizados no Portal.</p>
        <x-portal.list label="Resumo dos serviços fictícios" :items="array_map(fn ($service) => $service->product . ' · exemplo fictício', \App\Presentation\DemoServiceRequests::services())" />
    </x-portal.panel>
    <x-portal.panel title="Atividades recentes"><x-portal.empty-state title="Nenhuma atividade demonstrativa" message="As atualizações dos seus serviços poderão aparecer aqui." /></x-portal.panel>
</x-portal.layout>
