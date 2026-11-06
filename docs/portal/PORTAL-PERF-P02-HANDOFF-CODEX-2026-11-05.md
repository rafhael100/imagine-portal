# PORTAL-PERF P-02 — Handoff coordenado do checkout simulado e área Performance

**Data:** 05/11/2026  
**Status:** **PLANEJAMENTO TÉCNICO DOCUMENTAL PARA PR FUTURO**; não autoriza programação P-02 por si só.  
**Prioridade definida pelo responsável:** jornada completa de contratação e acompanhamento do Imagine Performance AI **dentro do Portal**, sem prestação comercial manual externa.  
**Diretriz principal:** [PORTAL-PERF-D01](PORTAL-PERF-D01-MVP-NATIVO-2026-11-05.md) e [PERF-PROD-D01](https://github.com/rafhael100/imagine-performance-ai/blob/main/docs/performance-ai/PERF-PROD-D01-PORTAL-FIRST-MVP-2026-11-05.md).

## 1. O que já está aprovado e deve ser usado

- **Stack de destino Portal:** Laravel + PostgreSQL — `PORTAL-TEC-D01`; implementação de fundação isolada em `apps/portal/` da branch `dev/fast-track-portal-2026-11-05`.
- **Portal × CRM:** logins e sessões humanos separados — `PORTAL-TEC-D02`; CRM opcional para Performance. A integração humana Portal × Performance/Universidade permanece discussão futura, sem SSO aprovado.
- **Legado Node/SQLite:** protótipo, com dados de teste; usar **somente fluxo/regras úteis**, sem migrar seus dados como base de produção.
- **Portal #12/F1-01-B-A:** classes PHP puras e testes de organizações/membros/permissões já autorizados para Codex, **ainda sem garantia de conclusão**. A base de autorização deve preceder a experiência do cliente.
- **Portal #13:** backlog para catálogo e contratação simulada do Performance, ainda precisa da liberação específica do seu recorte de código.
- **Performance #1/#2/#3:** proposta de stack, segurança e testes em andamento de preparação, não implementação concluída; Performance #5 contrato de integração mockado candidato.

## 2. Jornada da primeira versão, sem saltar gates

`Catálogo Performance` → `Canal Único Google/Meta ou Multicanal` → `Organização/usuário Portal` → `Selecionar oferta` → `Exibir valores somente se aprovados` → `Proposta e aceite expresso` → `Checkout e assinatura` → `Onboarding` → `Área de campanhas/relatórios` → `Suporte e cancelamento`.

**MVP fictício de desenvolvimento:** valores `N/D` e marca `not_for_sale`; pedido e aceite simulados; gateway de testes/mock sem faturamento; onboarding demonstrativo; métricas sintéticas identificadas. **Cliente real:** somente depois de preço/contrato/PSP/segurança/integração/estrutura fiscal homologados e autorização de go-live.

## 3. Fatiamento sugerido de PRs pequenos (NÃO AUTORIZADOS nesta decisão documental)

| Slice candidato | Responsabilidade no Portal | Dependência |
| --- | --- | --- |
| **P02-A** | Contrato de domínio do catálogo `PerformanceOffering`, variantes de canal, capacidades `nullable` e `not_for_sale`; testes de preço não aprovado | F1-01-B-A e aprovação de implementação P02-A |
| **P02-B** | Simulador determinístico de proposta, snapshot e aceite fictício; sem pagamento real | P02-A; regras comerciais Performance aprovadas |
| **P02-C** | Área autenticada de onboarding simulada e contrato de adapter Performance via mock; sem integração real | P02-A/B e interfaces de Performance #5 |
| **P02-D** | Painel demonstrativo e solicitações/aprovações humanas; sem dado real Google/Meta | P02-C e autorização específica |
| **P02-E** | Integração real financeira e de conta/anúncios, suporte/SLAs, auditoria | Apenas com todos os gates de produção aplicáveis |

## 4. Contrato Portal ⇄ Performance (candidato)

**Fonte técnica comum:** [INTEG-01 contrato mockado v0.1 no repositório Performance](https://github.com/rafhael100/imagine-performance-ai/blob/dev/fast-track-performance-2026-11-05/docs/execucao/INTEG-01-CONTRATO-MOCK-PORTAL-PERFORMANCE-v0.1.md).

- **Portal é autoridade** de usuário, empresa, produto, preço, pedido, aceite, pagamento, assinatura e fiscal; Performance não grava nem calcula pagamento como fonte primária.
- **Performance é autoridade** de operação Google/Meta, campanhas, grupos, análises e relatórios operacionais autorizados; Portal apenas apresenta estados agregados.
- `portal_organization_id` ⇄ `performance_tenant_id` somente com vínculo autenticado e auditável; não derive autorização do ID vindo do navegador.
- API/backend com autenticação serviço-a-serviço **a homologar** e escopo mínimo, sem login compartilhado com CRM e sem compartilhar banco ou sessão com Performance.
- Evento comercial candidato: `performance.service.eligibility_changed` com `event_id` idempotente, contrato e plano versionados, `order_id`, `service_id`, `correlation_id`. `pending_payment` não libera serviço.
- Atendimento e aprovações humanas dentro do Portal **não significam prestação manual fora da plataforma**; operações externas continuam sujeitas a autorização individual.

## 5. Testes exigidos no futuro P02-A/B/C — todos ainda não executados

1. Oferta sem tabela de preço homologada → `not_for_sale`, sem cobrança.
2. Organizações diferentes não enxergam pedidos, propostas, contas ou campanhas.
3. Aceite fictício não cria contrato real nem assinatura no PSP.
4. Pagamento falso ou pendente não provisiona tenant real.
5. Duas notificações iguais de serviço não criam duas assinaturas.
6. Cliente de canal único não recebe acesso indevido a outro canal.
7. Relatório fictício claramente marcado `demo`, sem alegar dado em tempo real.
8. Cancelamento do serviço Imagine não significa anúncios externos interrompidos.
9. Dados legados Node/SQLite não entram em fixtures como dados reais, nem contêm tokens.
10. `main`, VPS, OAuth, Asaas, Google, Meta e credenciais continuam intocados.

## 6. Tarefa de agora — Codex já autorizado

**Executar a Issue #12, F1-01-B-A, do Portal** na branch filha `dev/portal-f1-01-b-domain-2026-11-05`, seguindo [instrução oficial já aprovada](PORTAL-F1-01-B-A-TAREFA-CODEX-APROVADA-2026-11-05.md). Abrir PR draft com base `dev/fast-track-portal-2026-11-05`. **Não modificar o PR #11 e não fazer merge**. Não aproveitar o aceite deste documento para implementar automaticamente P02-A, B, C, D ou E.

**Em paralelo no Performance:** F1-01 (stack) + PAR-02 (segurança) + PAR-03 (testes), cada agente com sua issue/branch/PR draft. Os resultados deverão ser conciliados com o contrato INTEG-01 antes de homologar/implementar integração.

## 7. Decisão técnica seguinte ainda necessária

**PORTAL-PERF-P02-A:** aprovação expressa para implementar **somente domínio/contratos do catálogo Performance no Portal** em classes PHP e testes sintéticos, sem migrations, UI pública, oferta comercial habilitada, PSP, login real ou ligação a API Performance real. Submeter esse recorte depois que o PR da Issue #12 estiver disponível para revisão, evitando implementação duplicada de organizações e autorização.
