<x-portal.layout current="contracts">
    <x-portal.panel title="Documentos de exemplo">
        <x-portal.table caption="Contratos fictícios — sem validade jurídica"
            :columns="['Referência', 'Serviço', 'Situação', 'Assinatura']"
            :rows="\App\Presentation\DemoContractOverview::rows()" />
    </x-portal.panel>
    <x-portal.message title="Contratos independentes — demonstração" message="Onepage, Landing Page e Performance AI são serviços distintos. Esta lista não comprova compra, assinatura, autorização de acesso, cobrança ou direito de uso." />
    <x-portal.unavailable feature="A assinatura de contratos e os pagamentos" />
    <x-portal.message title="Exemplo de mensagem de erro" message="Não foi possível carregar um documento fictício. Nenhum envio ou assinatura foi realizado." tone="error" />
</x-portal.layout>
