<x-portal.layout current="services">
    <x-portal.message title="DEMONSTRAÇÃO — dados fictícios" message="Acompanhamento da Empresa Aurora fictícia. Os estados abaixo não comprovam contratação, elegibilidade ou execução de serviços." />
    <x-portal.service-tracking :services="\App\Presentation\DemoServiceRequests::services()" />
    <x-portal.panel title="Preparação dos serviços — demonstração">
        <p>Requisitos por produto e solicitações correspondentes, calculados somente a partir dos mesmos exemplos exibidos acima. Não há envio de materiais, atualização de status, prazo, contratação ou acesso a serviços reais.</p>
        @php($preparationRows = \App\Presentation\DemoServicePreparation::rows())
        @if (count($preparationRows) === 3)
            <x-portal.table caption="Checklist de preparação por serviço — somente dados fictícios"
                :columns="['Serviço', 'Referência', 'Etapa ilustrativa', 'Solicitação', 'Estado do pedido', 'Requisitos futuros']"
                :rows="$preparationRows" />
        @else
            <x-portal.empty-state title="Preparação indisponível"
                message="Os vínculos dos serviços e solicitações de exemplo estão inconsistentes. Não apresentamos dados parciais." />
        @endif
    </x-portal.panel>
    <x-portal.message title="Produtos independentes" message="CRM Imagine possui identidade, login e sessão próprios, sem SSO com o Portal. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa; nenhum acesso é concedido neste cenário." />
    <x-portal.unavailable feature="O acompanhamento operacional dos serviços" />
</x-portal.layout>
