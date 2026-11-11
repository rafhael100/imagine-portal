# PORTAL-F118 — Revogação total das sessões anteriores no aceite do convite

**Data:** 10/11/2026 · **Status:** prova em PostgreSQL 18 descartável; **não liberado para clientes**.

## Risco de elevação identificado

A F117 faz o aceite do convite, ativa o vínculo empresarial, revoga **a sessão provisória utilizada** e insere os eventos de auditoria em uma transação. Porém, uma pessoa pode possuir outras sessões simultâneas, incluindo sessões provisórias com MFA pendente, sessões legadas sem finalidade e até uma sessão já marcada como `business` antes da ativação. Mesmo com F113, o mero ato de ativar a membership poderia tornar uma **credencial empresarial pré-existente** elegível ao predicado de permissões, caso possuísse um grant e as revisões estivessem alinhadas. Por isso, é insuficiente revogar somente o bearer que executou o convite.

## Implementação

`labs/identity-f118/retire_all_pre_activation_sessions.sql` acrescenta o invólucro `portal_f118_accept_and_retire_all(session_sha256,invitation_sha256,organization_id)`, acessível a um papel de laboratório **EXECUTE-only**. A conta definidora é `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`, com SELECT apenas nas colunas essenciais de memberships e sessões, UPDATE limitado às colunas `status`, `revoked_at` e `updated_at`, e INSERT somente de eventos F89. Não tem acesso de leitura a senhas/TOTP nem permissão de criar sessões ou grants.

1. Executa F117 com o digest da **sessão Portal original**, cuja função revalida a identidade, empresa, finalidade `invitation_only`, MFA, revogação, revisões e o convite, obtendo locks sobre identidade, empresa, vínculo e sessão.
2. Se F117 retorna sucesso, verifica o registro da sessão agora revogada e exige que a membership correspondente esteja ativa com `membership_revision` incrementada exatamente uma vez. A identidade é inferida do banco, nunca de uma declaração avulsa do chamador.
3. Conta as demais sessões `active` ou `pending_mfa` **da mesma identidade e empresa**, incluindo qualquer finalidade `invitation_only`, `business` ou `NULL`. Há um limite conservador de **50 sessões adicionais**; acima disso, a ativação inteira falha fechada, exigindo recuperação administrativa.
4. Em ordem fixa de UUID e sob locks de linha, revoga cada sessão remanescente. Para cada uma grava um evento append-only `session.revoked` cujo digest contém somente UUID da sessão, evento, empresa e identidade, **nunca bearer nem hash bearer-equivalente**.
5. Reconfere que não restou sessão não revogada daquela identidade/empresa. Se qualquer operação pós-F117 falhar — inclusive política RLS de UPDATE, auditoria F89 ou excesso de sessões — uma exceção força rollback da **subtransação inteira**, incluindo consumo do convite, ativação do vínculo, revogação primária e todos os eventos.

F118 revoga o EXECUTE que a role anterior de teste tinha diretamente sobre F117, para que o runner não possa contornar a etapa de revogação integral.

## Segurança e isolamento

As policies RLS F118 são exclusivamente para o papel técnico `NOLOGIN` dentro do PostgreSQL descartável; **não são RLS multiempresa aprovadas para produção**. Elas permitem leitura de sessões ao definidor e a transição `active/pending_mfa → revoked`, com `WITH CHECK(status='revoked' AND revoked_at IS NOT NULL)` e privilégios de colunas mínimos. A role chamadora não possui SELECT/UPDATE em tabelas e não pode invocar F117 diretamente.

A função depende de uma propriedade operacional ainda **não demonstrada**: qualquer emissor futuro de sessão deve bloquear e revalidar o mesmo vínculo antes de emitir ou completar sessões, para impedir uma corrida de INSERT após a varredura. Um escritor superuser, um outro sistema ou uma transação que não respeite esse protocolo não são cobertos por esta prova.

## Testes

`labs/identity-f118/test_pg_full_session_retirement.php` demonstra em fixtures sintéticas:

- token `business` pré-emitido não se torna autorizado depois que a membership fica ativa, mesmo com um grant de serviço já existente;
- sessão primária, segunda sessão de convite, sessão com MFA pendente, sessão `business` e sessão legada `NULL` terminam revogadas no mesmo COMMIT;
- os dois eventos F117 são preservados e há um evento F89 adicional por sessão irmã revogada;
- outra empresa da mesma pessoa e outra pessoa permanecem intactas;
- replay, concorrência, rollback explícito, falhas forçadas de policy de auditoria e de UPDATE negam tudo sem consumir parcialmente o convite;
- mais de 50 sessões adicionais causam rollback completo;
- roles não possuem bypass RLS, leitura direta de sessões, emissão de sessões ou concessão de permissões.

O CI roda PHP 8.3/8.4 sobre PostgreSQL 18 descartável, executa a regressão F117 e o novo teste F118, verifica apenas a rota HTTP `/up` e descarta o schema do runner. O PR herda os checks gerais de Laravel e contêiner privado.

## Bloqueios de produção

Não há emissor confiável de sessão ou MFA de cliente, KMS, cookies/CSRF, e-mail real, recuperação, login empresarial, isolamento RLS operacional por tenant, antifraude, consentimento/retenção LGPD, auditoria testemunhada SEC-R03 nem avaliação AppSec/DBA independente. A invalidação de sessões em outros dispositivos/serviços não está implementada e nenhuma credencial `business` nova é emitida neste fluxo. O limite de 50 exige definição de recuperação segura, não apenas aumento automático.

**Sem login operacional, novas rotas, deploy VPS, merge na `main`, CRM, Ads, dados pessoais ou custos adicionais. DRAFT / NO-GO.**
