@props(['name', 'description', 'status' => 'Exemplo', 'tone' => 'neutral'])
<article class="pi-panel pi-service">
    <x-portal.badge :label="$status" :tone="$tone" />
    <h2>{{ $name }}</h2><p>{{ $description }}</p>
    <p class="pi-muted">Apresentação ilustrativa · sem acesso operacional</p>
</article>
