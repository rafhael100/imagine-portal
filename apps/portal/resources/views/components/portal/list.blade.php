@props(['label', 'items' => []])
<ul class="pi-list" aria-label="{{ $label }}">
    @forelse ($items as $item)
        <li>{{ $item }}</li>
    @empty
        <li><x-portal.empty-state title="Nenhum item demonstrativo" /></li>
    @endforelse
</ul>
