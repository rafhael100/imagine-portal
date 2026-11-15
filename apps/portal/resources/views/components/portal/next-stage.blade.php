@props(['title', 'description', 'state' => 'next'])
<article class="pi-panel">
    <x-portal.service-status :state="$state" />
    <h2 class="pi-stage-title">{{ $title }}</h2>
    <p>{{ $description }}</p>
    <p class="pi-muted">Planejamento fictício · nenhuma ação será executada</p>
</article>
