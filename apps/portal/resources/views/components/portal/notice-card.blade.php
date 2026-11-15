@props(['notice'])
<article class="pi-panel">
    <x-portal.badge :label="$notice->category->label()" />
    <h3>{{ $notice->title }}</h3>
    <p>{{ $notice->body }}</p>
    <p class="pi-muted">Aviso fictício · sem envio ou estado de leitura persistido</p>
</article>
