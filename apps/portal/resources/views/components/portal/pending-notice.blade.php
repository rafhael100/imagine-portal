@props(['title' => 'Pendências do exemplo', 'items' => [], 'state' => 'waiting'])
<aside class="pi-panel" aria-label="Pendências demonstrativas">
    <x-portal.service-status :state="$state" />
    <h2 class="pi-stage-title">{{ $title }}</h2>
    <x-portal.list label="Pendências fictícias" :items="$items" />
    <p class="pi-muted">Lista ilustrativa; não solicita envio de documentos ou dados.</p>
</aside>
