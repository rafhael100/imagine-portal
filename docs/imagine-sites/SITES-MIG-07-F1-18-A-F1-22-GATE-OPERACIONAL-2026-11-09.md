# SITES-MIG-07 — Encerramento da rodada técnica F1-18 a F1-22 e Gate Operacional

**Data de referência:** 09/11/2026, fuso America/Sao_Paulo.  
**Decisão aprovada:** Sites motor independente; produção/gestão pela Imagine, Portal para contratação/briefing/aprovação/atendimento; CRM e AI Agents mantêm domínios próprios.

> **IMPORTANTE:** este registro NÃO declara a migração integralmente concluída, não homologa produção e não autoriza merge/deploy. Os PRs são trabalhos isolados em branches e dependem de revisão.

## Evidências verificadas no GitHub

| Trilha | Entrega técnica | Evidência |
| --- | --- | --- |
| [Sites PR #19, F1-18](https://github.com/rafhael100/imagine-sites/pull/19) | Documentos OpenAPI 3.1 / AsyncAPI 3.0 candidatos, sem hosts/brokers ativos; testes estáticos de campos, segurança e erros | SHA `19f31d5e90bb827f442f79d0f872cb114813d99a`, CI PASS |
| [Sites PR #20, F1-19](https://github.com/rafhael100/imagine-sites/pull/20) | Laravel grava comando e outbox na mesma transação PostgreSQL efêmera com RLS e testes negativos | SHA `584f19928f4d499a3b2e1e0fd903c22e6f551ba8`, CI PHP 8.3/8.4 PASS |
| [Sites PR #21, F1-20](https://github.com/rafhael100/imagine-sites/pull/21) | Inventário de fluxos antigos `briefing.mjs`/`production.mjs` do Portal e guards PHP para briefing/revisão, titular, versão otimista, confirmação e aceite de digest exato | SHA `995da3f5070cb04d3ff4853a7a63a2c3cd5b592e`, CI PHP 8.3/8.4 PASS após correção de índices no teste |
| [Sites PR #22, F1-21](https://github.com/rafhael100/imagine-sites/pull/22) | `config/operational-readiness.json` com dez gates BLOCKED e scanner conservador de CI sem deploy/segredos/permissões de escrita | SHA `00f5cafb8256cb4e236d90537c08217cd069ba2f`, CI PASS |
| [Sites PR #23, F1-22](https://github.com/rafhael100/imagine-sites/pull/23) | PostgreSQL efêmero simula restauração de backup antigo sem reabilitar grants revogadas graças a revocation floor fora do conjunto de dados restaurado | SHA `7456ed0211b8573021d1ec2d5cac0efab4b75a46`, CI SQL/PHP/Laravel/guard PASS |

**Histórico adicional:** Sites PR #1–18 encadeados e draft; Portal PR [#101](https://github.com/rafhael100/imagine-portal/pull/101) (contrato consumidor mockado) e [#105](https://github.com/rafhael100/imagine-portal/pull/105) (assinatura HMAC de teste) em draft. A presente arquitetura/documentação reside em Portal PR [#94](https://github.com/rafhael100/imagine-portal/pull/94).

**Nenhum PR acima deve ser interpretado como implantação**, mesmo com CI verde. Testes de PostgreSQL foram executados exclusivamente em serviços temporários de runners GitHub, não na VPS ou banco de clientes. A restauração F1-22 usa ledger separado por schema, mas **não fisicamente independente**, de modo que ainda não prova recuperação operacional.

## Lacunas que não podem ser inferidas de testes sintéticos

1. Fonte oficial de contrato/cliente, autenticação forte S2S, revogação em produção e credenciais seguras; fluxo real entre Laravel Portal e Laravel Sites.
2. Migrations Laravel Sites estáveis, PostgreSQL próprio em ambiente de homologação com role sem privilégios, RLS, outbox real, backups restauráveis, isolamento real de ledger de revogação, métricas/auditoria.
3. Motor de geração por IA com controles de ferramenta, copyright/licenças, privacidade, custos, aprovação humana e identidade da Imagine.
4. Render de páginas completo para ofertas Onepage/Landing, gestão de assets e domínios, preview seguro de cliente, aprovação expressa da release e anexos/briefing protegidos.
5. Cloudflare Workers Static Assets/GitHub, SSL e DNS pelo proprietário do domínio, publicação observada independentemente (não só ACK de provedor), disponibilidade/rollback manual documentado.
6. Formulários de leads realmente funcionais, API primária e secundária **fisicamente independentes**, confirmação CRM, idempotência end-to-end, T0+72h/432h, descarte e incidentes.
7. Financeiro do Portal / benefício CRM Básico / calendário comercial e atendimento: sem alterações automáticas de cobrança; homologar todos os casos antes de migrar o atendimento do protótipo.
8. Análise de vulnerabilidades, dependências, custos/cotas e runbooks; decisão operacional e plano de contingência.

## Decisões restritas ao responsável em Gate de Produção (não presumir da autorização geral de programar)

- **Infraestrutura:** aprovar acesso controlado à VPS Leaseweb de desenvolvimento/homologação e qualquer gasto/fornecedor novo; registrar de forma segura credenciais no secret manager apropriado, sem expô-las em chats ou arquivos públicos.
- **Cloudflare e domínio:** autorizar credenciais de conta e domínio técnico de ensaio isolado, configurar permissões mínimas, quotas/monitoramento e decisão de teste de publicação. Nenhum domínio de cliente será alterado sem seu consentimento.
- **Homologação técnica de segurança e LGPD:** apresentar matriz de provas P0-DOC01..14, RF-PEND-01/04, RV-CT e análise AppSec antes de habilitar conexão a dados reais, lead capture e entrega.
- **Merge/deploy:** consentimento específico de change control para os PRs em branches protegidas e para o primeiro deploy após CI, backup e observação independente. A autorização de desenvolvimento contínuo não remove esse gate.

## Política até o Gate

Executar somente melhorias que não exigem dados/clientes/provedores reais, preferindo PRs em draft com CI verificável; priorizar integração operacional futura por etapas com cada dependência identificada. Não colocar chaves ou dados pessoais nos repos. **AGI-11-RB01 rollback automático permanece DESABILITADO**. Não alegar produto concluído, mesmo que toda a suíte sintética passe.
