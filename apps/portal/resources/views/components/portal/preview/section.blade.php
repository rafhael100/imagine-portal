@props(['anchor', 'title', 'summary', 'tag' => 'Demonstração'])
@php($target = in_array($anchor, ['inicio', 'servicos', 'performance', 'solicitacoes', 'universidade', 'financeiro'], true) ? $anchor : 'inicio')
<section class="pv-section" id="pv-{{ $target }}" aria-labelledby="pv-heading-{{ $target }}" tabindex="-1">
    <span class="pv-chip">{{ $tag }}</span>
    <h2 id="pv-heading-{{ $target }}">{{ $title }}</h2>
    <p>{{ $summary }}</p>
    {{ $slot }}
</section>
