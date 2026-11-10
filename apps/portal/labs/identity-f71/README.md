# PORTAL-F71 — prova transacional de MFA e ativação de sessão em PostgreSQL

Continuação técnica da F66 (identidade Portal-only), F69 (TOTP RFC6238) e F70 (contador anti-replay).

## Implementação real em laboratório

`App\\Labs\\IdentityF71\\PortalSyntheticMfaSessionCompleter` opera apenas no PostgreSQL 18 descartável e recebe um bearer fictício de alta entropia, um segredo TOTP **fictício**, um código de seis dígitos e relógio de teste. Busca a sessão exclusivamente por `SHA-256(bearer)` e mantém bloqueadas, durante uma transação, as linhas de sessão, identidade, organização e vínculo. Confere:

- sessão `pending_mfa` ainda vigente, revisão de segurança da identidade e revisão de membership coerentes;
- identidade, organização e membership ativas, MFA previamente confirmado na identidade;
- TOTP corresponde a um intervalo ainda não consumido;
- `UPDATE` da identidade só grava contador estritamente superior; `UPDATE` da sessão muda de `pending_mfa` para `active`, com horário de MFA, **na mesma transação**.

Se qualquer etapa falhar, faz rollback: a sessão não pode ficar ativa com um contador não persistido, nem consumir o código sem a ativação correspondente. A função retorna somente um booleano de sucesso **sintético** — não entrega cookie, token de cliente, API nem permissão comercial.

## CI

`.github/workflows/portal-f71-mfa-atomic-session-ci.yml` exercita PHP 8.3/8.4 e PG18 efêmero. Primeiro executa as provas F66 e F70, depois testes F71 de senha opaca incorreta, OTP inválido, ativação e persistência atômicas, replay na mesma sessão e entre duas sessões, contador posterior, revogação/versão de identidade ou membership, remoção do cadastro MFA, expiração e conflito de lock, e rollback de todas as migrações testadas.

## Limitações de segurança (não permitir produção)

- O segredo TOTP é fornecido pelo **teste**, não obtido de uma fonte criptografada e autenticada do usuário; o relógio também é arbitrário do teste.
- O papel PostgreSQL superuser é exclusivo do runner. Nenhum produto deve usar este papel nem promover a classe de laboratório a ServiceProvider, Job ou rota.
- Não existem anti-brute-force, limite de tentativas, CSRF, recuperação de conta, TOTP provisioning/rotação, gestão de segredos, histórico/auditoria operacional, nem policy RLS de app verificada.
- O estado `active` da fixture é apenas um valor do banco descartável. **Nenhuma sessão de cliente foi autenticada**; não equivale à concessão de autorização.
- Restore do PostgreSQL pode retroceder counter/revisões e reativar sessões antigas. O witness externo SEC-R03/Z4 continua bloqueante para produção.

**PR DRAFT. Sem merge, deploy, VPS, credenciais/cliente, contas Google/Meta, DNS ou custos novos.**
