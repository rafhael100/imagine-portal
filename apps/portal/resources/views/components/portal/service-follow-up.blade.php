@props(['service'])
<article class="pi-panel">
    <x-portal.service-status :state="$service->state->value" />
    <h3 class="pi-stage-title">{{ $service->product }}</h3>
    <dl class="pi-record">
        <div><dt>Referência fictícia</dt><dd>{{ $service->reference }}</dd></div>
        <div><dt>Resumo ilustrativo</dt><dd>{{ $service->summary }}</dd></div>
        <div><dt>Próxima atualização ilustrativa</dt><dd>{{ $service->nextUpdate }}</dd></div>
    </dl>
    <p class="pi-muted">Sem contratação, elegibilidade, prazo ou SLA real.</p>
</article>
