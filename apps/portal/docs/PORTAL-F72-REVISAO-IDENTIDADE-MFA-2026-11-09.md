# PORTAL-F72 — Revisão integrada da fundação de identidade e MFA, sem publicação

Corte técnico: 09/11/2026, ciclo GitHub UTC de 10/11/2026. **Este documento é de revisão; não libera produção.**

## Linha consolidada

Base `dev/fast-track-portal-2026-11-05`. Esta branch é descendente da F68/PR #202, incorpora:

- **F69 / PR #203:** `PortalPasswordCredential` Argon2id, política de senha; `PortalTotpVerifier` RFC6238 (janela ±1 e contador usado).
- **F70 / PR #204:** `mfa_last_used_counter` com constraint, teste CAS SQL em PostgreSQL18 de replay por múltiplas conexões, revogação, timeout de lock, RLS sem policy e rollback das migrações.
- **F71 / PR #205:** simulação transacional em PostgreSQL que liga TOTP à transição `pending_mfa→active` de **uma sessão sintética**, com rollback completo quando código, identidade, organização, membership ou versão não estão válidos.

Mantém, da F68, a imagem PHP8.4 FPM privada/nginx (apenas `/up` no localhost), a primeira migração de identidade Portal-only F66, cinco modelos F67 e a UI HTML offline de sete seções + Performance, com os contratos e testes SEC-R03/Performance/Imagine Sites.

## Status de testes e riscos

A F70 e F71 são provas com PG18 descartável no GitHub e PHP8.3/8.4. O resultado positivo refere-se **somente aos dados de teste**, não cria autenticação real nem autorização comercial. A F71 usa segredo TOTP fornecido pelo teste e papel PostgreSQL superuser do runner; **isso é deliberadamente proibido para aplicação real**. Nenhuma rota de login foi aberta. O C04/SEC-R03 ainda mantém os controles de release `BLOCKED`/`NOT_RUN`.

## Pré-requisitos não cumpridos para um Portal utilizável por clientes

1. FONTE DE IDENTIDADE FIRST-PARTY: verificação de e-mail, senha sob autorização do Portal, bloqueio de tentativas, convite e recuperação, gestão de secrets, sessão/cookie HttpOnly Secure SameSite, proteção CSRF e MFA TOTP recuperado exclusivamente do cofre do servidor, não passado por cliente.
2. AUTORIDADE E RLS: papel de aplicação PostgreSQL sem superusuário, fonte de recursos, membership e grants server-side, policies revisadas e testes E2E por empresa, revogação simultânea e mudanças de versão.
3. WITNESS antirollback SEC-R03/Z4: armazenamento/ancoragem fora do domínio de restore, monotonicidade verificável para sessões, MFA e permissões; restore e corrida reprovação por auditoria independente.
4. Integrações reais com Sites, Performance, CRM e Universidade com S2S seguro, contratações/aceite versionado, pagamentos, LGPD, idempotência e monitoração.
5. VPS Leaseweb de homologação acessível e sob controle do responsável, DNS/TLS/backup/runbook de restore/testes de segurança e aprovações AppSec/DBA independentes.
6. Revisão de PRs, dependências, segredos, licenses, acessibilidade, observabilidade e plano de implantação/rollback.

## Critérios mensuráveis para a próxima etapa

- Em ambiente de homologação **não público**, provar login com MFA real e controle de tentativas, senha Argon2id, mudança de tenant e revogação.
- Implementar conexão e claims de serviço verificados criptograficamente, sem compartilhar login do CRM.
- Fechar provas SEC-R03 de rollback e auditoria externa, com evidência em armazenamento distinto do banco.
- Só então substituir o gateway `/up` por rotas autenticadas e homologadas, com autorização explícita e revisão de segurança.

**DRAFT / NÃO MERGE AUTOMÁTICO OU DEPLOY**, sem alteração em `main`, `dev`, VPS, DNS, Cloudflare, provedores Google/Meta, faturamento ou dados de clientes. Não confundir quantidade de PRs/CI verde com completude do produto.
