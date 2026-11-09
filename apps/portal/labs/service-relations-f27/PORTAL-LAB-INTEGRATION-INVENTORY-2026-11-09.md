# PORTAL-F27 — Inventário de superfícies de leitura e bloqueios de integração

**Data: 09/11/2026. Escopo verificado: branch F26 e descendentes, ainda em PRs DRAFT.**

Este inventário lista **somente interfaces e caminhos efetivamente observados**
no código desta branch. Não equivale a descoberta exaustiva de todos os
readers/writers do ecossistema Imagine, pois outros repositórios e
infraestruturas não foram conectados a este laboratório.

## Entradas Laravel verificadas

| Arquivo / superfície | Estado atual nesta branch | Regra F27 |
|---|---|---|
| `bootstrap/app.php` | Routing web, console e health `/up`; nenhum middleware real de autenticação | Proibir referência F22/F24/F26 |
| `bootstrap/providers.php` | Apenas `AppServiceProvider` registrado | Proibir registro de laboratórios |
| `app/Providers/AppServiceProvider.php` | `register()` e `boot()` vazios | Não fazer bind dos PDO readers |
| `routes/web.php` | Sem rotas de negócio; `/up` é health do Laravel | Não criar login, API ou preview SQL real |
| `routes/console.php` | Sem comandos de negócio | Não permitir runner de laboratório como job |
| `public/index.php` | Bootstrap padrão do Laravel | Não importar laboratórios |
| `config/*.php` | Configuração do scaffold Laravel | Não colocar chaves, credenciais nem classes F22–F26 |
| `phpunit.xml` | Suites `tests/Unit` e `tests/Feature` | F22–F26 integração só via CI PG18 opt-in |

O teste `tests/Feature/PortalLabIsolationBootGateTest.php` confere
automaticamente esse estado e verifica que apenas a rota health está
registrada. Um novo endpoint precisará de revisão formal específica e
atualização deliberada desse teste: **não apagar o teste para fazê-lo
passar**.

## Contratos de leitura, decisão e escrita observados

| Origem | Leitura ou escrita | Natureza | Gate operacional pendente |
|---|---|---|---|
| `PortalAuthorityBoundary` F1 | Sessão, membership e owner via interfaces abstratas | Avaliação pura, não autoriza HTTP | Emissor Portal confiável, fontes persistentes, autorização real |
| `DenyingPortalAuthorities` | Recusa toda consulta | Padrão de segurança sem efeitos | Substituição só por fonte homologada |
| F20 `DemoServiceRelationProjector` | Relações 0..N em memória | Exemplo estático | Fonte SQL sob tenant real e revisão de contagem/recursos |
| F21 `f21.service_summary` | Leitura SQL isolada entre duas empresas fictícias | PostgreSQL 18 descartável com RLS | IAM, pool, políticas oficiais, DBA |
| F22 `SyntheticPgServiceReadAdapter` | Consulta `PDO_PGSQL` em transação read-only | Não registrado em rota/serviço | IAM e proveniência de identidade; RLS real |
| F23 `pg_catalog` | Negação se FORCE RLS/view/role mudar | Deriva negativa, não atestação | Auditoria independente de policies/grants |
| F24 `F24LabSessionProof` | Prova HMAC de sessão **fictícia** | Chave efêmera do runner | Emissor Portal-only, MFA, gestão de chaves, revogação |
| F24 `f24.session_fixture`, `membership_fixture` | Escrita por administrador de **teste** | Estado fictício de duas empresas | Barreira transacional real de todos os writers |
| F25 `synthetic_revocation_writer_gate` | UPDATE/INSERT/DELETE com advisory lock no fixture | Experimento cooperativo, superuser pode contornar | Inventariar todos os writers, jobs e consumidores |
| F26 `F26AuthorityReadRehearsal` | Composição F1 + F24 + PG | Apenas retorna preview sintético sem entitlement | Transação/autenticador e autorização confiáveis |

## Integrações externas NÃO inspecionadas ou autorizadas por F27

- Imagine CRM tem login e sessões próprios; **sem SSO presumido**,
  identidade unificada ou compartilhamento de SQL.
- Imagine Sites, Performance AI, Universidade, Portal Comercial e
  agentes/n8n são motores independentes. A existência de integração
  comercial planejada **não significa** que suas fontes foram
  inventariadas ou que têm leitores/escritores cobertos por F25.
- Cloudflare Workers, R2, Durable Objects e VPS Leaseweb não foram
  acessados. Nenhum recurso, domínio, token ou conta foi criado aqui.
- Webhooks, filas, automações, caches, callbacks externos, outbox,
  importações, backoffice, dados reais, pagamentos e restores não foram
  exercitados. Tratar como **GAP**, não como cobertos por estes testes.

## Critérios de bloqueio até revisão técnica

1. **Não registrar** laboratórios F22–F26 no container Laravel,
   HTTP/console, event listeners, scheduler ou workers reais.
2. Um PASS de `PortalAuthorityBoundary::syntheticChecksPass` **nunca**
   substitui `canAuthorizeHttp=false`, sessão oficial ou RLS.
3. F25 protege somente o banco fictício e usuários cooperantes. Não
   garante revogação linearizável global nem proteção contra restore.
4. Requerer AppSec/DBA, comprovação de autenticador, session/membership
   atuais, owner verificado, RLS de todas as tabelas, Z4/SEC-R03 anti-
   rollback e teste completo de cada reader/writer antes de integração real.
5. Revisão de PR por pessoa responsável antes de merge; gates P0,
   D02-SEC, D03-RLS, SEC-R03-X01..X24, T3, OPEN continuam bloqueados.

**Estado:** PR em rascunho, sem merge em `dev`/`main`, sem alteração de
VPS/Cloudflare, sem clientes, sem custos externos nem produção. O
Node.js continua somente para ferramentas/testes legados; backend
principal do Portal segue Laravel/PHP.
