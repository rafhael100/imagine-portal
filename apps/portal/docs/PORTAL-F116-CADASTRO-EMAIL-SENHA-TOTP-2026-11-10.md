# PORTAL-F116 — Cadastro sintético de identidade: e-mail → senha → TOTP → sessão provisória

**Data:** 10/11/2026. **Estado:** laboratório PHP + PostgreSQL 18 descartável, **NO-GO para clientes**.

## Qual lacuna esta entrega fecha

As F115 e F114 eram provas separadas. A F115 podia marcar um e-mail verificado mediante um desafio de uso único, mas não cadastrava credenciais. A F114 só iniciava autenticação provisória de identidades já ativas com senha Argon2id e MFA previamente cadastrados. A F116 acrescenta **a integração executável entre esses estados**.

## Fluxo construído

`labs/identity-f116/PortalF116EmailPasswordTotpEnrollment.php` é um serviço PHP limitado a `CLI`, `APP_ENV=testing`, `PORTAL_F116_PG_LAB=1`, localhost e banco `portal_identity_lab`. Exige conexão administrativa sintética de CI e a conexão separada F77 de limitação de tentativas. A implementação não faz parte de `app/`, não recebe rota Laravel e não integra o runtime Docker.

1. A pessoa fictícia começa com `portal_identities.status=invited`, sem senha, e-mail não verificado nem MFA. Uma função F115 consome um desafio de e-mail e grava `email_verified_at`, mantendo o status `invited`.
2. `begin(emailBearer, identityId, password, serverEncryptionKey)` exige o **mesmo token de e-mail consumido**, correspondente ao UUID, ao e-mail normalizado e à revisão de segurança atuais, com `email_verified_at` igual ao instante de consumo e no máximo **10 minutos** após confirmação.
3. Verifica que a identidade continua convidada, sem senha, sem segredo MFA e com contador inicial `-1`; gera hash Argon2id, produz internamente **160 bits aleatórios de TOTP** codificados em Base32 e os armazena no envelope `mfa-sb1` criptografado com chave exclusiva do runner. Devolve o segredo Base32 apenas à fronteira confiável do teste para configuração do aplicativo autenticador. **Não ativa a identidade**.
4. `complete(emailBearer, identityId, otpCode, serverEncryptionKey, trustedSourceIp, pepper)` valida o mesmo comprovante recente, aplica throttling F77 (até 5 tentativas por janela de 15 minutos e escopo HMAC), consulta o segredo cifrado **da identidade bloqueada** e compara o TOTP RFC6238 sob o relógio PostgreSQL.
5. Apenas se o código corresponder ao segredo cadastrado: grava o contador monotônico anti-replay, `mfa_confirmed_at`, promove `identity.status=active` e **incrementa `security_revision`** na mesma transação. O vínculo empresarial permanece `invited`, sem acesso ou sessão.
6. A F114 pode então criar uma sessão `pending_mfa` de finalidade imutável `invitation_only`; com uma **nova janela TOTP**, a sessão pode ser confirmada e usada apenas como evidência sintética F112/F110. Não existe emissão de credencial `business`, ativação de membership ou autorização HTTP.

## Provas adversariais

`labs/identity-f116/test_pg_email_password_totp_onboarding.php` verifica ponta a ponta: desafio de e-mail ainda pendente ou de outro domínio não libera cadastro; identidade errada, senha curta e chave ruim não gravam credenciais; segredos TOTP nunca ficam em claro na tabela; segunda inicialização é recusada; código inicial inválido, chave errada e reutilização de código não ativam identidade; somente TOTP correto ativa e incrementa revisão. Confirma ainda que o vínculo continua convidado sem grants/sessões empresariais e que a F114 continua exigindo novo MFA para a sessão provisória.

O workflow `portal-f116-email-password-totp-enrollment-ci.yml` roda PHP 8.3/8.4, PostgreSQL 18 descartável, os testes F115, F113, F114 e F116, rollback integral das migrations e remoção de roles/functions/policies. O PR aciona também Laravel, gate F111 e smoke Docker privado.

## Fronteiras e riscos reais

- **O fluxo não é operacional.** A F115 ainda não envia e-mail e os tokens não foram realmente entregues à pessoa; o CI fornece o token ao teste. O serviço F116 usa conta `postgres` **apenas para a fixture**; implementar identidades reais com superuser seria inseguro.
- A reutilização temporária do token F115 após consumo como recibo de cadastro é somente um protocolo **candidato**, que precisa de threat model: idealmente o fluxo operacional emitirá um recibo distinto, com sessão de cadastro curta, nonce, vínculo ao navegador e proteção CSRF/anti-phishing.
- Não há KMS operacional, recuperação de conta, QR apresentado em interface real, email delivery, gestão de erros sem enumeração, antifraude/abuso multi-IP, controle de origem de requisição, regras PostgreSQL RLS multiempresa ou isolamento do sistema de envio.
- A promoção de identidade e a ativação da membership são **distintas**. A segunda só poderá ocorrer com aceite de convite transacional F108/F107 e emissão de sessão `business` realmente nova, após autorização do usuário/cliente.
- **Relações com dados reais, rotas HTTP, main, VPS, CRM, SSO, Ads, integrações externas e novos gastos permanecem intocados.** AppSec e DBA independentes, LGPD, auditoria SEC-R03 e homologação da VPS são gates pendentes.

**PR draft / NO-GO de produção.**
