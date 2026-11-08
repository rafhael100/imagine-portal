@props(['catalog', 'title' => 'Avisos exclusivamente demonstrativos'])
<section aria-label="Lista de avisos fictícios" style="min-width:0;overflow-wrap:anywhere">
    <h2>{{ $title }}</h2>
    @if(count($catalog->notices) > 0)
        <ul class="pi-grid pi-grid-two" style="list-style:none;margin:0;padding:0">
            @foreach($catalog->notices as $notice)
                <li style="min-width:0"><x-portal.notice-card :notice="$notice" /></li>
            @endforeach
        </ul>
    @else
        <x-portal.empty-state title="Nenhum aviso fictício" message="Este cenário não possui exemplos. Isso não representa uma caixa de notificações reais." />
    @endif
</section>
