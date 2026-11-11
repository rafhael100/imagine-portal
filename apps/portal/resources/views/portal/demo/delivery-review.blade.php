<x-portal.layout title="Revisão de entregas — exemplo interno" current="requests">
    <x-portal.message
        title="SIMULAÇÃO LOCAL — não é o CRM Imagine"
        message="Todos os casos, serviços, referências e contagens abaixo são fictícios. Esta prévia não consulta clientes, não usa login, não executa revisão e não permite reenvio."
        tone="warning"
    />
    <x-portal.panel title="Entregas que exigiriam conferência">
        <p>Antes de qualquer decisão real, o operador precisaria verificar a sessão Portal, a empresa, o vínculo do formulário e a prova autenticada do CRM. Um envio sem resposta não significa que houve falha nem que chegou ao destino.</p>
        <x-portal.table
            caption="Exemplos fictícios de revisão — sem dados pessoais ou envios reais"
            :columns="['Referência demonstrativa', 'Produto fictício', 'Situação ilustrativa', 'Reservas simuladas', 'Próximo passo sugerido']"
            :rows="\App\Presentation\DemoCommercialDeliveryReview::rows()"
        />
    </x-portal.panel>
    <x-portal.panel title="Resumo do exemplo por motivo de revisão">
        <p>Contagem calculada somente a partir das três amostras fictícias, após validação do modelo de metadados. Não representa a quantidade de envios, leads ou solicitações reais.</p>
        <x-portal.table
            caption="Contagem demonstrativa — somente registros fictícios"
            :columns="['Motivo ilustrativo', 'Exemplos']"
            :rows="\App\Presentation\DemoCommercialDeliveryReview::reasonSummaryRows()"
        />
    </x-portal.panel>
    <x-portal.panel title="Regras de decisão — bloqueadas nesta prévia">
        <ul>
            <li>Resultado desconhecido: não confirmar entrega nem repetir automaticamente.</li>
            <li>Envio possivelmente iniciado: somente conciliação autenticada pode esclarecer a situação.</li>
            <li>Concessão de revisão: precisa de permissão específica, sessão Portal válida e isolamento por empresa.</li>
            <li>Auditoria: uma tentativa registrada na mesma transação pode ser apagada por rollback; não é prova independente.</li>
        </ul>
    </x-portal.panel>
    <x-portal.message
        title="Sem ações operacionais"
        message="Não existem botões de reenvio, aprovação, liberação, acesso ao CRM, exportação de dados ou confirmação a visitantes. A implementação depende da revisão AppSec/DBA e da homologação."
    />
    <x-portal.unavailable feature="Revisão autenticada, reconciliação com CRM verdadeiro e reenvio manual" />
</x-portal.layout>
