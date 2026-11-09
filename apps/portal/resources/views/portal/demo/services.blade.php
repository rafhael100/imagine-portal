<x-portal.layout current="services">
    <x-portal.message title="DEMONSTRAÇÃO — dados fictícios" message="Acompanhamento da Empresa Aurora fictícia. Os estados abaixo não comprovam contratação, elegibilidade ou execução de serviços." />
    <x-portal.service-tracking :services="\App\Presentation\DemoServiceRequests::services()" />
    <x-portal.message title="Produtos independentes" message="CRM Imagine possui identidade, login e sessão próprios, sem SSO com o Portal. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa; nenhum acesso é concedido neste cenário." />
    @php
        $demoRelations = \App\Presentation\DemoServiceRelationPreview::firstPage();
    @endphp
    @if ($demoRelations->isPreparedPreview())
        <x-portal.panel title="Relações entre serviços, solicitações e contratos — exemplo">
            <p>Projeção ilustrativa que permite vários registros por serviço, sem comprovar serviço ativo, contrato assinado ou direito de acesso.</p>
            <x-portal.list label="Quantidades vinculadas aos serviços fictícios"
                :items="\App\Presentation\DemoServiceRelationPreview::lines()" />
        </x-portal.panel>
    @else
        <x-portal.message title="Relações fictícias indisponíveis"
            message="Referências demonstrativas inconsistentes. Nenhuma contagem parcial será apresentada."
            tone="error" />
    @endif
    <x-portal.unavailable feature="O acompanhamento operacional dos serviços" />
</x-portal.layout>
