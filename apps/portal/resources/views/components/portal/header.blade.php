@props(['title', 'subtitle' => 'Empresa Aurora · exemplo fictício'])
<header class="pi-header">
    <div><p class="pi-eyebrow">Área do cliente · Demonstração</p><h1>{{ $title }}</h1><p class="pi-muted">{{ $subtitle }}</p></div>
    <x-portal.badge label="Dados fictícios" />
</header>
