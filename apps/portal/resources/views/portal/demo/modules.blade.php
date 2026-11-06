<x-portal.layout title="Módulos Imagine" current="modules">
    <div class="pi-grid pi-grid-two">
        <x-portal.service-card name="Performance AI" description="Acesso ilustrativo ao painel de resultados simulados. Google Ads e Meta não conectados." status="Acesso indisponível" tone="warning" />
        <x-portal.service-card name="Sites" description="Acesso ilustrativo à organização dos seus projetos digitais." status="Acesso indisponível" tone="warning" />
        <x-portal.service-card name="Imagine CRM" description="Serviço independente. Login e sessão próprios, sem compartilhamento de credenciais ou sessão com o Portal." status="Acesso independente" tone="info" />
        <x-portal.service-card name="Universidade Imagine" description="Acesso ilustrativo às trilhas de aprendizagem. Integração de identidade não implementada." status="Acesso indisponível" tone="warning" />
    </div>
    <x-portal.panel title="Exemplo visual de carregamento">
        <p class="pi-muted">Estado estático para revisão do componente; nenhuma requisição está em andamento.</p>
        <x-portal.loading label="Carregando conteúdos · exemplo estático" />
    </x-portal.panel>
    <x-portal.unavailable feature="O acesso aos módulos" />
</x-portal.layout>
