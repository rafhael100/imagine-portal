@props(['title' => 'Central de Avisos — DEMONSTRAÇÃO', 'message' => 'Exemplos fictícios, sem destinatário real, envio, leitura persistida, confirmação ou acesso concedido.'])
<x-portal.panel :title="$title">
    <x-portal.message title="Somente apresentação, sem notificações reais" :message="$message" />
    <p class="pi-muted">Nenhum aviso foi enviado. A exibição não comprova contratação, estado de serviço ou elegibilidade.</p>
</x-portal.panel>
