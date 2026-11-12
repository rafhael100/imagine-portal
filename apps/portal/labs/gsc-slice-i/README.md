# GSC Slice I — Ledger PostgreSQL de state OAuth com consumo único

Esta entrega substitui **somente o armazenamento temporário futuro** de PKCE por um adaptador real, parametrizado, com PostgreSQL. Nenhuma rota, Google HTTP, login real, troca de tokens, uso em produção ou atualização de credenciais ocorre nesta branch.

## Implementação

`GoogleSearchConsoleOAuthStateLedger`:
- Aceita o registro secreto **serverOnly** criado pelo PKCE do Lote G, com campos exatos, tenant/ator sintaticamente válidos, digest SHA-256, verifier de 43 caracteres e TTL **exatamente 600 segundos**.
- Criptografa o verifier antes de persistir usando **libsodium XChaCha20-Poly1305 AEAD**, com nonce aleatório e dados autenticados (state hash + tenant + ator + expiração), vinculando o segredo criptograficamente à linha e rejeitando ciphertext trocado entre empresas/estados.
- Exige uma **chave aleatória de 32 bytes**, recebida por parâmetro de backend confiável. Não há chave, token ou segredo nos arquivos; a aplicação real deverá usar um cofre de segredos com rotação e escopo de acesso definidos antes da promoção.
- `store`: `INSERT ... ON CONFLICT DO NOTHING` com SHA de state globalmente único; duplicatas falham.
- `inspectPending`: consulta somente registro válido, não consumido e relacionado ao contexto atual. O registro devolvido é **segredo somente de servidor** e nunca pode ser enviado à sessão web/JSON/logs.
- `consume`: um único `UPDATE ... WHERE consumed_at IS NULL AND expires_at>=now ... RETURNING` parametrizado. Duas instâncias de PHP com conexões independentes não podem consumir o mesmo registro com sucesso. O resultado é um objeto opaco `GoogleSearchConsoleOAuthConsumedVerifier`, sem campos JSON públicos ou debug contendo o verificador.
- Erro de chave, adulteração de nonce/ciphertext, troca de linhas, tempo expirado ou contexto cruzado falham fechados. Caso o registro seja consumido e o segredo não possa ser decifrado, **permanece consumido**, não retornando a estado pendente.

## Banco e testes

A migração **ainda não aplicada** `database/migrations/2026_11_12_000001_create_portal_gsc_oauth_states.php` cria tabela com chave de state, campos necessários e restrições para TTL, ciphertext e tempo de consumo. Exige PostgreSQL. Nenhum refresh token ou consulta GSC é salvo na tabela.

O laboratório `gsc-slice-i` cria **somente em banco descartável `imagine_gsc_ci_test`** a estrutura SQL de teste e valida inserção, duplicidade, expiração, acesso cross-tenant, uso único por duas conexões, integridade AEAD e tentativas de falsificação. Ambiente obrigatório `APP_ENV=testing`, `PORTAL_GSC_POSTGRES_LAB=1`; o DSN é fixo em host local e banco de testes. A CI deve verificar também a migração Laravel no mesmo PostgreSQL isolado.

## Bloqueios

Esta implementação **ainda não conecta** o controller/callback de clientes: falta sessão MFA/RBAC por tenant, cofre de chave com rotação e proteção de falhas, confirmação de registro de URI no Google, troca OAuth HTTPS com tokens e escopos verificados, revogação, política de retenção/purga e homologação. A existência de um método `consume` não concede autorização de uso ou envio de segredo a terceiros.

**DRAFT/NO-GO**: sem merge dev/main, aplicação de migration real, deploy, DNS/301, custo ou dados de clientes. Revisão independente AppSec, DBA e LGPD obrigatória.
