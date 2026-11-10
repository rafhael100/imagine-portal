# PORTAL-F61 — convergência AUTH-PERF F07–F09 em laboratório fechado

**Objetivo:** permitir que a linha do Portal F59-F60 também execute os testes de autoridade PostgreSQL, sessão/MFA e assinatura Ed25519 já construídos na linha experimental separada (PRs [#126](https://github.com/rafhael100/imagine-portal/pull/126), [#128](https://github.com/rafhael100/imagine-portal/pull/128), [#130](https://github.com/rafhael100/imagine-portal/pull/130)).

## Origem e mudanças propositais

Fonte: branch `feat/portal-auth-perf-09-pg-signed-issuer-2026-11-09` no GitHub, corte 09/11/2026.

Para impedir migração, providers ou capacidade real por acidente:

- As quatro classes F07-F09 foram colocadas em `app/Labs/AuthPerfF61/`, com namespace `App\\Labs\\AuthPerfF61`; não estão em `app/Domain/PerformanceBridge` e não são registradas em ServiceProvider.
- A migração de laboratório foi **movida para `labs/auth-perf-f61/migrations`**, fora da pasta `database/migrations` utilizada pelo Laravel normalmente. É aplicada **somente** pelo CI F61, explicitamente com `--path=labs/auth-perf-f61/migrations`, em banco descartável `portal_perf_auth_lab`.
- Os testes CLI F08 e F09 foram copiados e ajustados unicamente para paths/namespaces isolados, mantendo os 60 testes de role/RLS/revogação e os 45 de emissão de envelope Ed25519 e revogações.
- Um teste Feature do emissor F07 foi trazido ao Laravel com nome próprio para evitar colisões.
- O workflow `.github/workflows/portal-f61-auth-perf-db-signed.yml` usa PG16 efêmero em runner, leitura por role restrita, PHP 8.4, Composer lockfile, nenhuma rede de clientes, nenhum deploy ou integração externa.

## Evidências e riscos mantidos

1. O bearer, MFA e a assinatura do CI provêm apenas de **dados sintéticos** inseridos em PostgreSQL descartável. Não significam login real, prova de posse de dispositivo, consentimento ou direito comercial.
2. A chave Ed25519 é gerada e descartada em memória; não existe KMS, rotação, autoridade operacional ou nonce persistido.
3. RLS e revisões são testados no banco efêmero; revogação concorrente entre consulta e assinatura, rollback de backups e testemunha fora do banco continuam abertos.
4. O recurso de campanha continua fixo `demo-cam-a1` e não prova titularidade da campanha ou autorização de Ads.
5. Nenhum caminho HTTP, job, provider, fila, cliente, cobrança, contrato ou migração normal recebe essas classes. **Não promover para produção** nem usar fixtures como credencial.
6. Os segredos com prefixo `ci-f08-` são strings descartáveis exclusivamente do PostgreSQL do runner, não credenciais reais nem parâmetros para VPS.

## Escopo de aprovação

Este PR é **DRAFT**, sem merge na `dev` ou `main`, sem execução na VPS, sem novo fornecedor e sem novas cobranças. Precisam de AppSec/DBA e alinhamento com SEC-R03/anti-rollback antes da implementação de autenticação real.
