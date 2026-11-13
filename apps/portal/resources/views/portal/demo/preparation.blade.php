<x-portal.layout current="preparation">
    <x-portal.message title="DEMONSTRAÇÃO — preparação ilustrativa"
        message="Esta área mostra somente informações fictícias de Onepage, Landing Page e Performance AI. Não recebe documentos, cria solicitações, ativa anúncios, concede acessos ou publica sites." />
    <x-portal.panel title="Checklist de preparação dos serviços">
        <p>Confira os requisitos exemplificativos de cada produto, vinculados às mesmas solicitações demonstrativas de Meus serviços. Nenhum item representa aprovação, contrato ou execução efetiva.</p>
        @php($preparationRows = \App\Presentation\DemoServicePreparation::rows())
        @if (count($preparationRows) === 3)
            <x-portal.table caption="Preparação por serviço — conteúdo fictício"
                :columns="['Produto', 'Referência do serviço', 'Preparação ilustrativa', 'Solicitação', 'Estado da solicitação', 'Requisitos futuros']"
                :rows="$preparationRows" />
        @else
            <x-portal.empty-state title="Preparação não disponível"
                message="Os vínculos fictícios entre serviços e solicitações não são consistentes. Nenhum dado parcial será exibido." />
        @endif
    </x-portal.panel>
    <div class="pi-grid pi-grid-two">
        <x-portal.panel title="Sites Imagine">
            <p>Onepage e Landing Page são serviços independentes. Briefing, conteúdo, consentimento e domínio exigem revisão e autorização real antes de qualquer publicação.</p>
        </x-portal.panel>
        <x-portal.panel title="Performance AI">
            <p>Contas Google Ads e Meta, criativos e orçamento precisam de aprovação própria. Esta prévia não conecta contas nem inicia campanhas.</p>
        </x-portal.panel>
    </div>
    <x-portal.panel title="Proteções de acesso">
        <p>O CRM Imagine utiliza login e sessão próprios. A Universidade Imagine depende de serviço elegível ativo, sem acesso concedido por esta demonstração.</p>
        <p class="pi-muted">Sem identidade Portal ativa e MFA validado, nenhuma operação de cliente está disponível.</p>
    </x-portal.panel>
    <x-portal.unavailable feature="Envio de documentos, edição, ativação, publicação ou provisionamento" />
</x-portal.layout>
