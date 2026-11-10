# PORTAL-F115 — Confirmação de e-mail por desafio de uso único (laboratório)

**Data:** 10/11/2026. **Estado:** desenvolvimento e testes isolados em PostgreSQL 18. **NO-GO de produção.**

## Problema de produto

A F114 demonstrou senha Argon2id + TOTP para uma identidade que já possuía e-mail confirmado e MFA cadastrado. Faltava um mecanismo anterior para representar um desafio de comprovação do endereço e impedir reutilização ou confirmação do endereço errado. Esta entrega **não envia mensagens**, não abre cadastros e não prova titularidade de uma caixa postal real.

## Incremento implementado

A migração `2026_11_10_000009_create_portal_email_verification_challenges.php` cria uma tabela exclusivamente Portal com:

- `token_sha256` de um token aleatório de 256 bits, com domínio criptográfico exclusivo `IMAGINE-PORTAL-EMAIL-VERIFY:v1`; não se reutiliza o token nem o digest de convite ou de sessão;
- FK para `portal_identities`, cópia da revisão de segurança e SHA-256 do endereço normalizado no momento da emissão;
- prazo máximo estrutural de **30 minutos**, estado `pending/consumed/revoked` e timestamps coerentes;
- índice único para apenas um desafio pendente por identidade (inclusive se expirou sem revogação explícita);
- `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY`, sem nenhuma policy ou grant da aplicação no baseline.

`PortalEmailVerificationSecret` cria e revela o token no máximo uma vez, valida formato Base64URL canônico, separa o hash do de outros serviços e impede serialização, reconstrução ou exposição via `print_r`/`var_export` de suas propriedades de instância. Ela **não** registra, envia, valida e-mail real ou autoriza login.

## Prova de consumo atômico

`labs/identity-f115/atomic_email_confirmation.sql` instala somente em PostgreSQL descartável uma função `portal_f115_confirm_email(digest, identity_id)`:

1. Um papel `NOLOGIN/NOSUPERUSER/NOBYPASSRLS` tem as permissões mínimas de leitura e atualização de colunas para executar a operação sob policies RLS exclusivas desse papel; um segundo papel `EXECUTE-only` não pode ler/escrever tabelas.
2. A função bloqueia **primeiro a linha da identidade e depois a do desafio**; exige identidade ainda `invited`, sem verificação prévia, revisão igual, digest e identidade associados, prazo válido e hash do endereço atual igual ao capturado na emissão.
3. Atualiza `email_verified_at` e `status='consumed'` do desafio **na mesma transação**. Falha no segundo UPDATE desfaz o primeiro, nunca deixando um e-mail confirmado com desafio pendente.
4. Não altera `identity.status`, não ativa membership, não emite sessões, não cadastra senha/MFA e não concede permissões.

A função recebe o **hash bearer-equivalente** do desafio e, portanto, não pode ser disponibilizada diretamente em HTTP, para navegadores, CRM, terceiros ou logs. O envio real deverá usar infraestrutura confiável com proteção antiabuso, sem expor material em analytics/URLs ou respostas de erro. O SHA-256 do e-mail é **reidentificável via dicionário** e permanece dado pessoal no banco de segurança; não é material anonimizado.

## Testes adversariais

A suíte `test_pg_atomic_email_confirmation.php` executa com duas conexões PostgreSQL e verifica: isolamento de domínio, desafio inválido, identidade errada, token repetido, disputa com lock e um único commit, rollback, alteração do endereço, revisão de segurança desatualizada, expiração, revogação e desafio futuro, limite de 30 minutos, falha da policy de atualização como gatilho de rollback, RLS e privilégios mínimos. Ela confirma que **nenhuma** sessão, permissão ou ativação de identidade/vínculo foi criada.

Os testes de unidade `PortalEmailVerificationSecretTest` validam token canônico, entropy por `random_bytes`, entrega única, separação do digest e redaction. O workflow F115 roda PHP 8.3/8.4 + PostgreSQL 18 e remove roles, policies, função e tabelas no final.

## Bloqueios antes de liberar o cadastro

Ainda faltam emissor confiável e entrega de e-mail com domínio controlado, proteção contra transferência de token, criação inicial segura de identidade, política de troca de endereço, ativação da identidade, password enrollment, MFA enrollment KMS com prova TOTP, recuperação e remoção, rate limiting no serviço de cadastro, auditoria externa SEC-R03 e revisão independente AppSec/DBA/LGPD. Toda regra de acesso continua **deny-by-default** e somente `/up` é rota HTTP.

O CI usa papéis de banco e emails `.invalid` descartáveis. **Sem envio real, ativação de cliente, conexão CRM, merge em `main`, deploy na VPS ou custo novo. PR draft / NO-GO.**
