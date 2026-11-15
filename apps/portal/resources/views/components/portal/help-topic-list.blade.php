@props(['catalog', 'title' => 'Orientações demonstrativas por assunto'])
<section aria-label="Tópicos demonstrativos de ajuda" style="min-width:0;overflow-wrap:anywhere">
    <h2>{{ $title }}</h2>
    @if(count($catalog->topics) > 0)
        <div class="pi-grid pi-grid-two">
            @foreach($catalog->topics as $topic)
                <x-portal.help-topic :topic="$topic" />
            @endforeach
        </div>
    @else
        <x-portal.empty-state title="Nenhuma orientação fictícia" message="Este catálogo demonstrativo ainda não possui tópicos. Nenhum atendimento está disponível por esta tela." />
    @endif
</section>
