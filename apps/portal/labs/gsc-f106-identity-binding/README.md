# GSC F106 — vínculo entre IDs Portal (UUID) e escopo GSC sob RLS

## Objetivo entregue

O Portal usa IDs de organização e identidade em formato UUID nos
componentes recentes de autenticação; a etapa GSC usa
`tenantRef` / `actorRef` próprios. A F105 verificou o grant por
empresa, mas recebia essas referências como um contexto declarado.

A F106 adiciona um **registro de vínculo explícito** no PostgreSQL:
`portal_gsc_identity_scope_links`, relacionando
`portal_organization_id`, `portal_identity_id`,
`tenant_ref`, `actor_ref`, revisão da organização,
revisão do membership, início, expiração e revogação.

O runtime de leitura não recebe autorização de criar vínculos. Esse
registro somente deve ser provisionado futuramente por serviço confiável
que valide sessão Portal + MFA + vínculo de empresa, nunca pelo navegador
ou por um cliente externo.

## Verificador isolado

`OfflinePortalGscIdentityBindingReview` executa, na **mesma transação
PostgreSQL com `SET LOCAL app.tenant_ref` e RLS forçada**, tanto a
conferência do registro de vínculo por UUIDs e revisões, quanto a
consulta do grant de propriedade GSC. O ator GSC vem do registro em
banco, **não de uma alegação do DTO F104**. Nenhuma dessas verificações
sozinha prova login ou propriedade Google.

Regras:
- DTO F104 restrito ao laboratório (`imagine-demo` /
  `sc-domain:example.com`), com fingerprint exato.
- IDs UUID do Portal e revisões de organização/membership compatíveis
  com a linha gravada; período de sessão candidato e prazo de vínculo
  dentro dos limites.
- Recusa linhas revogadas, vencidas ou de empresa/propriedade diferente,
  registros de grant revogados, sessão sintética expirada e revisões
  divergentes.
- `GoogleSearchConsoleTenantScopedTransaction` passa a recusar, além
  de superuser e BYPASSRLS, papéis PostgreSQL com `CREATEROLE`,
  `CREATEDB`, `REPLICATION`, `INHERIT` ou `SET ROLE`.
- Nenhum dado bruto, identificador UUID, usuário, token ou URL de
  propriedade sai na resposta.

A prova de comportamento roda em `postgres:16-alpine`, usando papéis
não privilegiados, exemplos totalmente fictícios, três cenários
(completo/parcial/vazio), mutações adversariais, migrações reversíveis
e checkout pinado da F104.

## Limites fundamentais

**Este módulo não autentica a sessão do usuário.** O parâmetro
`principal` é um candidato fornecido pelo teste, não uma evidência
emissor-verificada. Em produção, valores como UUID, revisões e propósito
devem vir do backend privado de identidade do Portal, após checar uma
sessão/MFA vigente e revisão de membership/organização. Mesmo com
vínculo válido e grant registrado, todos os flags de autorização de
exibição, produção, Google atual e publicação permanecem **falsos**.
Não criar controller/rota HTTP para este laboratório.

O código de autenticação candidato fica em branches independentes.
A integração final exige reconciliar essas branches com revisão, sem
presumir que a classe `PostgresPortalSessionPrincipalCandidateReader`
autorize HTTP: ela explicitamente não autoriza.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
