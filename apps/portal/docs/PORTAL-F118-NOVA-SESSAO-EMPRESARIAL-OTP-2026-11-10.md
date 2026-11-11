# PORTAL-F118 — Nova sessão empresarial após aceite auditado

**Data:** 10/11/2026. **Estado:** laboratório PostgreSQL 18 + PHP 8.3/8.4, **NO-GO de produção**.

## Motivo

A F117 ativou o vínculo convidado, incrementou a revisão de segurança, consumiu convite e revogou a sessão provisória na mesma transação auditada. Essa etapa **não concedia uma credencial empresarial**. É um limite deliberado: converter o token `invitation_only` original em `business` permitiria elevação indevida de privilégio, em desacordo com a finalidade imutável da F113.

## Incremento

`labs/identity-f118/PortalF118BusinessSessionIssuer.php` introduz duas etapas de **login empresarial novamente autenticado**, exclusivamente no runner descartável:

- `begin(email, password, organization, trustedSourceIp, pepper)`: verifica ambiente `CLI+testing`, PDO da fixture `postgres` e conexão de throttle `portal_f77_throttler`; exige IP e segredo de servidor válidos e reclama uma tentativa F77 **antes de verificar a senha**. Faz leitura com bloqueios de identidade, vínculo e organização; exige todos ativos, e-mail e MFA previamente confirmados, senha Argon2id válida e revisões positivas. Emite referência Base64URL aleatória de **256 bits**, armazenando apenas `session_sha256`, `session_purpose='business'`, revisões atuais e estado `pending_mfa` com 5 minutos para terminar a segunda etapa. A referência **não é autenticada** até o MFA.
- `complete(pendingBearer, encryptionKey, totpCode, trustedSourceIp, pepper)`: executa throttling F77, consulta sob bloqueio a sessão empresarial provisória e o segredo TOTP cifrado **da própria identidade**, verifica estado, finalidade, limites temporais e revisões, decifra o envelope de teste `mfa-sb1`, compara o TOTP com contador anti-replay persistido e, dentro da mesma transação, atualiza o contador monotônico e promove a sessão para `active` com validade máxima de **8 horas a partir da emissão**. Não gera novos grants.
- A classe não implementa qualquer rota, middleware, cookie ou autoridade operacional. `mayIssueCustomerSession()` retorna falso. O Login Laravel F98 continua deny-all e `/up` segue a única rota.

## Prova de integração

`labs/identity-f118/test_pg_new_business_session.php` inicia com um **vínculo realmente ativado pela suíte F117**, com a antiga sessão de convite revogada e revisão de membership incrementada. Um fixture administrativo adiciona senha Argon2id e TOTP cifrado para o teste de nova autenticação. Testa senha errada, empresa estrangeira, IP/pepper inválidos, ausência de sessão antes do login, sessão `business` pendente sem concessões, MFA errado/chave errada/replay CRM, primeira confirmação correta e recusa de segundo uso do mesmo código. O token empresarial é diferente do token de convite e recebe finalidade `business` desde o `INSERT`.

O teste também demonstra que:

- Mesmo após senha + MFA, **sem um grant explícito** a função F113 recusa `services.view`.
- Um grant sintético de `services.view` habilita somente essa permissão para a sessão empresarial nova; `billing.view` continua negada.
- O token anterior `invitation_only`, revogado pela F117, jamais passa a autorizar serviços após a ativação e a criação de grants.
- Uma segunda sessão não pode reutilizar o mesmo TOTP (contador monotônico), alterações nas revisões de organização/membership invalidam a autorização, e revogação explícita da nova sessão encerra o acesso.
- F77 bloqueia a sexta tentativa de senha, mesmo se correta, e `APP_ENV=production`/flag desligada recusam a execução do emissor.

O workflow `portal-f118-fresh-business-session-ci.yml` roda a cadeia F115→F116→F113→F114→F117→F118 em PHP 8.3/8.4 com PostgreSQL 18 descartável e devolve as tabelas ao estado fechado. O PR também passa por Laravel, testes gerais e smoke Docker privado.

## Fronteiras pendentes

Este é **somente um ensaio de origem e validade de registros**, não um login de cliente. A conexão administradora `postgres` é **exclusivamente da CI**; um emissor real precisa de papéis de escrita mínimos, políticas RLS multiempresa verificadas, serviço de identidade primeira-parte, cookies HTTPS `__Host-`, CSRF, KMS/rotação, recuperação, antiabuso distribuído e autenticação segura de origem do IP. O cheque F76 usado no laboratório é `SECURITY DEFINER` de superuser do runner e exige substituição/revisão DBA independente.

As policies `USING(true)` de laboratório não são RLS operacional. Revisão externa AppSec, DBA/LGPD, SEC-R03 (testemunho antirollback), homologação segura da VPS e aceitação formal são requisitos antes de permitir qualquer rota ou conta real.

Sem envio real de e-mail, cliente, CRM, Ads, domínio, deploy na VPS, merge na `main` ou fornecedores com novos custos. **PR draft / NO-GO.**
