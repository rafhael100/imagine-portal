<ul class="timeline">
@forelse($events as $event)
<li><strong>{{ $event['action'] }}</strong> · {{ $event['resource'] }} · Ator administrativo {{ $event['actor'] }} · Revisão {{ $event['revision'] }}<br><span class="muted">{{ $date($event['at']) }} · origem sintética · nenhum efeito externo</span></li>
@empty
<li>Nenhuma operação registrada neste contexto. Cenário sintético criado em {{ $date($state['updated_at']) }}.</li>
@endforelse
</ul>
