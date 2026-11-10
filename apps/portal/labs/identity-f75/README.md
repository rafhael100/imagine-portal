# PORTAL-F75 — MFA com segredo cifrado da própria identidade (laboratório PostgreSQL)

## Problema corrigido

Na prova F71/F73, o teste SQL recebia o segredo Base32 TOTP do chamador, e opcionalmente comparava um envelope cifrado fornecido pelo teste. Isso **não garantia que o OTP fosse verificado contra o segredo efetivamente matriculado na identidade**. A F75 torna essa verificação determinística: consulta `portal_identities.mfa_secret_ciphertext` sob o mesmo bloqueio PostgreSQL da sessão e dos vínculos e **decifra o envelope persistido**, usando uma chave exclusiva do servidor de testes. A assinatura de `complete` da F75 não recebe segredo TOTP em texto.

## Implementação

O novo `App\\Labs\\IdentityF75\\PortalEnrolledMfaSessionCompleter`:

1. Só executa em `PHP_SAPI=cli`, `APP_ENV=testing`, `PORTAL_F75_PG_LAB=1` e PostgreSQL descartável `portal_identity_lab`, com role de teste explicitamente verificada.
2. Localiza sessão a partir de hash SHA-256 do bearer sintético, bloqueia sessão/identidade/vínculo/organização no mesmo `SELECT ... FOR UPDATE`.
3. Confirma sessões `pending_mfa`, status ativos, revisões e vencimento antes de qualquer processamento do código.
4. Obtém **somente do banco** o envelope `mfa-sb1`, decifra com `PortalTotpSecretCipher::open` e recusa tampering, chave errada, segredo ausente ou versão inválida.
5. Executa verificação RFC6238 do TOTP desencriptado, destrói a variável local quando disponível e faz CAS do contador e promoção da sessão numa única transação; caso contrário, rollback.
6. Nunca registra Provider, middleware, rota, autenticação ou sessão de cliente.

A suíte PG18 PHP 8.3/8.4 verifica chaves erradas, ciphertext adulterado, rotação de enrollment, OTP incorreto, replay entre duas conexões, counter monotônico, revogação da membership, revisão de segurança, MFA desconfirmado, identidade e bearer inválidos, RLS default-deny e que o segredo não existe em texto no banco.

## Limitações e gates ainda não satisfeitos

- Chave `sodium secretbox` é gerada no CI, **não há KMS, rotação, verificação de identidade de cliente nem provisionamento operacional do TOTP**.
- A versão do banco/contador pode retroceder em um restore sem testemunha externa SEC-R03.
- **A rotina continua restrita a superuser do banco descartável**; não pode ser usada em runtime. App role não-superuser com privilégios verificados, vault, gestão de chaves, auditoria AppSec e LGPD são exigidos antes de qualquer uso.
- Ainda não há sessão web real, rate limiting, senha/login, CSRF, consentimento comercial, interface de administração ou autorização de clientes.
- Nenhum deploy, merge, infraestrutura, domínio, Ads, CRM ou custo é realizado.

**Status:** PR em rascunho, laboratório não operacional; `CI PASS` significa somente que a falha de confiar em segredo informado por chamador foi removida na prova de integração e que os cenários sintéticos foram verificados.
