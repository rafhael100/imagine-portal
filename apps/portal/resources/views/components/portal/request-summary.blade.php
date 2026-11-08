@props(['request'])
<article class="pi-panel">
    <x-portal.service-status :state="$request->state->value" />
    <h3 class="pi-stage-title">{{ $request->title }}</h3>
    <dl class="pi-record">
        <div><dt>Solicitação fictícia</dt><dd>{{ $request->reference }}</dd></div>
        <div><dt>Serviço do exemplo</dt><dd>{{ $request->serviceReference }}</dd></div>
        <div><dt>Resumo ilustrativo</dt><dd>{{ $request->summary }}</dd></div>
    </dl>
    <p class="pi-muted">Somente leitura · sem abertura, edição, envio, anexos ou aprovação.</p>
    <x-portal.update-history :updates="$request->updates" />
</article>
