<x-portal.layout current="requests">
    <x-portal.message title="DEMONSTRAÇÃO — dados fictícios" message="Solicitações e atualizações da Empresa Aurora fictícia. Nenhum chamado foi aberto ou enviado; estados não concedem permissões, aprovação ou atendimento." />
    <x-portal.request-list :requests="\App\Presentation\DemoServiceRequests::requests()" />
    <x-portal.panel title="Etapas de atendimento — apenas exemplo">
        <p>Uma solicitação poderá percorrer os estados abaixo. Este painel é somente leitura: não salva dados, abre chamados ou concede permissões.</p>
        <ul aria-label="Estados ilustrativos do atendimento">
            @foreach (\App\Domain\ServiceRequests\ServiceRequestStatus::cases() as $workflowState)
                <li>{{ $workflowState->label() }}</li>
            @endforeach
        </ul>
    </x-portal.panel>
    <x-portal.message title="Serviços independentes" message="Onepage e Landing Page são serviços independentes. Performance AI possui motor de execução separado. CRM Imagine mantém identidade, login e sessão próprios, sem SSO. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa; nenhuma elegibilidade é concedida neste exemplo." />
    <x-portal.unavailable feature="A abertura, edição, envio e anexação de solicitações" />
</x-portal.layout>
