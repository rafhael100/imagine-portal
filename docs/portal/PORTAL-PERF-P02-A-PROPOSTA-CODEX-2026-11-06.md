# PORTAL-PERF-P02-A — Próximo recorte para Codex: domínio de catálogo Performance AI

**Data:** 06/11/2026  
**Status:** **PROPOSTA PRONTA PARA APROVAÇÃO — PROGRAMAÇÃO AINDA NÃO AUTORIZADA**. A autorização do merge PR #14 não aprova automaticamente esta nova etapa.

**Objetivo:** implementar no **Portal Imagine** uma estrutura de catálogo de ofertas do Performance AI com informações não financeiras fictícias e condições de habilitação explícitas, preparando a jornada futura de contratação **dentro do Portal**, sem oferta real, cobrança ou conexão com backend de anúncios.

**Contexto normativo:**
- Stack Portal: Laravel + PostgreSQL previamente aprovada; MVP isolado em `apps/portal/`. O código legado Node/SQLite é protótipo, com dados apenas de teste.
- PR #14 de domínio organização/membership integrado somente na branch de fast-track, com segurança de produção ainda pendente.
- Diretriz Portal-first: [PORTAL-PERF-D01](PORTAL-PERF-D01-MVP-NATIVO-2026-11-05.md).
- Integração independente futura: [INTEG-01 v0.1 do Performance](https://github.com/rafhael100/imagine-performance-ai/blob/dev/fast-track-performance-2026-11-05/docs/execucao/INTEG-01-CONTRATO-MOCK-PORTAL-PERFORMANCE-v0.1.md).
- Catálogo comercial Performance funcionalmente aprovado com Canal Único, Multicanal e Operação Ampliada, mas sem preços e franquias finais.

## Escopo candidato estritamente limitado

Implementar **somente classes/objetos de domínio e testes PHPUnit com fixtures fictícias**, preferencialmente sob `apps/portal/app/Domain/Catalog` e `apps/portal/tests/Unit`, para:

1. `ProductCode` e `OfferingId` tipados/validados, com identificador único da oferta e produto Performance.
2. `PerformanceChannel`: opções `google_ads` e `meta_ads`. Modelos de modalidade `single_channel`, `multichannel`, `expanded_operation`; Canal Único seleciona exatamente um canal permitido, Multicanal ambos, Operação Ampliada com capacidade configurável e condicionada.
3. `PerformanceOffering`: versão, descrição, status `draft`/`not_for_sale`/`ready_for_review`, recursos ilustrativos e limites `null` quando não homologados. **NÃO ter estado comercial `for_sale` ou ativo nessa fase**.
4. `OfferingEligibility` ou serviço puro que **nega habilitação de checkout** quando preços/limites/contratos não forem homologados e quando contrato ou oferta estiver em estado não elegível. Não simular pagamento real nem produzir ordem de serviço.
5. Associação de organização/tenant por **contexto de autorização tipado**, apenas para futuras integrações, sem inserir ID da organização diretamente em URLs ou confiar no navegador. Se a estrutura não exigir contexto na Fase A, documentar a escolha e deixar consultas reais bloqueadas.
6. Testes para seleção correta de canais, impossibilidade de canal extra, falha segura em limites `null`, ausência de habilitação de venda, imutabilidade da versão, enumerações inválidas, bloqueio de acesso cruzado por organização quando relevante.

### Arquivos e processos autorizáveis se aprovado

- Trabalhar em nova branch filha **derivada da HEAD atual** de `dev/fast-track-portal-2026-11-05`, sugerida `dev/portal-perf-p02-a-catalogo-2026-11-06`.
- **Não alterar** a `main`, a fundação do PR #11, o código legado ou os demais repositórios.
- **Apenas `apps/portal/**`**; não criar migrations, Eloquent, controladores, endpoints, rotas, autenticação, UI pública, checkout real, contratos reais, assinaturas ou dados de clientes.
- Não adicionar dependências, invocar n8n, Google Ads, Meta Ads, Asaas, PSP, banco de dados ou VPS.
- Rodar `composer validate`, `php artisan test` e lint/CI disponíveis; registrar a saída exata e limitações. Não declarar testes se não rodaram.
- Abrir PR **draft** para `dev/fast-track-portal-2026-11-05`, sem merge. O CI em PHP 8.3/8.4 é critério mínimo.

## Critérios de aceite

- Nenhuma oferta marcada como disponível para contratar; preço e capacidade quantitativa indefinidos são `N/D`/nulos, nunca zero, ilimitados ou preço inventado.
- A estrutura não mistura os preços de CRM ou Sites com Performance, preservando autoridade do Portal sobre o contrato comercial futuro e autonomia do motor Performance.
- Toda a funcionalidade implementada nesta etapa é **código puro e testes de demonstração**, que podem alimentar posteriormente P02-B (proposta/aceite simulado), P02-C (adapters mockados) e P02-D (área do cliente), sujeitos a novas aprovações.
- Relatório de PR com comparação de head, base, testes e ausência de alterações na `main`/VPS.
- D02-SEC, P0-DOC, contratos de API, segurança real, pagamentos e autorização de marketing continuam abertos.

## Texto recomendado para Codex após aprovação expressa

> Execute exclusivamente PORTAL-PERF-P02-A no repositório `rafhael100/imagine-portal`. Leia `docs/portal/PORTAL-PERF-P02-A-PROPOSTA-CODEX-2026-11-06.md` e `docs/portal/PORTAL-PERF-P02-HANDOFF-CODEX-2026-11-05.md` na branch documental `docs/portal-f1-00-2026-11-05`, mais `apps/portal/AGENTS.md` da branch `dev/fast-track-portal-2026-11-05`. Verifique HEAD atual da branch base e PR #14 já integrado. Crie branch filha e implemente somente objetos de domínio e testes fictícios do catálogo Performance com ofertas `not_for_sale`, sem preço, checkout, integração, banco ou login real. Execute a suíte e abra PR draft destinado exclusivamente à branch de fast-track. Não faça merge, não altere main/VPS ou outros repositórios. Se faltar aprovação de programação desta tarefa, interrompa a implementação e reporte.
