# Portal SEO F110 — PKCE OAuth sob RLS PostgreSQL 18 (laboratório)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continuação da
[F109 / PR #846](https://github.com/rafhael100/imagine-portal/pull/846).

## O que este recorte executa

O Portal já tinha geração PKCE/state, ledger criptografado e callback
classificado como não autorizador. Agora a F110 encapsula gravação
e consumo dos estados em transações tenant RLS no PostgreSQL, usando:

- GoogleSearchConsoleOAuthPreflight: gera state e verifier sintéticos
- GoogleSearchConsoleOAuthStateLedger: cifra verifier com
  XChaCha20-Poly1305 e AAD vinculada a state, tenant, ator e expiração
- GoogleSearchConsoleTenantScopedTransaction: app.tenant_ref local à
  transação e recusa superuser/BYPASSRLS
- GoogleSearchConsoleOAuthCallbackCoordinator: classifica callback e
  consome o state por UPDATE RETURNING, exatamente uma vez
- GoogleSearchConsoleOAuthRlsLabBoundary: combina os contratos e
  devolve apenas informações sanitizadas, sem code, verifier ou token

## Testes em PostgreSQL 18 real no CI

A suíte F110 roda depois de F108 e F109 no mesmo container descartável,
na matriz PHP 8.3/8.4:

1. FORCE RLS ativo e role não-superusuária
2. Inserção única; duplicatas/contextos adulterados rejeitados
3. Verifier persistido em ciphertext e não exibido
4. Tenant/ator distintos não conseguem consumir state de outro contexto
5. Scope OAuth adulterado não consome o state
6. Callback correto consome state uma única vez; somente review safe
7. Replay na segunda conexão falha
8. Consentimento negado consome state e bloqueia utilização posterior
9. State expirado não é consumido
10. Scope RLS não persiste após COMMIT/ROLLBACK; produção bloqueada

As chaves AEAD são aleatórias por job e as senhas visíveis só servem
ao PostgreSQL descartável. Nenhuma credencial real é empregada.

## Limitações e gates

**Esta PR não cria login operacional nem comprova MFA.** O contexto
tenant/actor ainda depende de um emissor confiável do Portal. Não há
acesso à Google, troca de tokens HTTP, verificação da conta Google ou
titularidade da propriedade, persistência de tokens de produção, acesso
a clientes ou ativação de dashboards.

Classe restrita a PHP CLI, APP_ENV=testing, CI=true e
PORTAL_SEO_F110_PG_CI=1. Sem HTTP, migrações novas, alteração de main,
VPS ou deploy. Um resultado positivo significa apenas state consumido
no banco do laboratório; não concede autorização de leitura.

RLS por variável app.tenant_ref não autentica usuário nem impede
SQL injection; essa barreira depende também de identidade real,
membership atualizado, política de roles, revisão AppSec/DBA/LGPD,
MFA, grants Google efetivos, consentimento, testes HTTP/TLS e
backup/rollback em homologação.

Visitas, Leads e Clientes permanecem indisponíveis sem analytics/CRM
autenticados, eventos confiáveis e atribuição autorizada.
