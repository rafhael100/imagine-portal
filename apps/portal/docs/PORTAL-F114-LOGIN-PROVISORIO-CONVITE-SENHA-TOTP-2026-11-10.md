# PORTAL-F114 — Sessão provisória para vínculo convidado (senha + TOTP)

**Data:** 10/11/2026. **Situação:** classe e testes de laboratório; **nenhum login de cliente**.

## Lacuna de produto

A F113 impediu que uma sessão de aceite de convite se transforme indevidamente em sessão de negócio. Entretanto, a prova anterior de emissão de sessão por senha (F82) exigia `membership.status='active'`, ao passo que a pré-verificação de aceite F112 exige `membership.status='invited'`. Não existia fluxo capaz de criar legitimamente uma sessão provisória de um vínculo ainda convidado com senha e MFA já cadastrados.

## Incremento implementado

A classe `labs/identity-f114/PortalF114InvitedSessionIssuer.php`, **fora do autoload runtime e da imagem Docker**, implementa um fluxo PHP→PostgreSQL executável apenas em banco descartável:

1. `begin(email, password, organization, trustedSourceIp, serverPepper)`: exige ambiente `testing` e `PORTAL_F114_PG_LAB=1`, conexão de identidade de laboratório `postgres`, conta de throttling `portal_f77_throttler` e IP validado. Faz claim F77 de limite de tentativas **antes da senha**, com HMAC dependente de email, empresa, IP e segredo do servidor, sem gravar esses dados em claro no bucket.
2. Dentro de transação, bloqueia linhas de identidade, empresa e membership, verifica hash Argon2id com `PortalPasswordCredential`, e-mail e TOTP já confirmados, segredo TOTP criptografado, identidade ativa, empresa ativa e **membership convidada**. Recolhe revisões atuais.
3. Emite **somente uma referência provisória aleatória Base64URL de 256 bits**, armazenando apenas `session_sha256`, propósito imutável `invitation_only`, três revisões e status `pending_mfa`, com **TTL de 5 minutos**. Nenhuma concessão ou identidade autenticada é retornada.
4. `complete(pendingBearer, serverEncryptionKey, totpCode, trustedSourceIp, serverPepper)`: aplica throttle F77 de `totp`, verifica segredo TOTP criptografado **da mesma identidade bloqueada**, checa sessão, revisões e estados de vínculo em uma transação, usa o relógio PostgreSQL para correspondência do TOTP e grava `mfa_last_used_counter` monotônico por compare-and-swap. Só então altera status da sessão provisória para `active` e registra `mfa_verified_at`.
5. O próximo consumo de convite usará a barreira transacional F108 e auditoria F107, mas **essa chamada não é executada pela classe F114**. A fonte F112 pode fornecer evidência de sessão provisória à F110; a função F113 continua negando negócio até emissão de **nova sessão `business`**, separada do token provisório.

## Provas automatizadas

`labs/identity-f114/test_pg_invited_password_mfa_session.php` executa em PHP 8.3/8.4 e PostgreSQL 18 descartável: senha Argon2id falsa/verdadeira; empresa errada; IP e pepper inválidos; `pending_mfa` sem autoridade; bearer de alta entropia armazenado só como hash; TOTP errado; chave de descriptografia errada; TOTP correto usando segredo cadastrado; emissão de evidência F112 sem autoridade HTTP real; recusa de repetição de código TOTP em outra sessão via counter monotônico; revogação lógica de permissões de negócio após ativação de membership **sem aumentar revisão**; bloqueio da sexta tentativa F77; e negação quando se troca `APP_ENV` para produção ou desabilita o flag do laboratório.

O workflow F114 também executa regressão F113, aplica somente migrations de laboratório F66/F70/F76/F77/F113 e funções temporárias F76/F77/F112/F113; os dados são fictícios e o teardown remove todos os papéis, funções, tabelas e migrations. No PR reexecutam ainda testes Laravel, gate estático e smoke Docker privado.

## Bloqueios antes de uma versão operacional

- A classe **usa superuser `postgres` no ensaio** para escrever nas tabelas fechadas. Nenhum adaptador de produção deve usar esse papel: é obrigatório implementar roles/policies por empresa, KMS, issuer auditável, controles transacionais e threat model aprovado por DBA/AppSec independente.
- O cadastro inicial de senha, verificação de e-mail, enrolamento do primeiro TOTP, recuperação de conta e envio de convite não são implementados aqui. O teste usa uma identidade fictícia **já verificada e com MFA cadastrado**.
- O IP de origem é fornecido explicitamente pelo runner; um fluxo real exige proxy autenticado, rate limit contra distribuições de IP e monitoramento de abuso, além de proteção CSRF, sessão/cookie HTTPS, anti-phishing e proteção de segredos.
- Para conceder serviços, o aceite futuro deve confirmar ownership, consumir convite com auditoria F107/F108, ativar membership e **emitir nova sessão business**. Nenhum cookie `invitation_only` pode se converter automaticamente.
- Fonte F112 e F114 continuam exclusivas de `labs/`, não vinculadas ao Laravel; a implementação padrão F110 nega identidade. `main`, VPS, clientes, CRM e produção inalterados.

**DRAFT / NO-GO — sem nova despesa de fornecedores.**
