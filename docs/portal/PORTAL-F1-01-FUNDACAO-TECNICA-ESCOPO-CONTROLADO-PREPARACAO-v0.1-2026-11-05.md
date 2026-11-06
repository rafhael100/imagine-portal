# PORTAL-F1-01 — Fundação técnica isolada do novo Portal Imagine — pacote de preparação v0.1

**Data:** 05/11/2026  

> **ATUALIZAÇÃO SUPERVENIENTE — 05/11/2026:** o responsável **AUTORIZOU EXPRESSAMENTE somente a implementação F1-01-A** com a resposta “ok pode avançar”. Foi criada a branch isolada `dev/fast-track-portal-2026-11-05` e o [PR #11 de código em draft](https://github.com/rafhael100/imagine-portal/pull/11), HEAD `03cb6297e3880e8665289fe20730d43900e17f95`. O novo scaffold está em `apps/portal/`; 37 arquivos alterados exclusivamente nesse prefixo; testes Laravel completos continuam **PENDENTES** (Composer/extensões não disponíveis neste ambiente). O [registro da execução](PORTAL-F1-01-A-REGISTRO-EXECUCAO-CONTROLADA-PR11-2026-11-05.md) prevalece sobre menções históricas de “não autorizada” para **F1-01-A somente**. F1-01-B/C, F1-02+, merge, produção e P0 seguem sem autorização.

**Projeto:** Portal Imagine  
**Fase:** PORTAL-F1-01 (candidata à primeira implementação controlada)  
**Estado:** **PLANEJAMENTO TÉCNICO DOCUMENTAL PREPARADO; NÃO APROVADO PARA PROGRAMAR OU TESTAR**  
**Documento de origem:** [PORTAL-F1-00](PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md)  
**Repositório:** `rafhael100/imagine-portal`, branch documental `docs/portal-f1-00-2026-11-05`. `main` de referência verificada: `50b683a499e674641df5749051f3d05f7ac3f627`.

## 1. Objetivo e enquadramento

Definir um **recorte pequeno e verificável** para futura programação do novo Portal Imagine, em **Laravel + PostgreSQL**, partindo de arquitetura nova e priorizando **fluxos de negócio e ideias do protótipo**. A aprovação posterior de D03-R02 confirma que o sistema antigo ainda era projeto de desenvolvimento e que seus dados eram de teste; não migrar SQLite ou código por obrigação. Manter aplicações e logins humanos Portal/CRM independentes; uma eventual identidade integrada Portal–Universidade–Performance AI é discussão futura, não requisito da F1-01.

A proposta **não cria estrutura de produção**, não trata o legado como sistema operacional a preservar funcionalmente e não estabelece contas reais. O código antigo pode ser examinado **somente como referência conceitual**. Não apagar o protótipo, reescrever o histórico Git nem excluir backups ou dados do desenvolvimento antigo.

**Decisões existentes:**
- D01-B: Laravel + PostgreSQL como arquitetura-base do novo Portal — **APROVADA**.
- D02: logins humanos independentes entre Portal e CRM — **APROVADA COMO DIRETRIZ**; MFA, gestão de sessão, RBAC e relações empresa/usuário permanecem por especificar.
- D03-MIG-B: backend novo e modelo lógico direcional — **APROVADOS**; D03-R02: legado é protótipo/dados de teste, aproveitar prioritariamente fluxos — **APROVADA**.
- D04-FASE-A: catálogo, preços, campanhas, cálculo e propostas/contratos simulados — **ESCOPO DIRECIONAL APROVADO**; programação ainda não liberada.

## 2. O que a F1-01 deverá efetivamente entregar após autorização futura

### F1-01-A — Fundação de projeto (primeira entrega recomendada)

1. Preparar, em **nova branch de desenvolvimento isolada**, um diretório de aplicação novo, **candidato** `apps/portal/` ou alternativa documentada, **sem sobrescrever** `server/`, `public/`, `package.json`, SQLite ou testes do legado.
2. Criar esqueleto Laravel organizado, com configuração exclusiva do Portal e ponto de conexão **somente configurável** para banco PostgreSQL isolado de desenvolvimento, sem credenciais reais nem operações de infraestrutura.
3. Escrever **inventário arquitetural leve** com módulos previstos, suas responsabilidades e dependências permitidas: `Identity` (contrato/placeholder), `Organizations`, `Catalog`, `Offers`, `Contracts` (simulados), `ServiceProjects`, `Support` e `Integrations` (contratos sem chamadas).
4. Configurar interfaces de ambiente seguras: parâmetros por variáveis de ambiente **de exemplo sem segredos**, migrações sob controle, noções de multiempresa, logs minimizados; não hospedar ou disponibilizar publicamente.
5. Incluir rota de **health-check local sem dados pessoais** e/ou testes de unidade de bootstrap; nenhuma rota de autenticação, conta real, compra ou operação financeira exposta nesta fase.
6. Gerar documentação clara `apps/portal/README.md` com instalação isolada, pré-requisitos verificáveis, forma autorizada de executar testes offline e documentação das dependências.
7. Se dependências precisarem ser obtidas da rede, exigir permissão do executor para instalação em ambiente de desenvolvimento isolado; não presumir que dependências, Compose, PHP, PostgreSQL ou serviços da VPS já estejam disponíveis.
8. Definir PHP/Laravel/driver PostgreSQL apenas na implementação **após** verificar versões suportadas e políticas do projeto; D01 não escolheu versões nominais.

### F1-01-B — Interfaces e modelos mínimos **sem identidade funcional** (subfase candidata, NÃO automaticamente autorizada)

- Tipos/interfaces desacopladas e contratos de domínio com organização e identidade como **referências fictícias**, sem CRUD multiempresa real, sem login, sem sessão e sem elevar autorização.
- Fixtures 100% sintéticas para cenários de organização A/B, cliente fictício e produtos Onepage/Landing Page; segregação de dados **como requisito e teste futuro**, não como garantia de segurança em produção.
- Controles de integridade e auditoria necessários a especificar antes de incluir contas, PII e dados reais.
- Não criar migrations de tabelas de usuário/tenant nem aplicar RLS ao banco real sem fechamento de D02-SEC e desenho físico específico D03.

### F1-01-C — Preparação de cálculo comercial isolado (somente se liberada explicitamente em outro escopo)

- Descrever contratos do motor puro de ofertas `price quote` com entrada explícita de produto/campanha/competência e saída de valores precisos/versionados.
- O escopo de **programação do motor** exige aprovar previamente precedência/cumulatividade dos descontos Pix com promoções, limites, arredondamento, validade e aceites; **não antecipar** decisões D04-P02.
- Considerar somente resultados fictícios, com testes determinísticos e **nenhuma dependência em Asaas/Focus, PSP ou contas CRM**.

**Regra de corte:** quando autorizada a programação da F1-01, sugerir **somente F1-01-A** como primeiro PR de código. B/C exigem liberação própria após resultados e ajustes dos gates. O usuário não autorizou nenhuma dessas subfases ao dizer apenas "continue".

## 3. Arquitetura-alvo mínima e fronteiras

| Elemento | Decisão / escopo na F1-01-A | Não permitido |
|---|---|---|
| Aplicação Portal | Novo Laravel isolado do monólito Node.js existente | Substituir endpoints públicos do protótipo ou apontar domínio real |
| Persistência | PostgreSQL **projeto distinto** e credenciais separadas, em configuração de desenvolvimento; sem banco provisionado agora | Compartilhar Postgres/schemas/tabelas do CRM |
| Usuários e sessões | Interfaces e documentação para futura identidade do Portal, independente do CRM | SSO Portal↔CRM, login real, usuários importados do protótipo |
| Organizações | Modelo lógico D03; escopo e permissão empresa são invariantes para design | Cadastro real de tenants sem RBAC/MFA/isolamento homologados |
| Catálogo/comercial | D04-A aprovada documentalmente; só referência neste PR A | Checkout, contrato real, PSP, pagamentos, faturamento |
| Sites/CRM | Contratos técnicos futuros autenticados, com donos de estados separados | Chamadas reais, leads, publicação, concessão de cortesia, sincronização de logins |
| Universidade/Performance AI | Manter interfaces desacopladas para estudos futuros | Provisionar serviços ou criar SSO presumido |
| Segurança | Segredos ausentes, logs sem dados sensíveis, permissões negadas por padrão quando introduzidas | Expor dados reais ou configurar contas com papéis genéricos em produção |
| Legado | Repositório antigo preservado para leitura de ideias e verificação mínima antes de descarte futuro | Migração/descarte automático de SQLite, uploads ou histórico |
| Infraestrutura | Nenhum deploy; documentação preparada para ambientes separados | Mudança em VPS, GitHub `main`, Cloudflare, DNS, provedores ou gastos |

## 4. Sequência de PRs proposta, somente após autorização específica

| PR futuro | Conteúdo admissível | Resultado de aceite |
|---|---|---|
| **F1-01-A / PR1** | Skeleton Laravel segregado em diretório novo, exemplo de configuração sem segredos, README, testes locais mínimos | Código isolado; verificações passam em ambiente autorizado; nenhuma modificação legado/produção |
| **F1-01-B / PR2** | Contratos/interfaces de domínio e fixtures fictícias, somente após aprovação D02/SEG relevante | Tipos/limites documentados; sem usuários reais e sem vazamento entre cenários |
| **F1-01-C / PR3** | Motor puro de ofertas fictícias, somente após aprovação D04-P02 e liberação específica | Casos de teste comerciais versionados; zero PSP/integrações reais |

**Não criar PR2/PR3 nem abrir F1-02+ automaticamente** em consequência de aprovação futura de PR1.

## 5. Critérios de aceite e validação da F1-01-A

Os critérios abaixo são **propostos para futura programação/testes autorizados**; ainda não foram executados.

1. Nova aplicação no diretório isolado; arquivos Node/SQLite legados e `main` intactos; documentação explica arquitetura e decisão D03-R02.
2. PHP/Laravel/PostgreSQL versões suportadas e registradas; dependências fixadas pelo mecanismo próprio e sem segredos no repositório.
3. Testes de bootstrap e checagem estática/documental (ex.: `composer validate`, testes de unidade/feature local) executados **somente após liberação**; evidência do comando, versão, resultado e limites. Não mascarar testes falhos.
4. Health-check não expõe detalhes internos, configurações sensíveis ou acesso a dados; não configura autenticação de cliente real.
5. Nenhum endpoint de checkout, webhook financeiro, ativação do CRM, lead, publicação/atualização de site ou emissão fiscal.
6. Sem `npm`/Docker/Compose/serviço n8n executado no legado por consequência do novo scaffold; eventual sandbox local isolada explicitamente aprovada.
7. Revisão humana de diff, SHA, arquivos modificados e ausência de segredos; PR futuro em **draft**, sem merge e sem deploy.
8. Se o ambiente não viabilizar Laravel/PHP/Composer, documentar impedimento; **não** simular sucesso ou trocar stack para contornar sem decisão.

## 6. Proteções de GitHub, branch e Codex

- **Branch documental atual:** `docs/portal-f1-00-2026-11-05`, PR #10 em draft visando `main`; não integrar por inferência.
- **Branch futura de código candidata:** `dev/fast-track-portal-2026-11-05`, criada **somente depois de autorização de programação expressa** e conferência dos SHAs, idealmente partindo de `main` verificada e tendo como fonte os documentos aprovados na branch de planejamento; não sobrescrever `main`.
- Se as especificações ainda não estiverem na branch de código, o executor deve lê-las na **referência exata** e registrar os links/versões no PR; não fingir que estão presentes na `main`.
- Não realizar merge automático nem aplicar `AGI-11-RB01` (rollback automático desabilitado).
- **Codex futura tarefa delimitada:** `F1-01-A` apenas — criar skeleton local Laravel e configuração de exemplo sem segredos em diretório novo, documentação e testes locais não integrados. O executor deve interromper a ação caso necessite alterar infraestrutura, dados reais, repositório CRM, legado ou contratos P0 não homologados.
- Instrução ao Codex **ainda NÃO EMITIDA para execução**: esta documentação não inicia um agente nem concede autonomia de continuar PRs sucessivos.

## 7. Registro de riscos e pendências

| Código | Estado atual | Efeito na primeira codificação |
|---|---|---|
| **GATE-F1-01-PROG** — autorização expressa de implementação | **ABERTO** | Proíbe qualquer PR de código/Codex por esta conversa |
| **D02-SEC01–06** — auth/MFA/RBAC/segregação | **ABERTO** | Bloqueia login real, tenants operacionais, permissões reais e SSO |
| **D03 físico** — esquema, organização e RLS | **ABERTO** | Bloqueia migrations definitivas de clientes, contratos e autorização |
| **D04-P02** — cumulatividade/preços/campanhas | **ABERTO** | Bloqueia motor comercial definitivo |
| **D04-FASE-B/C** — contratação real/PSP | **NÃO APROVADAS** | Bloqueia checkout, pagamentos, fiscal e integração de benefícios |
| **P0-DOC01–14 e RV-CT** | **ABERTOS/PARCIAIS** conforme fonte própria | Bloqueia integrações reais Portal/Sites/CRM e release final |
| **Infra/backup/segredos** | **PARCIAL/ABERTO** | Sem VPS, deploy, backups reais ou serviços externos |
| **main e protótipo** | **PRESERVAR** | Sem merge, rewrite, migração ou destruição |
| **AGI-11-RB01** | **DESABILITADO** | Sem rollback automático |

## 8. Decisão operacional futura a solicitar

**PORTAL-F1-01-DEC-01 — AINDA NÃO APROVADA:**

> Autorizo exclusivamente a implementação da **F1-01-A**, com criação de branch isolada após conferência de SHA, scaffold novo Laravel/PostgreSQL em diretório próprio, configuração de exemplo sem segredos, README e testes locais isolados. **Não autorizo:** F1-01-B/C, F1-02+, login real, migração, PSP/cobranças, integrações CRM/Sites, VPS, Cloudflare, deploy, custos, alteração do protótipo, `main` ou merge automático. O Codex deverá preparar PR de código em draft para revisão, sem liberar gates P0.

A formulação acima é **proposta para deliberação específica**, não autorização já concedida. Se aprovada futuramente, conferir o estado corrente do repositório, adaptar SHAs e limites e executar somente no perímetro explicitado. Caso algum bloqueio P0 proíba mesmo o scaffold, documentar a exceção estritamente técnica antes da execução.

## 9. Referências

- [PORTAL-F1-00](PORTAL-F1-00-PREPARACAO-DESENVOLVIMENTO-CONTROLADO-v0.1-2026-11-05.md)
- [D01 — Laravel/PostgreSQL](PORTAL-TEC-D01-APROVACAO-ALTERNATIVA-B-LARAVEL-POSTGRESQL-2026-11-05.md)
- [D02 — logins independentes](PORTAL-TEC-D02-LOGINS-INDEPENDENTES-PORTAL-CRM-DECISAO-2026-11-05.md)
- [D03-MIG-B — aprovada](PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md)
- [D03-R02 — legado é protótipo/dados teste](PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md)
- [D04-A — catálogo/ofertas fictícias](PORTAL-TEC-D04-APROVACAO-FASE-A-CATALOGO-OFERTAS-SIMULADAS-2026-11-05.md)
- [P0 de Sites/Portal/CRM](../imagine-sites/P0-FECHAMENTO-DOCUMENTAL-01.md)

**Resumo de estado:** pacote técnico F1-01 pronto para revisão no PR documental. **Programação NÃO autorizada, nenhum código foi escrito por este instrumento.**
