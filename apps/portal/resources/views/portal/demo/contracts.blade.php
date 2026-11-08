<x-portal.layout current="contracts">
    <x-portal.panel title="Documentos de exemplo">
        <x-portal.table caption="Contratos fictícios — sem validade jurídica"
            :columns="['Referência', 'Serviço', 'Situação', 'Assinatura']"
            :rows="[['DEMO-001', 'Performance AI', 'Rascunho ilustrativo', 'Não realizada']]" />
    </x-portal.panel>
    <x-portal.unavailable feature="A assinatura de contratos e os pagamentos" />
    <x-portal.message title="Exemplo de mensagem de erro" message="Não foi possível carregar um documento fictício. Nenhum envio ou assinatura foi realizado." tone="error" />
</x-portal.layout>
