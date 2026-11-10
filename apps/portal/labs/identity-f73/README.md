# PORTAL-F73 — envelope cifrado de segredo TOTP, preservando o bloqueio de login

**Avanço técnico:** primeira implementação de armazenamento criptográfico do segredo MFA do próprio Portal. A F69 implementou o verificador TOTP; F70, o contador anti-replay; F71, a transação de ativação de sessão fictícia. A F73 adiciona o primitivo `PortalTotpSecretCipher` e uma proteção de frescor do ciphertext na consulta bloqueada da F71.

## Criptografia

`PortalTotpSecretCipher` usa PHP sodium `secretbox` (XSalsa20-Poly1305 com nonce aleatório de 24 bytes e chave de 32 bytes). O envelope local tem versão `mfa-sb1:`, codificação base64url canônica e prefixo de domínio interno. Sem armazenar segredo em texto simples no banco nem expor a chave no JSON. Chaves e segredos dos testes são **efêmeros**, públicos/sintéticos no caso dos vetores RFC.

Os testes unitários exercitam abertura correta com chave válida, nonce aleatório, chave diferente, modificação de bytes, prefixo ou Base32 inválidos, e negativa de concessão de login pela criptografia isolada.

## Validação da referência de segredo na transação

A F71 passa a aceitar um parâmetro **opcional de expectativa da forma cifrada** usado apenas no teste. Quando fornecido, exige correspondência exata (comparação `hash_equals`) com `portal_identities.mfa_secret_ciphertext` após bloquear as linhas da transação. Se o ciphertext tiver sido substituído por rotação, um código calculado a partir do valor anterior não deverá ativar a sessão.

**Importante:** o laboratório original F71 continua podendo receber o segredo explícito do teste quando expectativa de ciphertext é nula. Portanto, **não é um autenticador operacional** nem um fluxo completo que lê/descriptografa o cofre de chave. A autorização real exigirá que o servidor resolva o segredo sob autoridade Portal-only, KMS/cofre e validações de chaves/versão; nunca aceitar um segredo enviado livremente pelo cliente.

## Bloqueios de liberação

Faltam gestão de chaves e rotação/backup de MFA, prova de posse e sessão de cliente, métodos de recuperação, rate-limit/anti-brute-force, CSRF, TLS, políticas SQL sem superuser, testemunha externa SEC-R03 e revisão AppSec/DBA/LGPD. O uso de sodium e um teste verde não transformam dados fictícios em login real.

**DRAFT; sem merge, deploy, credencial real, VPS, cliente, publicidade ou gasto novo.**
