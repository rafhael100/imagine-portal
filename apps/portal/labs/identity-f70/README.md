# PORTAL-F70 — teste de reutilização TOTP com compare-and-swap real PostgreSQL

Esta etapa parte dos verificadores de senha Argon2id/TOTP F69 e da estrutura de identidades PostgreSQL F66.

## Entrega

A migração `2026_11_09_000003_add_mfa_replay_counter_to_portal_identities.php` acrescenta `mfa_last_used_counter BIGINT NOT NULL DEFAULT -1` à identidade Portal-only, com constraint SQL. A classe `App\\Labs\\IdentityF70\\PortalMfaReplayFence` executa a instrução atômica `UPDATE ... SET mfa_last_used_counter=:counter WHERE ... AND security_revision=:revision AND status='active' AND mfa_confirmed_at IS NOT NULL AND mfa_last_used_counter<:counter RETURNING ...`. O segundo uso do mesmo contador não consegue atualizar a linha.

**Segurança de escopo:** a classe só executa em PHP CLI, `APP_ENV=testing`, banco local descartável `portal_identity_lab`, `PORTAL_F70_PG_LAB=1`. Não emite sessão. Sem secret real, conta de cliente, login ou ACL de produção. O papel superuser PostgreSQL existe **apenas dentro do runner descartável** para semear dados e testar o mecanismo; a autoridade de produto não deve usar esse papel.

## CI e regressão

`.github/workflows/portal-f70-totp-pg-cas-ci.yml` exercita PG18 + PHP 8.3/8.4. Aplica apenas as migrações F66 e F70; executa o conjunto de regras SQL F66, depois verifica em banco real:

- contador TOTP RFC 6238 reconhecido, único uso do mesmo contador, negação de replay por conexão diferente e aceitação apenas de contador superior;
- revisão de identidade alterada, MFA não confirmado, conta revogada e identificador malformado recusados;
- cliente PostgreSQL sem privilégios efetivos vê zero linhas e não altera o contador, mesmo recebendo GRANT por engano;
- lock concorrente faz timeout e falha fechada, permitindo nova tentativa somente após liberação;
- restrições do banco, uso em produção e ausência de flag negados; não abre rotas HTTP;
- rollback da coluna F70 e das cinco tabelas F66, tudo **somente no PostgreSQL descartável**.

## Limites operacionais

A verificação TOTP e o CAS SQL são componentes separados, ainda não existe um fluxo de MFA com identidade verificada, cookie de sessão, autenticação da recuperação, controle de tentativas ou provedor de chave. A migração **não** protege contra restauração de backup antigo: `mfa_last_used_counter` pode retroceder com PostgreSQL restaurado. O controle externo antirollback SEC-R03 e a testemunha independente precisam ser demonstrados antes de uso com clientes.

Não atualizar VPS, mesclar `main`/`dev`, liberar rotas, provisionar serviços ou anunciar conclusão do login com este PR. **DRAFT / só laboratório, sem despesas adicionais.**
