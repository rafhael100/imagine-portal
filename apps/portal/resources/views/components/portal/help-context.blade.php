@props(['title' => 'Contexto da ajuda demonstrativa', 'message' => 'Exemplos exclusivamente fictícios, sem canal de atendimento, envio de dados ou garantia de resposta.'])
<x-portal.panel :title="$title">
    <x-portal.message title="Conteúdo ilustrativo, sem atendimento disponível" :message="$message" />
    <p class="pi-muted">Ler uma orientação não comprova acesso, estado de serviço ou elegibilidade.</p>
</x-portal.panel>
