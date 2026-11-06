@props(['title' => 'Acompanhamento fictício dos serviços', 'services' => []])
<section aria-label="Acompanhamento demonstrativo de serviços">
    <h2>{{ $title }}</h2>
    @if(count($services) > 0)
        <div class="pi-grid pi-grid-two">
            @foreach($services as $service)
                <x-portal.service-follow-up :service="$service" />
            @endforeach
        </div>
    @else
        <x-portal.empty-state title="Nenhum serviço de exemplo" message="O cenário fictício ainda não possui serviços para ilustrar." />
    @endif
</section>
