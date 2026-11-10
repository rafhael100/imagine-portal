# PORTAL-F69 — Argon2id e TOTP (RFC 6238) para futura identidade Portal-only

Esta etapa aprofunda a F66/F67 sem abrir rotas de login ou contas reais.

## Componentes de código

- `PortalPasswordCredential`: cria hash com PHP Argon2id (64 MiB, custo de tempo 3, thread 1), valida tamanho da senha (12–1024 bytes), recusa NUL, bcrypt, texto simples e hashes com parâmetros de custo impróprios. `password_verify` oferece verificação contra o hash armazenado, mas **não concede sessão**.
- `PortalTotpVerifier`: verifica TOTP RFC 6238 de seis dígitos via HMAC-SHA1, step de 30 s e tolerância de uma janela anterior/posterior. Recebe segredo Base32 maiúsculo validado e retorna o **contador exato** que correspondeu. Recusa formato incorreto, relógio inválido e contadores já utilizados.

## Garantia crítica de replay

O verificador TOTP **não persiste o contador**. Antes de liberar qualquer sessão, um servidor autorizado precisará fazer `UPDATE ... WHERE last_used_counter < matched_counter` em transação atômica, verificar 1 linha atualizada, confrontar a identidade e versão de sessão vigentes, MFA habilitado e fence antirollback SEC-R03. Sem esse compare-and-swap durável, o uso de `matchCounter` isoladamente não protege contra solicitações concorrentes.

## Testes

- Casos SHA1 TOTP de seis dígitos derivados dos vetores de tempo RFC6238.
- Rejeição de replay na mesma janela, códigos/segredos inválidos, horário fora da janela.
- Hash Argon2id real gerado no runner PHP, senha correta/errada, bcrypt rejeitado, limites de senha.
- Ausência de métodos que emitam login, permissões, sessão ou MFA validado sem fonte confiável.

## Ainda pendente

Provisionamento seguro de TOTP, geração QR sem fornecedor, segredo criptografado com rotação, proteção contra brute-force, rate-limit, cookies de sessão, provedores de recuperação, OTP reuse durável, RLS autorizativo, witness Z4 externo e auditoria. Nenhum valor de senha ou segredo real é testado; dados e vetores são públicos/sintéticos.

**DRAFT / só código e teste. Não habilitar endpoints, VPS, SQL de cliente, produção ou cobrança.**
