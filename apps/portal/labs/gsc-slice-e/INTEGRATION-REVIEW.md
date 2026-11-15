# Imagine Portal × SEO — Candidata consolidada A–AB (revisão)

**Candidata única para revisão:** [PR #543](https://github.com/rafhael100/imagine-portal/pull/543), contra `dev/fast-track-portal-2026-11-05`, **DRAFT/NO-GO**. Antes da atualização deste manifesto: **174 commits / 95 arquivos**, 0 atrás. A antiga PR [#394](https://github.com/rafhael100/imagine-portal/pull/394), ampla e histórica, **não** está incluída nesta candidata.

| Lote | PR incremental | Entrega |
| --- | --- | --- |
| A | [#398](https://github.com/rafhael100/imagine-portal/pull/398) | Contrato SEO RC32 para PHP/HTML offline |
| B | [#399](https://github.com/rafhael100/imagine-portal/pull/399) | Tendências com supressão de métricas |
| C | [#400](https://github.com/rafhael100/imagine-portal/pull/400) | Propriedades e paginação sintética |
| D | [#403](https://github.com/rafhael100/imagine-portal/pull/403) | Erros/retry do provedor, sem HTTP |
| E | [#408](https://github.com/rafhael100/imagine-portal/pull/408) | Paridade de privacidade SEO-F79 |
| F | [#416](https://github.com/rafhael100/imagine-portal/pull/416) | Handoff original SEO-F88 → PHP |
| G | [#421](https://github.com/rafhael100/imagine-portal/pull/421) | PKCE S256, callback HTTPS e state |
| H | [#439](https://github.com/rafhael100/imagine-portal/pull/439) | Classificação de callback e erros OAuth |
| I | [#446](https://github.com/rafhael100/imagine-portal/pull/446) | State/verificador XChaCha20/PG de uso único |
| J | [#447](https://github.com/rafhael100/imagine-portal/pull/447) | Coordenador callback e consumo atômico |
| K | [#449](https://github.com/rafhael100/imagine-portal/pull/449) | Retenção de states e chave protegida |
| L | [#453](https://github.com/rafhael100/imagine-portal/pull/453) | Requisição de token com PKCE, sem HTTP |
| M | [#455](https://github.com/rafhael100/imagine-portal/pull/455) | Parser estrito de token e scope readonly |
| N | [#457](https://github.com/rafhael100/imagine-portal/pull/457) | Teste integrado do fluxo OAuth sintético |
| O | [#460](https://github.com/rafhael100/imagine-portal/pull/460) | Grant por empresa/ator/propriedade exata |
| P | [#495](https://github.com/rafhael100/imagine-portal/pull/495) | Revogação exata ou limitada de grants |
| Q | [#497](https://github.com/rafhael100/imagine-portal/pull/497) | Auditoria de eventos sem payloads pessoais |
| R | [#500](https://github.com/rafhael100/imagine-portal/pull/500) | Revogação + auditoria em mesma transação |
| S | [#503](https://github.com/rafhael100/imagine-portal/pull/503) | Vault AEAD de *candidatos* a token por tenant |
| T | [#505](https://github.com/rafhael100/imagine-portal/pull/505) | Remoção controlada de candidatos vencidos/revogados |
| U | [#509](https://github.com/rafhael100/imagine-portal/pull/509) | Rotação AEAD de chave v1→v2 com dois segredos e CAS no PostgreSQL |
| V | [#511](https://github.com/rafhael100/imagine-portal/pull/511) | Policies PostgreSQL RLS padrão-negado por empresa nas quatro tabelas GSC |
| W | [#512](https://github.com/rafhael100/imagine-portal/pull/512) | Origem do callback vinculada ao consumo único no PostgreSQL; bloqueio de DTO forjado/clonado |
| X | [#533](https://github.com/rafhael100/imagine-portal/pull/533) | Capacidade de planejar apenas uma troca de código por callback consumido |
| Y | [#535](https://github.com/rafhael100/imagine-portal/pull/535) | Envelope de POST OAuth transportável uma só vez, sem clone/serialização |
| Z | [#536](https://github.com/rafhael100/imagine-portal/pull/536) | Wrapper de `SET LOCAL app.tenant_ref` com transação e papel restrito |
| AA | [#539](https://github.com/rafhael100/imagine-portal/pull/539) | Gateway de grant sob RLS e revogação+auditoria atômicas |
| AB | [#543](https://github.com/rafhael100/imagine-portal/pull/543) | Vault sintético bloqueado fora de testes e para tokens sem marcador sintético |

**Não mesclar as PRs A–AA separadamente ou em paralelo com #543.** Elas preservam a trilha de commits e testes de cada entrega; #543 contém o diff único para eventual revisão de integração.

## Limites de código e testes

A CI usa uma **allowlist explícita** de contratos PHP GSC, laboratórios, renderizadores estáticos, cinco migrations e o próprio workflow. Não inclui **nenhuma rota ou controller OAuth pública**, arquivo de deploy, provider credentials, sessão MFA ou telas que possam mostrar credenciais. O limite total é de até 123 arquivos na revisão consolidada, além de limites menores nos lotes incrementais.

- Node 22/24 × PHP 8.3/8.4; hashes SEO RC32 `f46b65aaef9b10e32d02e415907cd916a43b548e`, F79 `fdae08985e89b9bd1323e42d1d64678ddc69e446` e F88 `56d7fa79904903f9af0c88646a59c0fa07546594` fixados na CI.
- PostgreSQL 16 **descartável** testa migrations para state, grants, auditoria e candidatos de token; PKCE/state com expiração e consumo único, AEAD, grant isolado/revogado, auditoria atômica, segredo criptografado e expurgo.
- Migrations adicionadas **somente para revisão:** `2026_11_12_000001_create_portal_gsc_oauth_states.php`, `000002_create_portal_gsc_property_grants.php`, `000003_create_portal_gsc_security_events.php` e `000004_create_portal_gsc_oauth_token_candidates.php`. Não aplicadas em bancos de cliente, staging ou produção.
- O código de vault só armazena candidatos **sintéticos** nos testes. Nenhuma conta Google, token real, cliente, acesso de produção, chamada HTTP, redirect DNS/301 ou cobrança foi ativado.

## Bloqueios externos reais

1. Backend autenticado do Portal, **MFA/RBAC**, membership e isolamento de empresa/operador, com evidência servidor; aceitar strings do cliente não comprova identidade.
2. OAuth registrado no Google Cloud com callback exato; cliente HTTPS de origem fixa, TLS, sem redirecionamentos e com quotas, logs seguros e tratamento de erros.
3. **Comprovação atual de acesso Google** à propriedade exata; um fingerprint ou registro PostgreSQL não é permissão real.
4. Cofre real de chaves e política operacional de rotação. O Lote U valida somente a passagem de v1 para v2 com dados sintéticos, **sem configurar segredos reais nem agendamento**; continuam pendentes retenção LGPD e revogação remota de refresh token.
5. Revisão independente AppSec/DBA/LGPD/SEO, testes de staging autenticado, migrações/rollback ensaiados e autorização de liberação.

**DRAFT/NO-GO.** CI verde atesta testes de componentes e comportamento sintético, não uma integração Google operacional. Nenhum merge em dev/main ou deploy autorizado.

## Camada de isolamento RLS — ainda exige identidade confiável

O Lote V acrescenta a migration `2026_11_12_000005_enable_portal_gsc_tenant_rls.php` para ativar **ROW LEVEL SECURITY obrigatório** nas quatro tabelas GSC, com escopo `tenant_ref = current_setting('app.tenant_ref', true)`. O CI comprova isolamento com usuário PostgreSQL não proprietário e sem BYPASSRLS; `SET LOCAL` é reiniciado ao fim da transação. **Não é autenticação:** a variável de sessão pode ser definida por SQL; cabe ao futuro backend autenticado (MFA/RBAC, memberships e papel de banco não privilegiado) fornecê-la de maneira confiável. A migration é só de revisão, não aplicada a bancos reais.

## Prova interna de consumo do callback (Lote W)

O planejador de tokens não aceita mais `GoogleSearchConsoleOAuthCallbackOutcome` meramente construído no PHP: exige que o coordenador o tenha emitido **após consumo atômico do state em PostgreSQL**. Essa proveniência fica em `WeakMap` privada local ao processo PHP, impede falsificação/clonagem do objeto e não é serializável como cookie ou token. Ainda **não** verifica identidade/MFA do Portal nem acesso Google e não habilita rede; a troca deve ocorrer no mesmo backend confiável que realizou o CAS.

## Consolidação de segurança X–AA (12/11/2026)

As etapas X/Y bloqueiam planos e envelopes OAuth repetidos após o consumo do state no PostgreSQL. O envelope de transporte contém segredos apenas durante a primeira retirada, não pode ser clonado ou serializado e não autoriza HTTP. A fase Z aplica `set_config('app.tenant_ref', :tenant, true)` dentro de uma transação sob role PostgreSQL restrita, prevenindo vazamento do tenant entre requisições. A etapa AA utiliza esse wrapper de fato para consultar grants e revogá-los em uma única transação com evento de auditoria, revertendo ambos quando a escrita do evento é negada. Tudo validado com dados e papéis de teste no PostgreSQL descartável.

**Aviso importante:** não existe um login MFA/RBAC autenticando este contexto na integração GSC; `tenantRef` continua precisando vir exclusivamente de uma sessão/identidade verificada por backend externo. Também faltam autenticação Google real, callback registrado, provas de acesso à propriedade, HTTPS de produção, gestão de segredos e parecer independente. A migração e o RLS continuam só no CI. CI verde não autoriza o merge nem acesso aos dados de clientes.

## Lote AB — bloqueio de tokens reais no vault de laboratório

O vault de candidatos OAuth continua um **módulo de revisão, não um cofre de tokens operacionais**. Suas ações de escrita, leitura, revogação e rotação exigem ambiente `testing` e flag de laboratório; tokens cujo conteúdo não começa por `synthetic-` são recusados. Os testes PostgreSQL validam que um candidato de formato plausível não é persistido e que a aplicação não consegue operar o vault em `production`. Antes de habilitar credenciais reais é obrigatório construir/autorizar um serviço separado com sessão Portal autenticada, prova Google de propriedade e escopo, transporte HTTPS, cofre operacional de chaves, auditoria e política de retenção; nenhuma destas integrações foi declarada pronta.
