@props(['title' => 'Linha do tempo ilustrativa', 'steps' => []])
@php
    // Progress reflects only the supplied example, never actual service execution.
    $total = count($steps);
    $illustrated = count(array_filter($steps, fn ($step) => ($step['state'] ?? '') === \App\Presentation\JourneyState::Illustrated->value));
@endphp
<section class="pi-panel" aria-label="Linha do tempo demonstrativa">
    <h2>{{ $title }}</h2>
    <p class="pi-muted">{{ $illustrated }} de {{ $total }} etapas ilustradas · progresso fictício</p>
    @if($total > 0)
        <progress class="pi-journey-progress" aria-label="Progresso fictício das etapas ilustradas" value="{{ $illustrated }}" max="{{ $total }}">{{ $illustrated }} de {{ $total }}</progress>
        <ol class="pi-journey">
            @foreach($steps as $step)
                <li>
                    <x-portal.service-status :state="$step['state'] ?? 'example'" />
                    <h3>{{ $step['title'] }}</h3>
                    <p>{{ $step['description'] }}</p>
                </li>
            @endforeach
        </ol>
    @else
        <x-portal.empty-state title="Nenhuma etapa ilustrativa" message="O exemplo ainda não possui etapas." />
    @endif
</section>
