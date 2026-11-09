# SITES-MIG-02 — Registro de execução inicial da separação (F1-01)

**Data de referência:** 08/11/2026 (America/Sao_Paulo).  
**Status:** migração progressiva INICIADA, ainda não operacional.  
**Decisão vigente:** Imagine Sites tem motor independente, mas segue produzido/gerenciado pela Imagine e integrado à experiência comercial e ao aceite no Portal.

## Repositório independente criado pelo responsável

- [rafhael100/imagine-sites](https://github.com/rafhael100/imagine-sites): repositório real, branch `main` inicializada somente com README não operacional.
- Branch técnica isolada: `feat/sites-f1-01-engine-domain-contracts-2026-11-08`, HEAD `101e416b081a9f0a1d825a635df934527bc21ddd`.
- [Imagine Sites PR #1](https://github.com/rafhael100/imagine-sites/pull/1): **draft, sem merge**.
- Base documental neste Portal: [PR #94 — SITES-ARQ-01](https://github.com/rafhael100/imagine-portal/pull/94), também draft.

## Implementação de laboratório F1-01

- Criado um núcleo **PHP 8.3+ puro** com objetos de manifesto e evidência fictícia, políticas lógicas de elegibilidade pré-publicação/entrega, cálculo de janela de lead `T0+72h` e `T0+432h`, e ledger de idempotência apenas em memória.
- Adicionados `README.md`, `AGENTS.md`, `STATUS-ATUAL.md`, ADR `SITES-ARQ-02`, contrato candidato `SITES-CT-01`, matriz `SITES-SEC-01` e inventário `SITES-MIG-01`.
- Testes: 32 verificações sintéticas passaram no laboratório local PHP 8.4.24; workflow GitHub PHP 8.3 e 8.4 **sem deploy**. Consultar resultados do CI do PR #1 antes de marcar execução remota como completa.
- Nenhum endpoint, Laravel app, banco real, Cloudflare, domínio, CRM, captação, n8n, Agents, armazenamento privado ou serviço de publicação foi ativado.
- Este núcleo **não é prova de autorização real**: os recibos são dados fictícios; idempotência não é durável; publicação e entrega não ocorrem.

## Interfaces e ownership preservados

Portal controla cliente, contratos, financeiro, briefing e aceite expresso da release, além do único evento oficial ENTREGUE. Sites controla projeto técnico, manifesto, release, pipeline, publicação comprovada, monitoramento e incidentes. CRM confirma gravação de leads. APIs captadoras principal e contingência têm exigência de persistência independente, com T0 durável, prazo total 432h e verificação de destino autorizado em ambas as rotas. Agents/n8n apenas coordenam operações tipadas sob alçadas.

## Controle de mudanças e gates

- O PR #94 **não será fundido** nesta etapa. A main de Portal, CRM, Performance e Agents permanece inalterada por essa separação.
- P0-DOC01..14, P0-DOC03, RF-PEND-01, RF-PEND-04, contratos RV-CT01..08, autenticação multiempresa e homologação permanecem ABERTOS nos seus respectivos estados.
- AUT-P01..10 alternativa B, RV-CT01/02 B são diretrizes normativas, não contratos homologados.
- Rollback automático AGI-11-RB01 DESABILITADO.
- Sem merge/deploy automático, custos, VPS, DNS, dados reais, segredos ou publicação de clientes.

## Próximo passo autorizado sem risco operacional

F1-02: preparar contrato OpenAPI/AsyncAPI candidato e adaptadores de autoridade **fail-closed**, somente com mocks e testes adversariais. Somente após revisão e gates poderão ser propostas rotas HTTP reais, Laravel/PostgreSQL próprios, publisher Cloudflare e captação integrada.

**Não tratar este registro como fechamento de gate, homologação ou entrega operacional.**
