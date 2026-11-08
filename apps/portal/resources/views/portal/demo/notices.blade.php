<x-portal.layout title="Central de Avisos" current="notices">
    <x-portal.notice-context />
    <x-portal.notice-list :catalog="$catalog ?? \App\Presentation\DemoNoticeCatalog::examples()" />
    <x-portal.message title="Operações indisponíveis" message="Não há ações de marcar como lido, envio de notificações, canais de contato ou integração com produtos nesta demonstração." tone="warning" />
</x-portal.layout>
