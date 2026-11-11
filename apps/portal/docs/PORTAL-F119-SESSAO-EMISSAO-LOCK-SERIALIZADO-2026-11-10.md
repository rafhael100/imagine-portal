# PORTAL-F119 — Emissão de sessão serializada com aceite de convite

**Data:** 10/11/2026 · **Status:** laboratório PostgreSQL 18; sem autenticação operacional.

## Brecha remanescente após F118

A F118 revoga todas as sessões antigas quando a membership muda de `invited` para `active`. Essa varredura só é completa contra emissores **que bloqueiam o mesmo vínculo empresarial**. Se outro emissor inserir uma sessão durante a ativação, sem respeitar o bloqueio de membership, poderá criar uma credencial que escape da varredura ou uma sessão `business` com data anterior à ativação. A F119 testa a defesa no **próprio PostgreSQL**.

## Contrato SQL executável

`labs/identity-f119/locked_session_admission.sql` instala um trigger `BEFORE INSERT OR UPDATE OR DELETE` sobre `portal_auth_sessions`, invocando uma função `SECURITY DEFINER` de papel `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`. O proprietário não tem INSERT/UPDATE na tabela de sessões nem acesso à senha ou ao segredo TOTP. Seu acesso mínimo a identidade, empresa e membership existe só para **SELECT ... FOR SHARE**, em ordem fixa, sob policies de laboratório `WITH CHECK(false)` que impedem alterar essas entidades.

O trigger requer:

- Identidade e organização ativas, e-mail e MFA já confirmados, revisões atuais de identidade, organização e membership iguais às da sessão.
- `session_purpose='invitation_only'` **somente** enquanto membership for `invited`; `session_purpose='business'` **somente** depois de membership `active`, com `issued_at` não anterior ao `updated_at` do vínculo.
- Toda sessão nova deve começar `pending_mfa`, sem `mfa_verified_at`; a promoção a `active` exige timestamp MFA fresco, posterior à emissão, não futuro e com idade máxima de cinco minutos.
- UUID da sessão, digest do bearer, identidade, empresa, finalidade, revisões, emissão e expiração são imutáveis após INSERT; revogado não pode retornar a ativo ou ser apagado por DELETE.
- `active/pending_mfa → revoked` continua possível mesmo **após** o aumento da revisão na ativação da membership, para permitir que F117/F118 revoguem credenciais antigas de modo atômico.
- O bloqueio das três linhas pai segue a ordem de F117/F118, serializando a escrita das sessões com a transação de aceite. Uma emissão concorrente deve esperar e, após o commit de ativação, não pode mais inserir uma sessão `invitation_only`.

Nenhum mecanismo de origem do dispositivo foi aprovado: a função é um **guardião de invariantes no banco de testes**, não substitui emissor confiável, senha, TOTP e KMS.

## Verificações

`labs/identity-f119/test_pg_serialized_session_issuance.php` testa:

- tentativa de emitir um bearer empresarial antes da ativação falha;
- emissão de sessão já `active`, saltando MFA, falha; sessão provisória começa `pending_mfa` e só é promovida com timestamp MFA;
- digest de sessão, finalidade e expiração não podem ser alterados depois de emitidos;
- enquanto a transação F118 mantém o lock de membership, um escritor que tenta inserir uma nova sessão bloqueia por timeout (`55P03`), sem escapar da varredura;
- após o commit, uma nova sessão `invitation_only` é recusada e todas as antigas permanecem revogadas;
- só uma **nova** sessão `business` iniciada depois da ativação pode passar por MFA; mesmo assim, **sem grant explícito** a função F113 ainda recusa `services.view`;
- session revogada não pode ser ressuscitada ou apagada, e o definer não tem permissão de alterar o vínculo.

Workflow F119 executa PHP 8.3 e 8.4, PostgreSQL 18 descartável, regressões F117 e F118, testes do trigger e verificação de que HTTP continua somente `/up`.

## Limites e pré-requisitos de liberação

O trigger não impede um DBA superuser de desativá-lo ou fazer restauração arbitrária. As policies de leitura de todos os tenants pelo `NOLOGIN` são **somente de laboratório** e não podem ser copiadas para produção. A verificação temporal baseada em `membership.updated_at` é uma regra candidata, não um substituto de um registro formal e auditado de ativação.

São pendentes: emissor não-superuser revisado, KMS, segurança de cookies/CSRF, MFA de cliente, verificação real de titularidade, revogação e recuperação segura, RLS operacional por empresa, auditoria externa SEC-R03, testes de carga e revisão independente DBA/AppSec/LGPD.

**Nenhum endpoint, sessão real, deploy VPS, alteração da main, CRM/Ads ou despesa adicional. DRAFT / NO-GO.**
