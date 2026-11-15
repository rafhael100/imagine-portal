@props(['updates' => []])
<section class="pi-update-history" aria-label="Histórico fictício de atualizações">
    <h4>Histórico fictício de atualizações</h4>
    <p class="pi-muted">Ordem narrativa do exemplo · sem eventos reais ou registro de auditoria</p>
    @if(count($updates) > 0)
        <ol class="pi-updates">
            @foreach($updates as $update)
                <li>
                    <p class="pi-muted">{{ $update->label }}</p>
                    <strong>{{ $update->title }}</strong>
                    <p>{{ $update->summary }}</p>
                </li>
            @endforeach
        </ol>
    @else
        <x-portal.empty-state title="Nenhuma atualização fictícia" message="Este exemplo ainda não possui histórico ilustrativo." />
    @endif
</section>
