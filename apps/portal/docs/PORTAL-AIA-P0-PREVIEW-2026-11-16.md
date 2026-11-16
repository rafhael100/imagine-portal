# Portal Imagine — prévia segura do Imagine Agentes de IA (P0)

**16/11/2026 · Apenas UX ilustrativa · NO-GO / NO-DEPLOY / NO-MERGE**

## Entrega

Na árvore Laravel do Portal `apps/portal/`, os catálogos **Módulos** e **Disponíveis** passam a apresentar o serviço **Imagine Agentes de IA** como indisponível/não contratável, com descrição da metodologia **Visitas → Leads → Clientes**. A nova view `portal.demo.agents` utiliza os componentes Blade e o design system original do Portal e apresenta um storyboard de uso com funil numérico fictício (1.000 visitas, 50 leads e 5 clientes), simulações e central de aprovação não operacional.

A view não é roteada; não há links externos, formulários, scripts, acesso a banco, sessão, autenticação SSO ou integração com CRM. É um componente interno para revisão de UX; a demonstração **interativa** autônoma permanece na [PR Imagine AI Agents #335](https://github.com/rafhael100/imagine-ai-agents/pull/335), arquivo `examples/p0-assistente-comercial-demo.html`. Não substituir uma por outra nem incorporar o HTML em uma rota de cliente sem revisão de escopo.

## Testes

`PortalAgentsPreviewTest` verifica renderização Blade, três pilares, estados de indisponibilidade, ausência de URL/JavaScript/formulários, nenhuma consulta ao banco, nenhuma nova rota e nenhuma sessão aberta. O workflow existente `laravel-f1-01-a.yml` roda testes PHP 8.3/8.4 na HEAD exata desta PR. Os testes anteriores do Portal permanecem obrigatórios.

## Próxima integração

Antes da futura navegação ativa para Agentes será necessário: autorização de serviço elegível no Portal, identidade de usuário segura, autenticação **independente** do CRM, estado de assinatura/revogação, destino autenticado e verificação de CORE/SEG/IAM/LGPD. A integração CRM OP-01 segue sob [PR #57](https://github.com/rafhael100/imagine-crm/pull/57), não homologada (7 decisões, 14 gates, 13 cenários reais). Segurança de rede, despesas e VPS permanecem sem alteração.

Nenhum merge em `main`, deploy, checkout, pagamento, SSO ou leitura operacional foi realizado.
