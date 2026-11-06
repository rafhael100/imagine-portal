@props(['caption', 'columns' => [], 'rows' => []])
<p class="pi-muted pi-table-hint">Em telas menores, role a tabela horizontalmente para ler todas as colunas.</p>
<div class="pi-table-wrap" role="region" aria-label="{{ $caption }}" tabindex="0">
    <table>
        <caption>{{ $caption }}</caption>
        <thead><tr>@foreach ($columns as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>@foreach ($columns as $index => $column)<td>{{ $row[$index] ?? '—' }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ max(1, count($columns)) }}"><x-portal.empty-state title="Nenhum registro demonstrativo" /></td></tr>
            @endforelse
        </tbody>
    </table>
</div>
