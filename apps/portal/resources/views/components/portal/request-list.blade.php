@props(['title' => 'Solicitações fictícias', 'requests' => []])
<section aria-label="Lista demonstrativa de solicitações">
    <h2>{{ $title }}</h2>
    @if(count($requests) > 0)
        <ul class="pi-request-list">
            @foreach($requests as $request)
                <li><x-portal.request-summary :request="$request" /></li>
            @endforeach
        </ul>
    @else
        <x-portal.empty-state title="Nenhuma solicitação de exemplo" message="Nenhum chamado real pode ser aberto nesta demonstração." />
    @endif
</section>
