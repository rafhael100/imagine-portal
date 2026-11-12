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
        <p>Mapa de transições possíveis no fluxo demonstrativo. Os perfis são apenas rótulos de simulação, não usuários autenticados nem concessões de acesso.</p>
        <x-portal.table caption="Mapa fictício de sete transições — sem execução"
            :columns="['Estado atual', 'Perfil simulado', 'Ação ilustrativa', 'Próximo estado']"
            :rows="\App\Presentation\DemoRequestWorkflowMap::rows()" />
    </x-portal.panel>
    <x-portal.panel title="Fila de solicitações — somente demonstração">
        <p>Lista fictícia da Empresa Aurora; nenhum filtro autoriza acesso a dados reais, nenhum SLA ou prazo é demonstrado.</p>
        <x-portal.table caption="Fila fictícia de solicitações — sem dados reais"
            :columns="['Referência', 'Serviço', 'Estado ilustrativo', 'Versão de exemplo']"
            :rows="\App\Presentation\DemoRequestInbox::rows()" />
    </x-portal.panel>
    <x-portal.panel title="Resumo por situação — apenas exemplos">
        <p>Contagem derivada da mesma fila fictícia acima, sem dados de clientes, prazos, compromissos ou indicadores operacionais. Se o conjunto estiver incompleto, o resumo não será apresentado.</p>
        <x-portal.table caption="Distribuição de solicitações fictícias — total de exemplo, não de atendimento"
            :columns="['Situação ilustrativa', 'Solicitações de exemplo']"
            :rows="\App\Presentation\DemoRequestInboxStatusBoard::rows()" />
    </x-portal.panel>
    <x-portal.panel title="Onepage e Landing Page — briefing e revisão de conteúdo fictícios">
        <p>Exemplos locais de briefing, versão de conteúdo e conferência editorial. Uma aprovação simulada não representa aceite assinado, contratação ou autorização para publicar um site.</p>
        <x-portal.table caption="Conteúdos fictícios de Sites Imagine — sem aprovação real"
            :columns="['Solicitação', 'Produto', 'Título ilustrativo', 'Versões do exemplo', 'Revisão editorial']"
            :rows="\App\Presentation\DemoSitesEditorialPreview::rows()" />
        <p>Se o briefing ou o conteúdo mudar, uma revisão anterior precisa ser refeita para a nova versão. Nenhuma publicação ou cobrança está disponível nesta tela.</p>
    </x-portal.panel>
    <x-portal.message title="Serviços independentes" message="Onepage e Landing Page são serviços independentes. Performance AI possui motor de execução separado. CRM Imagine mantém identidade, login e sessão próprios, sem SSO. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa; nenhuma elegibilidade é concedida neste exemplo." />
    <x-portal.unavailable feature="A abertura, edição, envio e anexação de solicitações" />
</x-portal.layout>
