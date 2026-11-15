# GSC Slice N — prova integrada do fluxo OAuth em PostgreSQL descartável

O laboratório N faz uma verificação **única de ponta a ponta**, usando os módulos G–M já implementados, sem contas Google ou dados reais:

1. O preflight G cria `state` e PKCE S256 com escopo `webmasters.readonly`.
2. O Ledger I grava apenas digest de state e verifier cifrado com XChaCha20-Poly1305 em PostgreSQL descartável.
3. O coordenador H/J verifica uma callback sintética, o contexto de empresa e operador e consome o state **uma única vez**. Tentativas de replay são recusadas.
4. O planner L prepara o POST fixo para `https://oauth2.googleapis.com/token` usando o mesmo código e `code_verifier`, sem realizar HTTP.
5. O parser M examina **somente uma resposta JSON fictícia**, exige escopo readonly e recusa ampliação de permissões ou status HTTP de erro.
6. A retenção K remove o registro consumido sem deixar o verificador em claro ou expor código, access token ou refresh token em saídas destinadas ao cliente.

O teste verifica que os flags de identidade Google, grant por tenant, persistência de token, exibição e publicação permanecem **false**, mesmo quando toda a sequência sintética funciona.

## Significado do teste

Prova compatibilidade dos contratos e estados de erro **no mesmo processo PHP e no PostgreSQL real do CI**. Não prova que o cliente OAuth esteja registrado no Google Cloud, que o Portal tenha sessão MFA/RBAC real, que o token seja válido, que exista transporte TLS operacional ou que a propriedade GSC seja do cliente. Dados podem estar ausentes/anônimos no Search Console.

## Bloqueios de produção

Faltam o backend autenticado de usuários/empresas e grants, cofre de chaves/refresh tokens, implementação HTTPS com pinagem de destino e validação TLS, verificação independente de escopo, quota, revogação, observabilidade e homologação staging. **Nenhum token fictício é persistido nesta entrega**, e nenhuma API foi chamada.

**DRAFT/NO-GO:** sem merge em `dev/main`, deploy, DNS/301 ou cadastro Google real. Revisão externa AppSec/DBA/LGPD ainda necessária.
