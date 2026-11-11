# GSC-F133 — Verificação transacional de sessão, concessão e propriedade no PostgreSQL

## O que foi implementado

A classe `GoogleSearchConsoleIamWitness` é o primeiro leitor PHP do Imagine SEO que consulta a **estrutura PostgreSQL real** do Portal para obter uma evidência transacional de sessão e permissão, em vez de usar somente objetos de domínio construídos em memória.

Ela recebe exclusivamente referências do **lado do servidor**: um SHA-256 de sessão (não o bearer cookie), o UUID da organização e um `siteUrl` que passa pelo validador exato F120. Com parâmetros SQL preparados, realiza **uma consulta de seis tabelas**:

1. `portal_auth_sessions`: sessão ativa, não revogada, dentro da validade, MFA na própria sessão e revisões de identidade, empresa e membership atualizadas.
2. `portal_identities`: identidade ativa, e-mail verificado e MFA confirmado.
3. `portal_organizations`: empresa ativa.
4. `portal_memberships`: membro ativo vinculado à identidade e empresa da sessão.
5. `portal_membership_grants`: concessão explícita `seo.gsc.connect`, sem herança automática de função owner/manager.
6. `portal_gsc_property_candidates`: candidato de propriedade vinculado **à mesma empresa** com SHA-256 `siteUrl` exatamente igual, estado candidato e sem revogação.

A consulta roda **somente dentro de transação iniciada pelo chamador**, usa `FOR SHARE OF` sobre as seis tabelas para bloquear revogações concorrentes até o commit e recusa sessão/empresa/hash incorretos ou problemas SQL sem expor detalhes de identidade. O testador informa o resultado como `syntheticDatabaseRowsMatch`; `canStartOAuth()`, `canReadGoogle()` e `canIssueOrStoreTokens()` são **sempre false**.

## O que os testes verificam

O teste `labs/gsc-f133/test_pg_iam_witness.php` aplica esse componente à cadeia real das migrations F66/F76/F113/F123–F126 no mesmo banco **PostgreSQL 18 descartável**, em PHP 8.3/8.4. Insere um registro fictício de sessão Portal MFA e usa a concessão sintética criada pela F130.

Além dos testes positivos de correspondência de linhas, verifica bloqueio após revogação de sessão, identidade, empresa, membership, grant, propriedade, retirada do MFA e divergência de revisões. Um segundo usuário SQL sem privilégio de bypass RLS recebe **nenhuma sessão** apesar de GRANT SELECT e não obtém evidência positiva. Outro cliente de banco tenta revogar a sessão enquanto o leitor segura `FOR SHARE`, devendo receber timeout de lock. Após a transação terminar e a revogação ser confirmada, um novo leitor obtém negação. As fixtures de sessão são removidas para não interferir nos testes de rollback da F130.

## Limites que NÃO podem ser confundidos com uma integração pronta

- O cenário positivo roda com **administrador PostgreSQL no banco sintético** para provar o predicado; isso **não** demonstra acesso autorizado por um papel real de produção com RLS. A role comum continua sem políticas RLS e recebe negação.
- A classe é **deliberadamente não registrada** no `AppServiceProvider`, não é uma implementação da interface operacional `PortalSessionAuthority` e não emite sessão, OAuth state, PKCE nem token. As autoridades Laravel permanecem default-deny e as rotas Google continuam 404.
- Um registro `portal_gsc_property_candidates` **não comprova titularidade Google**; `siteUrl` no banco só mostra candidatura. Necessários consentimento, busca autenticada `sites.list`, revisão do nível de permissão, cliente Google próprio e cofre aprovado.
- O lock do banco evita apenas determinados cenários concorrentes **durante a transação**. Não protege uma futura chamada externa posterior ao commit nem dispensa revisão independente de deadlocks, roles, RLS, timeout, LGPD, KMS e revogação real de tokens.

**HOLD:** antes de habilitar qualquer endpoint, construir emissão de sessão first-party com MFA e políticas RLS/SQL aprovadas por AppSec/DBA independentes, homologar VPS e validar propriedade Google sob token seguro. Não houve merge `main`, migração na VPS, integração Google, custo novo ou deploy.
