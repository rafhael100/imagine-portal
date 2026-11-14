# F107 — Prova de compatibilidade entre sessão Portal e GSC (laboratório)

## Implementado

Esta entrega junta duas candidatas independentes **sem mesclá-las**:

1. O leitor original
   `PostgresPortalSessionPrincipalCandidateReader.php` e sua função SQL
   `portal_browser_principal_candidate` vêm da revisão de identidade do Portal
   fixada na SHA `563e281c0e789e6bcd772aa1095a7ec33490b544`.
2. A candidata GSC F104–F106 usa o PDO de papel restrito, o registro
   de vínculo UUID→tenant/ator GSC, um grant registrado e RLS forçada;
   as métricas são geradas pelo código real SEO F104 na SHA
   `ec1739621b20860f729c59d9863ba1b341da43e8`.

`OfflinePortalGscSessionCandidateReview` observa uma referência opaca de
sessão de negócio via **o leitor SQL original e imutável da identidade**,
confere organização esperada, expiração e revisões observadas e entrega
somente uma candidata interna ao verificador F106. A origem dos UUIDs
de organização/usuário é o banco de identidade, **não o DTO de SEO**.

O teste usa PostgreSQL 16 descartável, duas conexões restritas e dados
inventados. Instala o SQL de primeira parte inalterado, com tabelas e
políticas RLS sintéticas construídas para o ensaio. Testa sessão válida,
outra organização, referência falsa, MFA ausente, finalidade da sessão
indevida, expiração/revogação, revisões divergentes, associação suspensa,
grant GSC revogado e flags de produção desabilitadas.

## Limite que esta prova NÃO resolve

A função SQL original de identidade fornece somente `expires_at`,
não `issued_at`. O adaptador F107 utiliza o instante de observação
como campo de normalização **exclusivamente para F106 em laboratório**;
isso não é o instante verdadeiro da emissão. A própria função de banco
já verifica o intervalo da sessão e o MFA, mas este detalhe deve ser
reconciliado no contrato de produção e auditado.

Além disso, o leitor de identidade e o gateway GSC usam **conexões e
transações diferentes**. Uma revogação concorrente entre a consulta
da sessão e a leitura do GSC ainda precisa de um bloqueio transacional
ou autoridade externa com fencing confiável. Por isso o resultado
identifica explicitamente `singleTransactionAcrossIdentityAndGsc=false`
e `sessionRevocationFenced=false`.

Essa prova, mesmo em PostgreSQL real, não ativa autenticação Laravel,
não cria controller, cookie, login, callback ou consulta Google. Os
indicadores `sessionAuthenticatedForProduction`,
`googleOAuthVerifiedNow`, `customerDisplayAuthorized`,
`productionReadAuthorized` e `publicationAuthorized` são sempre falsos.

## Próxima integração operacional

- Reconciliar as branches de identidade, GSC e Portal principal com revisão
  de código/DBA, sem presumir que snapshots são permissões.
- Projetar leitura revogável e atômica ou fence inequívoco para evitar
  reuso do resultado entre sessões/tenants e garantir observação
  de revogação concorrente.
- Exigir permissão SEO/GSC explícita no RBAC do Portal antes de qualquer
  rota autenticada.
- Implantar Google OAuth readonly e prova atual de propriedade,
  consentimento, vault e trilha de auditoria (processo separado).
- Validar staging, backup, rollback, LGPD e AppSec independente, com
  autorização de release específica.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
