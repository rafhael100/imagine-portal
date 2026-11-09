<x-portal.layout current="available">
    <x-portal.message title="DEMONSTRAÇÃO — catálogo de serviços fictício"
        message="Os produtos abaixo são apresentados somente para revisão visual. Não há preço publicado, contratação, provisionamento, cobrança ou acesso a contas de clientes." />
    <div class="pi-grid pi-grid-two">
        @foreach (\App\Presentation\DemoServicePortfolio::items() as $item)
            <x-portal.service-card :name="$item->product->label()"
                :description="$item->description . ' ' . $item->statusDetail() . '.'"
                :status="$item->statusLabel()" />
        @endforeach
    </div>
    <x-portal.message title="Serviços e benefícios independentes"
        message="Onepage e Landing Page são serviços contratados separadamente. CRM Imagine utiliza identidade, login e sessão próprios, sem SSO com o Portal. Universidade Imagine é benefício de clientes elegíveis, não assinatura avulsa. Nenhum acesso ou direito é concedido por esta demonstração." />
    <x-portal.panel title="Universidade Imagine — critérios de benefício, não de venda">
        <p>Acesso à Universidade não verificado. Os pré-requisitos abaixo são orientações de política, não uma consulta a contratos, assinaturas ou organizações reais.</p>
        <x-portal.list label="Condições para uma futura verificação de elegibilidade"
            :items="\App\Presentation\DemoUniversityBenefitRequirements::items()" />
        <p class="pi-muted">Nenhum benefício está ativo, habilitado ou autorizado por este painel demonstrativo.</p>
    </x-portal.panel>
    <x-portal.unavailable feature="A contratação de serviços" />
</x-portal.layout>
