# PVIEW-PERF-07 — emissor de eventos S2S fictícios do Portal

Data-base: 08/11/2026. Issue #95. Branch filha de PVIEW-PERF-06 (PR #90), sem merge.

## Implementação
- `apps/portal/preview/contracts/SyntheticPortalGrantIssuer.php`: emissor de laboratório que associa **atores, perfis, organizações, serviços e tenants fictícios**, recusando emissor sem permissão, leitor somente leitura, empresa ou serviço de outra organização e grants revogados.
- Assina envelopes de teste via `SyntheticS2SProtocol::mintForLab` com `random_bytes(32)` criado apenas no teste, derivando SHA-256 da fixture local do painel Performance. O verificador em Performance usa exatamente a mesma classe de protocolo versionada e copiada em F1-07.
- `SyntheticPortalGrantIssuerTest.php`: **29 verificações** de emissão fictícia, HMAC, replay em memória, vínculo entre empresas, assinatura adulterada, expiração, revogação do grant de laboratório e ausência de cookies/dados reais/valores financeiros.
- Workflow CI separado em branch pública usa PHP puro, `contents: read`, sem Composer, banco, serviços externos ou despesa nova.

## Riscos e limites
**As identidades e permissões não são reais.** O emissor contém atores e vínculos hardcoded para testes; `issueForLab` não consulta sessão Portal, MFA, banco de contratos nem grants verdadeiros. `revokeForLab` muda apenas um array PHP na memória; não publica evento de revogação para o motor nem escreve no PostgreSQL. Nunca registrar essas classes como serviços Laravel, rotas ou manipuladores de fila.

A combinação com F1-09 Performance é uma prova de contrato **sintética**, não uma integração ativa entre dois servidores. Antes de uso real: identidade de workload, autenticação mútua, concessão assinada originada da autoridade Portal, revogação durável, protocolo transacional replay-safe, auditoria, LGPD, esquema e migração aprovados.

## Governança
PR draft, sem merge automático, `main`, branch de integração, VPS, serviços reais, API Ads e cobrança intocados. Gates P0/HT-R01 permanecem abertos.
