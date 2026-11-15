# Portal SEO F116 — prévia visual interna, Visitas → Leads → Clientes

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
Continuação da [F115 / PR #853](https://github.com/rafhael100/imagine-portal/pull/853).

## Entrega

A F116 adiciona uma prévia Blade moderna do Imagine SEO Engine no Portal,
respeitando o laranja de identidade visual #F9941D e a metodologia
**Visitas → Leads → Clientes**.

Arquivo: resources/views/seo/demo-onboarding.blade.php

A experiência apresenta:

- Status visual do Google Search Console, analytics e CRM: **Não conectado**
- Três pilares Visitas, Leads, Clientes, sempre com número **N/D** e a
  fonte que será necessária antes de apresentar dados reais
- Bloco de descoberta orgânica: cliques/impressões/CTR **N/D**;
  cliques GSC não são sessões nem leads ou vendas
- Etapas de ativação: login/MFA do Portal, OAuth readonly Google e
  fontes analytics/CRM com atribuição
- Identidade Imagine, layout responsivo, textos em português e
  marcações acessíveis; nenhuma ação de conexão ou contratação ativa

## Segurança do protótipo

Não existe rota HTTP para essa view e o bootstrap Laravel não a publica.
Nenhum controller, provider, secret, token, API, script de terceiro,
formulário, botão funcional ou URL de autorização foi criado.

A empresa fictícia é apenas texto de visualização; Blade escapa
HTML para evitar injeção. Os testes verificam escaping, presença dos
três estágios com N/D, ausência de ações e 404 para endpoints SEO
e OAuth não homologados. A view não acessa banco e nenhum indicador
pode ser convertido em zero como substituto de ausência de dados.

O template não é uma sessão de cliente e não comprova autorização real.
A futura UI produtiva precisará de gate autenticado no Portal, tenant
verificado, Google OAuth readonly próprio, analytics, CRM, revisão de
atribuição e LGPD, homologação, segurança e liberação formal.

## Validação

Testes PHPUnit/Laravel:
tests/Feature/SeoOnboardingDemoViewTest.php

Executar somente na branch de revisão, com Composer instalado:

    cd apps/portal
    php artisan test --filter=SeoOnboardingDemoViewTest

**Sem merge, deploy, VPS, Google real, CRM real, DNS, contas ou custos.**
