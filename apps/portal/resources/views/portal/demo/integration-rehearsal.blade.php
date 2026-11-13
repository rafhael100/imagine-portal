<x-portal.layout title="Homologação funcional — prévia integrada PG18" current="preparation">
    <x-portal.message title="LABORATÓRIO DESCARTÁVEL — NÃO É ÁREA DO CLIENTE"
        message="Esta prévia contém somente organizações, serviços, checklists e solicitações sintéticos. Foi gerada na CI com PostgreSQL 18. Não existe login, publicação, cobrança, sessão ou atendimento." tone="warning" />
    <x-portal.panel title="Projetos e preparação — dados de teste persistidos">
        <p>Os registros abaixo foram consultados diretamente do PostgreSQL 18 descartável por leitores exclusivos de teste, com empresa e projeto verificados em uma transação somente de leitura. Itens revisados são eventos fictícios, não aprovação de entrega.</p>
        <x-portal.table caption="Checklists do PostgreSQL 18 — demonstração não operacional"
            :columns="['Produto', 'Referência do projeto', 'Situação fictícia', 'Itens revisados', 'Itens ainda pendentes']"
            :rows="$preparationRows" />
    </x-portal.panel>
    <x-portal.panel title="Solicitações e histórico — dados de teste persistidos">
        <p>Cada solicitação representa um fluxo fictício com revisões registradas em transação e verificadas contra a trilha de auditoria antes da apresentação. Não há botões de abertura, edição, envio, reenvio ou suporte.</p>
        <x-portal.table caption="Solicitações do PostgreSQL 18 — histórico conciliado"
            :columns="['Solicitação', 'Projeto', 'Estado fictício', 'Revisão registrada']"
            :rows="$requestRows" />
    </x-portal.panel>
    <div class="pi-grid pi-grid-two">
        <x-portal.panel title="Identidade Imagine preservada">
            <p>Interface baseada no Portal Imagine aprovado, com logomarca oficial, navegação leve, cores laranja e grafite e visual responsivo. A imagem desta prévia é incorporada ao HTML para abertura offline, sem carregamento externo.</p>
        </x-portal.panel>
        <x-portal.panel title="Gates de segurança ainda fechados">
            <p>Não há permissões SQL para o usuário público, nem acesso real a CRM, Sites, Performance AI ou Universidade. A liberação exige MFA, contratos, isolamento autorizado, witness independente de revogação e revisão AppSec/DBA.</p>
        </x-portal.panel>
    </div>
    <x-portal.unavailable feature="Acesso, edição, revisão real, publicação, anúncios, contratos ou pagamentos" />
</x-portal.layout>
