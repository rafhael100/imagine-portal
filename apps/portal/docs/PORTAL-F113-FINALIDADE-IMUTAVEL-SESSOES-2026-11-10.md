# PORTAL-F113 — Finalidade imutável da sessão (convite × uso empresarial)

**Data:** 10/11/2026. **Status:** migração e funções de prova em PostgreSQL 18 descartável, **NO-GO de produção**.

## Risco corrigido

A F112 lê do PostgreSQL uma sessão sintética de identidade Portal para pré-verificar o aceite de convite. Entretanto, a migração F66 guardava sessões `active` sem diferenciar se eram uma sessão provisória de **aceite de convite** ou uma sessão de **uso normal de serviços**. Uma sessão criada antes da ativação do vínculo poderia obter autorização empresarial **sem ser reemitida**, caso o vínculo passasse a `active`, permissões fossem concedidas e a revisão da membership não fosse incrementada. O predicado F76 antigo exige vínculo ativo e concessão explícita, mas não conhece a finalidade da sessão. Essa combinação é demonstrada em teste adversarial, não apenas presumida.

## Implementação

Migração `2026_11_10_000008_add_session_purpose_to_portal_auth_sessions.php`:

- Acrescenta `session_purpose` como coluna **nullable SEM default**. Sessões legadas ficam com `NULL`, que nenhum novo gate pode interpretar como acesso liberado.
- Aceita apenas `business` e `invitation_only`. Para sessões de convite exige revisão de organização e duração máxima de **1 hora**; para sessões de negócio, revisão de organização e duração máxima de **24 horas**.
- Trigger `portal_f113_session_purpose_immutable` rejeita qualquer mudança de finalidade após o `INSERT` (inclusive de `NULL` para `business`). Após conclusão de convite, o serviço futuro precisa **emitir uma nova sessão** de finalidade empresarial, passando por novo processo revisado de autenticação; nunca reaproveitar bearer provisório.
- Não introduz política de RLS permissiva, GRANT, rota, emissor nem alteração automática de registros antigos.

SQL isolado em `labs/identity-f113/purpose_isolation_gate.sql`:

- Endurece a policy de sessões da fonte F112 para `session_purpose='invitation_only'`. Essa regra se aplica ao definer `NOLOGIN` da F112, sob `FORCE RLS`.
- Introduz outro papel `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`, com leitura mínima de `session_sha256` e `session_purpose`, policy RLS específica para `business` e nova função `portal_f113_business_permission_check`.
- Só libera uma permissão de serviço quando a sessão tem finalidade `business` **e** a checagem F76 de sessão, organização, MFA, revisions, membership ativa e concessão explícita é verdadeira.
- Revoga `EXECUTE` direto do F76 original no papel de negócio do laboratório; esse papel só recebe a função nova, que verifica finalidade. F76 ainda é SECURITY DEFINER de laboratório e precisa ser substituída por uma autoridade independente antes de produção.

## Testes

`labs/identity-f113/test_pg_session_purpose_isolation.php` usa uma identidade e empresa fictícias, três sessões de alta entropia (convite, negócio, legada) e PostgreSQL 18 em PHP 8.3/8.4.

Valida que a sessão provisória serve somente como **candidata sintética** na F110, sem capacidade HTTP ou consumo; ao mudar a membership para ativa e conceder `services.view` **SEM mudar a revisão**, o predicado F76 antigo passaria a aceitá-la, mas a nova função F113 ainda a nega. Em seguida cria uma sessão `business` nova, que alcança somente a concessão explícita. Sessões `business` não servem para pré-aceite, e sessões `NULL` legadas são negadas em ambos os caminhos.

Testa ainda bloqueio de troca de finalidade por trigger, limite de duração, falta de grants, papel não-superuser, isolamento de SELECT sob RLS, revogação e mudança da revisão da empresa. O workflow aplica e desfaz F66, F76 e F113; remove funções/policies/roles do ensaio e preserva a rota HTTP única `/up`.

## Dependências antes de uso real

- O emissor F82 existente só autentica membership `active` e é **laboratório**; ainda não existe emissor confiável de sessão `invitation_only` para vínculo `invited`. Isso será necessário para a próxima fase. Não é seguro improvisar usando sessão empresarial.
- O aceite futuro precisa provar titularidade, e-mail e MFA reais; consumir convite de modo transacional (F106–F108), ativar membership com revisão e auditoria e depois emitir **nova** sessão empresarial.
- As policies `USING(true)` limitadas aos roles técnicos são provas de laboratório, **não** regras RLS multiempresa para produção. Revisão independente DBA/AppSec, KMS, CSRF, rate-limiting, gestão de cookies, SEC-R03 e homologação da VPS continuam pendentes.
- Nenhuma sessão de cliente emitida, login, endpoint novo, merge em `main`, deploy, CRM/SSO, Ads ou custos adicionais.

**PR draft / NO-GO operacional.**
