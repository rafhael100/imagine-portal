# PORTAL-AUTH-F2 — Sessões persistidas, MFA/TOTP e bloqueio de tentativas (laboratório)

**Data:** 08/11/2026. **Autorização:** desenvolvimento autônomo dos módulos do Imagine Portal, mantendo decisões críticas e segurança de produção separadas. Este PR é **filho do PR #80** e altera **somente** o ambiente sintético PostgreSQL 18 + PHPUnit, sem criar rotas acessíveis a clientes.

## Implementação realizada

- Criadas três tabelas laboratoriais adicionais no schema SQL descartável, **fora de database/migrations**:
  - `lab_portal_auth_factors`: segredo TOTP hexadecimal **inteiramente fictício**, contador de último código consumido.
  - `lab_portal_auth_sessions`: **somente digest SHA-256** do bearer opaco aleatório (32 bytes), ator, revisão, expiração, flag MFA e revogação.
  - `lab_portal_login_limits`: chave SHA-256 do e-mail normalizado, contagem de falhas e janela de bloqueio (parâmetros **ilustrativos**, não política de segurança aprovada).
- `LabTotp`: HMAC-SHA1 compatível com o cálculo RFC 6238 (30 segundos, 6 dígitos, janela de ±1 passo), checagem com `hash_equals`. Uma utilização bem-sucedida atualiza `last_step` sob bloqueio da linha SQL; não aceita reutilização do mesmo contador.
- `LabSecureLogin`: valida senha com `Hash::check`, exige segundo fator, limita três tentativas de login no PostgreSQL com `lockForUpdate`, cria sessão com bearer de alta entropia e grava apenas hash no banco. Expiração ilustrativa de 20 minutos. Cada requisição consulta estado, revisão de usuário, MFA confirmado, vencimento e revogação atuais. Logout marca revogado no DB e invalida sessão Laravel local.
- Endpoints lab isolados em `/_lab/portal/secure/*` (login, logout, organizações e dashboard somente-leitura), mantendo o endpoint original da F1 **para compatibilidade de testes**, mas jamais permitindo que ele satisfaça a autorização exigida pelo recorte F2. Nenhuma rota pública foi aberta.
- Testes adversariais usam usuários e senhas fictícios, validando o vetor RFC TOTP, senha errada, OTP incorreto, bloqueio, replay, token digerido, expiração, revisão, logout, tenant B e membership revogado.

## Limites e riscos não resolvidos

1. A tabela de MFA guarda o segredo **fictício em claro**. **NÃO reutilizar em produção**: requer criptografia por chave de aplicação/KMS, provisionamento, prova de posse, rotação, recuperação e trilha de auditoria.
2. O registro de sessão é persistente no PostgreSQL do laboratório, mas o *transporte do bearer até o browser* usa a sessão Laravel `array` em PHPUnit. **Não comprova cookies Secure/HttpOnly/SameSite, continuidade entre servidores ou CSRF em HTTP real**.
3. A proteção antiabuso é simplificada por principal/e-mail com 3 falhas e 60 segundos; não prova limites distribuídos por IP, dispositivo, ASN, login enumeration, notificações, cadastros, MFA fatigue, recuperação de conta, ataques volumétricos ou risco de DoS por travamento.
4. RLS segue parcial às tabelas de serviços e solicitações. A role de teste pode acessar dados sintéticos e não foi provada segurança contra Z2 comprometida. Permissões e fatores não são um sistema de conta real.
5. **Ainda NÃO testado**: concorrência HTTP real e linearizabilidade de revogação com escrita, backup rollback/Z4, pools/filas/outbox, MFA de recuperação, persistência de sessão distribuída e ataques web reais.
6. O protocolo REST do laboratório só é incluído se `APP_ENV=testing`, `PHP_SAPI=cli` e `PORTAL_CLIENT_LAB_ENABLED=1`. Mesmo uma resposta `200` no mock não concede autorização em produção.
7. Versões, duração de sessão, políticas MFA e UX são parâmetros experimentais. Não pressupor adesão, custo ou seleção de provedor.
8. A implementação F2 é um ramo de prova; futura produção deverá utilizar as interfaces de autoridade já existentes e uma solução auditável em Laravel, nunca promover diretamente `LabSecureLogin` ou as senhas públicas do CI.

## Critérios de teste

Executar exclusivamente com serviço PostgreSQL 18 efêmero e role `portal_lab_runtime`, via workflow `.github/workflows/portal-client-lab-postgres.yml`. Não declarar PASS antes de logs de CI no SHA exato, distinguindo testes PG intencionalmente ignorados no workflow geral PHP 8.3/8.4.

**Gates ainda abertos:** D02-SEC01–06, D03/RLS, P0, G-ID/G-BB, SEC-R03/T3, privacidade e release. Nenhum merge automático para PR #80, dev/main; nenhuma VPS, Cloudflare, dados ou conta real, PSP, CRM, despesa ou deployment.
