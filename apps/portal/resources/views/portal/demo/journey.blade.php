<x-portal.layout current="journey">
    <x-portal.message title="DEMONSTRAÇÃO — dados fictícios" message="Jornada da Empresa Aurora fictícia. Nenhum serviço, contrato, campanha ou módulo está ativo no sistema por esta demonstração." />
    <div class="pi-grid">
        <x-portal.indicator label="Etapas ilustradas" value="1 de 4" detail="Progresso exclusivamente fictício" />
        <x-portal.indicator label="Serviços no cenário" value="3" detail="Onepage, Landing Page e Performance AI" />
        <x-portal.indicator label="Próximo foco do exemplo" value="Planejamento" detail="Sem envio ou execução" />
    </div>
    <x-portal.journey :steps="[
        ['title' => 'Conhecer as possibilidades', 'description' => 'Exemplo de apresentação dos serviços, sem contratação.', 'state' => 'illustrated'],
        ['title' => 'Organizar o projeto fictício', 'description' => 'Planejamento ilustrativo de Onepage, Landing Page e Performance AI.', 'state' => 'preparing'],
        ['title' => 'Revisar o material de exemplo', 'description' => 'Pendência fictícia de revisão de conteúdo, sem coleta ou envio.', 'state' => 'waiting'],
        ['title' => 'Visualizar o acompanhamento', 'description' => 'Próxima etapa demonstrativa, sem publicação ou campanha real.', 'state' => 'next'],
    ]" />
    <div class="pi-grid pi-grid-two">
        <x-portal.next-stage title="Revisar o roteiro fictício" description="Conferir os objetivos e conteúdos do exemplo antes de ilustrar o acompanhamento. Nenhuma ação operacional disponível." />
        <x-portal.pending-notice :items="['Revisão fictícia do conteúdo da Onepage', 'Organização ilustrativa dos objetivos da Landing Page', 'Definição demonstrativa dos indicadores de Performance AI']" />
    </div>
    <x-portal.panel title="Situações ilustrativas dos serviços">
        <div class="pi-grid pi-grid-two">
            <x-portal.next-stage title="Sites Imagine · Onepage" description="Serviço comercial independente. Preparação apenas fictícia, sem domínio ou publicação." state="preparing" />
            <x-portal.next-stage title="Sites Imagine · Landing Page" description="Serviço comercial independente da Onepage. Revisão ilustrativa, sem contratação." state="waiting" />
            <x-portal.next-stage title="Performance AI" description="Serviço próprio administrado pelo Portal. Execução externa não implementada; Google Ads e Meta são apenas descrições, sem campanhas conectadas." state="example" />
            <x-portal.next-stage title="Imagine CRM" description="Produto independente. Login e sessão próprios, sem SSO ou compartilhamento de acesso com o Portal." state="example" />
            <x-portal.next-stage title="Universidade Imagine" description="Benefício de clientes elegíveis, não uma assinatura avulsa. Nenhuma elegibilidade ou acesso concedido neste exemplo." state="example" />
        </div>
    </x-portal.panel>
</x-portal.layout>
