# PORTAL-F62 — checagem ponta a ponta PostgreSQL → Ed25519 → Performance AI

**Escopo:** laboratório portátil do Portal com as implementações **reais de código** do receptor Performance AI F1-29/F1-30, porém exclusivamente com dados fictícios, PostgreSQL descartável e sem autorização operacional.

## Proveniência verificável

Código originalmente do repo `rafhael100/imagine-performance-ai`, branch `dev/perf-f1-30-portal-sql-ed25519-e2e-2026-11-09`, PR [#73](https://github.com/rafhael100/imagine-performance-ai/pull/73), após PR [#71](https://github.com/rafhael100/imagine-performance-ai/pull/71).

- `SignedPortalCapabilityGate.php`, blob Git origem `e54ed6d3d044013b00a7ec30d8d405ae64d15751`
- `SyntheticCurrentAuthority.php`, blob `ffbd4b2dc81194320e9c9e0fece6fe9e8bf68ce0`
- `test_portal_pg_signed_perf_e2e.php` — derivado do teste de contrato F1-30, mudanças restritas a namespace/path para usar a fonte F61 já integrada ao Portal.

As classes verificadoras preservam a lógica F1-29 e foram copiadas para `apps/portal/labs/performance-verifier-f62/` com namespace `App\\Labs\\PerformanceVerifierF62` (sem auto-registro, routes, providers ou serviços operacionais).

## O que o CI executa

O workflow `portal-f61-auth-perf-db-signed.yml` passa a rodar, **no mesmo PG16 descartável**, as verificações:

1. **60 F08** — sessão bearer somente hash, MFA sintético, membership/serviço/revisões, RLS/role de leitura e revogação;
2. **45 F09** — emissão Ed25519 apenas após consulta PostgreSQL, keypair efêmero, nonce, revogações e perda de conexão/permissão;
3. **50 F30** — emissor Portal F61 envia *arquivo local temporário* com envelope assinado e somente a chave pública; receptor Performance importado verifica integridade, escopo, TTL, nonce e reconsulta SQL de revogação/revisões posteriores à emissão.

No caso positivo, o verificador só pode devolver `verified_synthetic_only`. O teste verifica negações para sessão, MFA, membership, serviço, revogações, alteração de epoch, replay na mesma instância, chave divergente e perda de acesso ao banco.

## O que não pode ser feito

- Não é S2S de rede, login real, auditoria AppSec/DBA, autenticação de campanhas, OAuth Google/Meta ou autorização de mídia.
- Não implementa store anti-replay distribuído, witness anti-rollback resistente a restore, KMS/rotação, atomicidade de emissão/consumo, propriedade de recurso ou revogação entre lookup/assinatura.
- O receptor é componente **copiado exclusivamente para o laboratório do Portal**, não substitui código publicado do motor Performance; mudanças futuras no motor exigem revisão cruzada.
- Não fazer merge, deploy, migração de produção, acesso à VPS, emissão de chave privada persistida, cobrança ou ativação de clientes.

**Gate operacional: NO-GO** até autoridade de clientes/contratos, IAM/MFA e LGPD, AppSec/DBA, SEC-R03/T3 e release review aprovados.
