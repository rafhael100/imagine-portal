@props(['topic'])
<article class="pi-panel">
    <x-portal.badge :label="$topic->category->label()" />
    <h3>{{ $topic->title }}</h3>
    <p>{{ $topic->guidance }}</p>
    <p class="pi-muted">Orientação fictícia · sem resposta oficial ou ação operacional</p>
</article>
