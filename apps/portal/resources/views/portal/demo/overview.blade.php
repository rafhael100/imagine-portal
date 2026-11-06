<x-portal.layout title="Visão geral do cliente" current="overview">
    <div class="pi-grid">
        <x-portal.indicator label="Serviços no exemplo" value="2" />
        <x-portal.indicator label="Solicitações de exemplo" value="0" />
        <x-portal.indicator label="Contratos ilustrativos" value="1" detail="Sem validade jurídica" />
    </div>
    <x-portal.panel title="Bem-vinda, Empresa Aurora">
        <p>Este resumo mostra como os serviços Imagine poderão ser organizados no Portal.</p>
        <x-portal.list label="Resumo dos serviços fictícios" :items="['Performance AI · acompanhamento demonstrativo', 'Sites · projeto ilustrativo em preparação']" />
    </x-portal.panel>
    <x-portal.panel title="Atividades recentes"><x-portal.empty-state title="Nenhuma atividade demonstrativa" message="As atualizações dos seus serviços poderão aparecer aqui." /></x-portal.panel>
</x-portal.layout>
