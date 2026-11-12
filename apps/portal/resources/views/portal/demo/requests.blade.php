<x-portal.layout current="requests">
    <x-portal.message title="DEMONSTRAÇÃO — dados fictícios" message="Solicitações e atualizações da Empresa Aurora fictícia. Nenhum chamado foi aberto ou enviado; estados não concedem permissões, aprovação ou atendimento." />
    <x-portal.request-list :requests="\App\Presentation\DemoServiceRequests::requests()" />
    <x-portal.panel title="Fila fictícia de solicitações — somente leitura">
        <p>Exemplos locais da Empresa Aurora. A listagem não abre chamados, consulta contratos ou comprova prazos e SLAs.</p>
        <x-portal.table caption="Solicitações de exemplo — nenhuma operação disponível"
            :columns="['Referência', 'Serviço', 'Situação ilustrativa', 'Versão fictícia']"
            :rows="\App\Presentation\DemoRequestInbox::rows()" />
    </x-portal.panel>
    <x-portal.panel title="Resumo ilustrativo por situação">
        <p>Contagem derivada da mesma lista fictícia, sem dados de clientes. Se faltar parte do conjunto, nenhum total será apresentado.</p>
        <x-portal.table caption="Distribuição da fila de exemplo — sem dados operacionais"
            :columns="['Situação', 'Total fictício']"
            :rows="\App\Presentation\DemoRequestInboxStatusBoard::rows()" />
    </x-portal.panel>
    <x-portal.panel title="Fluxo de solicitações — sete transições fictícias">
        <p>As possíveis mudanças de estado ilustram um fluxo de revisão. Nenhum perfil aqui é uma identidade autenticada; nenhuma transição pode ser executada ou persistida.</p>
        <x-portal.table caption="Mapa de sete transições ilustrativas — sem envio"
            :columns="['Estado atual', 'Perfil fictício', 'Ação de exemplo', 'Próximo estado']"
            :rows="\App\Presentation\DemoRequestWorkflowMap::rows()" />
    </x-portal.panel>
    <x-portal.message title="Serviços independentes" message="Onepage e Landing Page são serviços independentes. Performance AI possui motor de execução separado. CRM Imagine mantém identidade, login e sessão próprios, sem SSO. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa; nenhuma elegibilidade é concedida neste exemplo." />
    <x-portal.unavailable feature="A abertura, edição, envio e anexação de solicitações" />
</x-portal.layout>
