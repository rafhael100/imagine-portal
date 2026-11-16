# Portal SEO F115 — coerência entre grant GSC e token cifrado (PG18)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação da
[F114 / PR #852](https://github.com/rafhael100/imagine-portal/pull/852).

## Problema

Um candidato de token OAuth cifrado existente no banco **não** basta
para permitir qualquer uso futuro. É necessário verificar também se o
grant registrado da mesma **empresa, ator e propriedade exata GSC**
está ativo e recente. A F115 fornece um contrato de leitura segura
**somente para laboratório**, sempre sem autorizações reais.

## Comportamento

PortalSeoPgScopedTokenCandidateReviewLab exige:

- Execução PHP CLI exclusivamente em CI, APP_ENV=testing e flags explícitas
- Escopo RLS PostgreSQL18 com papel SQL não-superuser / não-BYPASSRLS
- Registro de grant GSC readonly, tenant/ator/fingerprint corretos,
  status ativo, sem revogação, verificação com no máximo 900 segundos
- Candidato cifrado XChaCha20-Poly1305, mesmo tenant/ator/fingerprint,
  ainda não revogado, válido no momento informado e com escopo readonly
- As duas consultas sob a mesma transação PostgreSQL por tenant
- Saída sem token, URL, código OAuth, verifier PKCE, sessão ou identidade;
  flags de Google, produção, autorização e exibição sempre falsas

A ausência de qualquer dado, autorização registrada expirada ou
revogação F114 causa recusa genérica (null), sem expor a origem.

## Testes

A suíte executa em PHP 8.3 e PHP 8.4 sobre PostgreSQL 18 descartável,
revalidando F108–F114 antes da F115.

Verifica também: segunda empresa preservada, fingerprint/domain vs.
URL-prefix, ator trocado, grant ausente, candidato ausente/expirado,
grant desatualizado, candidato revogado individualmente, grant e
candidato revogados conjuntamente com auditoria, campos de privilégio
forjados, nenhum token na resposta e liberação do contexto RLS.

## Limites que impedem a produção

Esta é uma **revisão de coerência do banco**. O token não é enviado para
a Google e o resultado não afirma origem OAuth, sessão Portal confiável,
MFA real, permissão ativa da propriedade no Google, nem autorização
para o cliente visualizar dados.

A transação utiliza consultas de leitura sem lock exclusivo; uma
revogação concorrente pode ocorrer após a observação. Por isso,
a F115 **não fornece uma capacidade para usar tokens**. Um serviço
real precisará revalidar identidade, consentimento, titularidade
e estado do grant no momento exato do acesso remoto, com uma estratégia
de serialização aprovada.

Permanecem necessários: fluxo de login/MFA operacional, OAuth Google
readonly com endpoints HTTPS/TLS verificados, armazenamento de
segredos fora do GitHub, política de rotação/revogação remota,
isolamento/SQLi, testes de carga, LGPD/AppSec/DBA, backup/rollback
e homologação na VPS antes de publicar.

CI: .github/workflows/portal-seo-f115-token-review-pg18-ci.yml

Sem alteração de main, DNS, VPS, faturamento ou dados de clientes.
