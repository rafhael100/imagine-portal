<x-portal.layout current="overview">
    <div class="pi-grid">
        <x-portal.indicator label="Serviços no exemplo" :value="count(\App\Presentation\DemoServiceRequests::services())" />
        <x-portal.indicator label="Solicitações de exemplo" :value="count(\App\Presentation\DemoServiceRequests::requests())" />
        <x-portal.indicator label="Contratos ilustrativos" value="1" detail="Sem validade jurídica" />
    </div>
    @php($overviewNextSteps = \App\Presentation\DemoPreparationCards::cards())
    <x-portal.panel title="Próximos passos dos serviços — exemplo">
        <p>Veja em um só lugar as solicitações e o que precisará ser conferido para cada produto. Estas informações são inteiramente fictícias: nenhuma tarefa foi enviada, concluída ou autorizada.</p>
        @if (count($overviewNextSteps) === 3)
            <ul class="pi-list" aria-label="Próximos passos ilustrativos por serviço">
                @foreach ($overviewNextSteps as $card)
                    <li>
                        <strong>{{ $card['product'] }}</strong>
                        <p class="pi-muted">Solicitação {{ $card['request'] }} · {{ $card['request_state'] }}</p>
                        <p>{{ $card['next'] }}</p>
                    </li>
                @endforeach
            </ul>
        @else
            <x-portal.empty-state title="Resumo de preparação indisponível"
                message="As referências fictícias de serviços e solicitações não são consistentes. Nenhuma etapa parcial será exibida." />
        @endif
    </x-portal.panel>
    <x-portal.panel title="Bem-vinda, Empresa Aurora">
        <p>Este resumo mostra como os serviços Imagine poderão ser organizados no Portal.</p>
        <x-portal.list label="Resumo dos serviços fictícios" :items="array_map(fn ($service) => $service->product . ' · exemplo fictício', \App\Presentation\DemoServiceRequests::services())" />
    </x-portal.panel>
    <x-portal.panel title="Atividades recentes"><x-portal.empty-state title="Nenhuma atividade demonstrativa" message="As atualizações dos seus serviços poderão aparecer aqui." /></x-portal.panel>
</x-portal.layout>
