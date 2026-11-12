<x-portal.layout current="requests">
    <x-portal.message title="DEMONSTRAÇÃO — dados fictícios" message="Solicitações e atualizações da Empresa Aurora fictícia. Nenhum chamado foi aberto ou enviado; estados não concedem permissões, aprovação ou atendimento." />
    <x-portal.request-list :requests="\App\Presentation\DemoServiceRequests::requests()" />
    <x-portal.panel title="Onepage e Landing Page — revisão editorial fictícia">
        <p>Briefings e propostas de texto são exemplos locais. Uma aprovação simulada não representa aceite assinado, contratação ou autorização de publicar um site.</p>
        <x-portal.table caption="Conteúdos ilustrativos de Sites Imagine — sem aprovação real"
            :columns="['Referência', 'Produto', 'Título fictício', 'Versões de exemplo', 'Revisão editorial']"
            :rows="\App\Presentation\DemoSitesEditorialPreview::rows()" />
        <p>Alterar briefing ou conteúdo exige nova revisão da versão. Esta tela não coleta dados, cobra nem publica.</p>
    </x-portal.panel>
    <x-portal.message title="Serviços independentes" message="Onepage e Landing Page são serviços independentes. Performance AI possui motor de execução separado. CRM Imagine mantém identidade, login e sessão próprios, sem SSO. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa; nenhuma elegibilidade é concedida neste exemplo." />
    <x-portal.unavailable feature="A abertura, edição, envio e anexação de solicitações" />
</x-portal.layout>
