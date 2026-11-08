# PORTAL-ARQ-R01 — Reequilíbrio da arquitetura e reconstrução controlada

**Data:** 08/11/2026. **Estado:** diretriz de planejamento aprovada quanto ao rumo; **não** é homologação técnica nem autorização de produção.  
**Decisão da Direção:** recomeçar partes se a revisão demonstrar necessidade, mas **não eliminar trabalho útil**; preservar conhecimento e interfaces, reconstruindo a arquitetura operacional de segurança desde a fundação. Prioridade: Portal Imagine funcional, navegável, seguro e preparado para os primeiros clientes sem paralisia por laboratórios intermináveis.

**Base para este lote documental:** `dev/fast-track-portal-2026-11-05` em `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8`. Nenhum merge automático para `dev`/`main`; nenhuma alteração de VPS/Cloudflare, serviços ou clientes.

## A. Inventário verificado e classificação

| Origem | Situação constatada | Decisão |
| --- | --- | --- |
| Raiz `server/`, `portal.html`, JS/SQLite/Node | Protótipo legado, testes e regras comerciais históricas; README raiz o chama versão 0.18.2 em homologação, sem implantação | **REFERÊNCIA** de aparência, fluxos e requisitos. Não utilizar o backend legado para produção; não migrar dados de teste nem importar autenticação. Não apagar o histórico. |
| `apps/portal/` | Fundação Laravel 13 + lockfile, domínio em memória e componentes de demonstração; `routes/web.php` sem rotas de cliente ou autenticação | **FUNDAÇÃO CANDIDATA** a preservar com revisão de dependências, composição e limites. Nenhuma rota real de negócio liberada. |
| `apps/portal/app/Domain/**` | Objetos puros de identidade, organizações, autorização e outros recortes sintéticos | **REUSO CONDICIONAL** a análise de invariantes e testes; NÃO pressupor autorização comprovada por DTO. |
| `apps/portal/app/Presentation/**`, `resources/views/**` | Componentes Blade e modelos ilustrativos de jornada/serviços/avisos; alguns recortes vindos de PRs ainda isolados | **REFERÊNCIA VISUAL** e possível reaproveitamento de componentes auditados; sem entitlements, status de cliente ou operação real. |
| PoCs de segurança, PR #51, #67 e documentos PR #69 | Simulações PG18/Z1/Z2 e anti-rollback; falhas conhecidas, evidências PASS restritas, contratos propostos | **EVIDÊNCIA**, nunca portar código do laboratório para o Portal operacional. |
| `docs/**` de negócio/infra | Regras comerciais e arquiteturais, algumas preliminares e divergentes do legado | **FONTES COM PRECEDÊNCIA**: decisão vigente aprovada pela Direção prevalece sobre implementação antiga. Divergência vira issue, não ajuste silencioso. |

**Fatos técnicos específicos:** `apps/portal/routes/web.php` contém somente comentário de que `/up` é health-check; `PortalAuthenticationRoutesClosedTest` exige HTTP 404 em `/login`, `/portal` e `/performance`. Não confundir UI compilada, view Blade e teste de domínio com um cliente autenticado navegando.

## B. Escolha de arquitetura-alvo (sem mudança de infraestrutura)

1. **Produto Portal:** Laravel 13/PHP compatível com lockfile, PostgreSQL **exclusivo do Portal**, API/servidor controlando identidade, empresa, assinatura, contratos, faturamento e serviços. Escolha de runtime já iniciada; versão e manutenção precisam ser verificadas na implantação.
2. **Acesso:** autenticação, sessões e MFA **do próprio Portal**, emitidas e verificadas por fonte oficial no servidor. Sem SSO implícito, sem sessão ou base de identidade compartilhada com CRM. A comunicação autorizada com CRM ocorre por contratos de integração isolados, não por confiar em IDs coincidentes.
3. **Tenancy:** todas as entidades persistidas têm titularidade/autorização verificáveis no servidor; chaves estrangeiras compostas/constraints e RLS PostgreSQL são defesa adicional **sem tomar GUC arbitrária como identidade**. Faltam esquema e testes operacionais; gates D02/D03/P0 permanecem.
4. **Serviços Imagine:** catálogo unificado e painel no Portal, com contratos/cobranças **por produto**; Sites, CRM, Performance AI e Universidade permanecem motores, dados, credenciais e ciclos de vida independentes, conforme regras comerciais próprias. Performance é contratável isoladamente; CRM sem login compartilhado; Universidade somente para clientes elegíveis ativos e não é oferta avulsa.
5. **Integrações:** APIs versionadas, permissões mínimas, idempotência, eventos e auditoria; suportar falha de terceiros sem expor banco nem misturar dados. n8n e agentes são camadas de orquestração futuras, nunca fonte de identidade/segredo nem bypass de regras.
6. **Infraestrutura:** conservar a diretriz de desenvolvimento central e ambientes segregados; nenhum deploy, bucket, banco, pagamento, Cloudflare ou custo criado nesta decisão.
7. **Princípio de produto:** primeiro comprovar navegação de demonstração e fluxos não sensíveis, depois autenticação/tenancy reais testadas, e apenas então efeitos financeiros e integrações — nunca remover segurança para acelerar.

## C. Segurança proporcional e recuperação

Não transformar toda PoC em dependência de lançamento sem explicitar risco, mas **não alegar proteção anti-rollback sem prova**.

**Caminho de estudo preferencial:** recuperação **fail-closed**, bloqueio de todos os writers e leitores sensíveis, clone isolado, invalidação global de sessões/refresh/MFA/recovery após desastre, reconciliação confiável de grants/permits, intents, jobs e outbox, e uma âncora de geração/revogação **independente do domínio restaurado**. Sem essa âncora ou provas equivalentes para regressão de direitos, manter serviço bloqueado. A alternativa híbrida do contrato SEC-R03 é **candidata de desenho**, não arquitetura homologada nem serviço adquirido.

- **Mínimo de segurança para preview público/offline:** zero dados reais, zero autenticação, zero chamadas de rede para backends, zero efeitos e indicação visual DEMONSTRAÇÃO. Nunca publicar páginas privadas do Laravel sem autenticação real.
- **Mínimo para área de cliente real:** D02 identidade/sessão/MFA, autorização de ator, organização ativa, titularidade e revogação, constraints e RLS testados, transações e race, cookies/CSRF/abuso, auditoria; aprovação de gates técnicos.
- **Mínimo para recuperação operacional:** acordo de risco, inventário de todos os writers, backup seguro e restauração exercitada, definição durável de fence externa, reautenticação e reconciliação verificáveis, prova de ausência de efeitos de outbox duplicados. Z1 comprometido com token A legítimo (T3) e Z2 comprometida continuam riscos a tratar.
- **Mínimo para faturamento/integrações:** PSP e cobranças somente após aprovações, sandbox apropriado, conciliação e idempotência; jamais usar dinheiro ou API real nos ensaios.

A política de invalidação **não** equivale a reset de grants; `epoch++` dentro do mesmo dump restaurável **não** resiste ao rollback. O arquivo fence do runner do PR #67 também não prova independência de host/região.

## D. O que será preservado e o que pode ser refeito

**Preservar:** histórico Git, docs/decisões vigentes, estudos de requisitos, conteúdo de telas, semântica comercial e evidências testadas. **Reconstruir com liberdade:** autenticação, modelos persistidos, APIs, integrações, controle de concorrência, recuperação e código legado que não satisfaça invariantes. **Nunca adotar automaticamente:** mocks, identity claims de Z1, mocks de PostgreSQL, jobs demonstrativos, tokens inventados ou UI de demo como solução de produção.

Se uma análise revelar que a fundação Laravel contraria invariantes, registrar prova e abrir recorte de substituição; somente então reimplementar com critérios e rollback documentados. Não deletar arquivos existentes apenas por preferência arquitetural.

## E. Roadmap de entregas pequenas

| Gate | Entrega verificável | Critério de aceite | Restrições |
| --- | --- | --- | --- |
| PVIEW-01 | Preview **navegável offline** de visão geral, serviços, catálogo e solicitações, com dados fictícios | Navegação de teclado, mobile e desktop, sem network/API e com aviso de demonstração; link/artefato real verificável | Não é rota de cliente; sem login |
| CORE-01 | Revisão/normalização do domínio e contratos de fonte confiável | Testes de regras multiempresa e semântica de negação; sem falsa autoridade | Não habilita rotas reais |
| AUTH-01 | Identidade oficial + ciclo de sessão/MFA + recuperação com requisitos definidos | Testes negativos, rate limiting, CSRF, fixation, revoked sessions, acessibilidade | Requer aprovação específica D02/P0 para código funcional |
| DB-01 | Modelos/migrations RLS, ownership, banco isolado e privilégios mínimos | Testes PG18, concorrência, multidomínio, isolamento A/B, roles | Requer autorização específica de SQL e ambiente |
| CLIENT-01 | Painel de cliente autenticado, catálogo/contratos e solicitações em domínio seguro | Ninguém acessa dados de outra empresa; revogação imediata testada | Depende de AUTH-01/DB-01 |
| INT-01 | Integrações isoladas com CRM/Sites/Performance, eventos e notificações | Consentimento, idempotência, isolamento, auditoria e retry | Sem SSO presumido; por produto |
| BILL-01 | Contratos/assinaturas, cobranças e conciliação via provedor escolhido | Testes sintéticos e homologação contratual antes de valor real | Sem contratação ou cobrança nesta fase |
| REC-01 | Restore fail-closed e continuidade operativa | Inventário de writers, anti-rollback e idempotência comprovados | Continua BLOCKED até evidência e decisão |
| RELEASE | Homologação segurança/acessibilidade/operação | Checklist completo, testes de backup, aceite gerencial e plano de rollback | Nenhum deploy autorizado agora |

**Foco imediato:** PVIEW-01; é possível visualizar/navegar com dados simulados antes de criar contas reais. O preview não deve alterar as rotas reais fechadas. A fundação Laravel permanece isolada e revisável enquanto se desenvolvem as provas operacionais.

## F. Precedência, governança e próximos passos

- Este documento fixa o **direcionamento** aprovado de reequilíbrio, mas não substitui unilateralmente decisões detalhadas já vigentes sobre Sites/CRM/Performance/Universidade. Em choque, registrar conflito e requerer revisão expressa.
- PRs #51/#67/#69 continuam rascunhos, não transferir código deles; os gates P0 R01-01/02/03/09/10, G-ID/G-BB/D02-SEC/D03/RLS e T3 seguem abertos.
- Cada implementação deve ter branch filha, limite de arquivos, teste registrado com SHA, PR draft, e **nenhum merge `main`/`dev` ou VPS sem autorização específica**. Não ocultar falhas de CI.
- Primeira atividade técnica derivada: design/execução isolada de **PVIEW-01** com componentes de apresentação e dados fictícios, a partir de ref verificada; pode ser HTML offline ou render Blade local sem rota, não serviço de produção. Testar acessibilidade e nenhuma requisição externa. Qualquer acesso a contas reais, auth, SQL, deploy ou prestador necessita autorização separada.

**Critério de sucesso desta decisão:** roadmap de entregas demonstráveis com limites claros, progresso de produto observável e a mesma exigência de segurança operacional para informações reais.