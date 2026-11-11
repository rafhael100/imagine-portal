# PORTAL-INT-F156 — Revisão de segurança da empresa no PostgreSQL 18

O contrato F155 exige que a empresa esteja ativa e que `PortalSessionSnapshot::sessionOrganizationRevision` seja igual ao `security_revision` atual da organização. A fundação F66 armazena `portal_organizations.security_revision` e a migração F76 armazena `portal_auth_sessions.organization_revision` — sem valor implícito para sessões legadas — mas o banco ainda permitia **alterar o status da organização sem elevar a revisão**.

F156 acrescenta uma migração para revisão `2026_11_11_000013_fence_portal_organization_revision.php`, com trigger PostgreSQL SECURITY INVOKER, sem RLS ALLOW nem grants:

- Impede substituir o id/identificador de uma organização existente;
- Exige incremento da revisão sempre que status mudar: `active→suspended` incrementa revisão, `suspended→closed` incrementa novamente;
- Bloqueia reativação silenciosa `suspended→active`, bem como mudança de `closed` para outro status, até a existência de um fluxo de reativação com identidade e auditoria homologadas;
- Recusa diminuição da revisão e trata limite inteiro de segurança;
- Recusa remover o trigger enquanto houver organizações/sessões no banco, exigindo limpeza revisada antes do rollback.

A CI `portal-int-f156-org-revision-pg18.yml` roda a migração real **somente** contra PostgreSQL18 descartável, PHP8.3/8.4. Cria organização, membership e sessão sintética de revisão 1; suspende a empresa e prova a revisão 2 e a sessão obsoleta; tenta reativação ou redução da revisão e exige negação; encerra a organização e prova revisão 3; e cria role NOLOGIN/NOBYPASSRLS com grants SQL explícitos para verificar que FORCE RLS sem políticas mantém leituras/mutações negadas. Depois testa o rollback recusado com registros e o rollback bem-sucedido após limpeza dos fixtures.

**NO-GO:** essa migração não foi executada na VPS nem em produção; funções SECURITY INVOKER não elevam privilégios de quem as chama, e o estado de organização confiável ainda não está ligado ao runtime. Real reativação, issuer de sessão, autorização de mudança de status, auditoria independente, retenção LGPD, revogação e migrations de staging exigem parecer AppSec/DBA independente antes de qualquer liberação.
