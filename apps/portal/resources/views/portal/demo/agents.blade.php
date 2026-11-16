<x-portal.layout title="Imagine Agentes de IA · prévia" current="modules">
    <x-portal.panel title="Assistente Comercial — demonstração ilustrativa">
        <p class="pi-muted">Metodologia Imagine: <strong>Visitas → Leads → Clientes</strong>. Esta é apenas uma prévia visual da funcionalidade já desenvolvida no repositório Imagine AI Agents. Nenhum dado ou mensagem é processado aqui.</p>
        <div class="pi-grid pi-grid-three">
            <x-portal.indicator label="Visitas ilustrativas" value="1.000" detail="Hipótese semanal" />
            <x-portal.indicator label="Leads ilustrativos" value="50" detail="Conversão hipotética de 5%" />
            <x-portal.indicator label="Clientes ilustrativos" value="5" detail="Fechamento hipotético de 10%" />
        </div>
    </x-portal.panel>
    <x-portal.panel title="Funcionalidades preparadas para homologação">
        <x-portal.list label="Recursos de prévia" :items="[
            'Painel do Assistente Comercial com leads de laboratório',
            'Simulações determinísticas, sem provedor de IA',
            'Central de aprovações exclusivamente em memória',
            'Integração CRM e n8n desabilitada até validação'
        ]" />
    </x-portal.panel>
    <x-portal.message title="Integração indisponível" message="O Portal não compartilha sessões com o CRM. Identidade de serviço, autorização por empresa/lead/campo, contratos e 13 cenários OP-01 ainda aguardam homologação. Nenhuma rota, link externo, login ou leitura de CRM foi habilitada." tone="warning" />
    <x-portal.unavailable feature="A execução de Agentes de IA" />
</x-portal.layout>