# PORTAL-F60 — inclusão do SEC-R03 C04 no CI do Portal consolidado

**Propósito:** impedir que a consolidação técnica F59 seja confundida com liberação de produção. A linha F59 não continha a suíte de evidência C04 presente em branch separada [SEC-R03 C04, PR #132](https://github.com/rafhael100/imagine-portal/pull/132).

## Proveniência

Copiado em 09/11/2026 de `test/portal-sec-r03-c04-release-evidence-gate-2026-11-09` (PR #132), sem alterar a lógica do arquivo de teste e da implementação. Blobs de origem:

- `C04-OPERATIONAL-GATES.json`: `d5af6bf60666c121e970f3546ad44695bdbc2ed2`
- `src/c04-release-gates.mjs`: `c1f4c0ff3162da2951c821aef352cf0c7e99b7df`
- `test/c04-release-gates.test.mjs`: `d31002ea9059aac9cbf14870fa27912c5ff09aad`
- `wrangler.jsonc`: `928cfdaf2867660e240ff3875f5ef170f401403e`

Os quatro arquivos foram mantidos na mesma estrutura relativa para executar o contrato C04 sem dependências, sem rede e sem Wrangler instalado. `wrangler.jsonc` é **somente fixture de segurança**; não possui um `src/worker.mjs` implantável nesta pasta. É proibido utilizar esta fixture para deploy.

## Execução

```bash
cd apps/portal/labs/release-f60
node --test test/c04-release-gates.test.mjs
node src/c04-release-gates.mjs --check-lab
```

A CI `.github/workflows/portal-f60-sec-r03-release-gate.yml` roda Node 22/24. O resultado esperado é testes passando **porque** todos os gates operacionais continuam `BLOCKED` ou `NOT_RUN`, inclusive SEC-R03-X01..24, P0, IAM, RLS, AppSec/DBA e witness externo. Se alguém alterar a fixture para `PASS`, o CI deve falhar — não usar verde como autorização.

## O que NÃO foi integrado

A execução real de Cloudflare, DO/R2, RLS, o witness externo anti-rollback e autoridade de produção; tampouco foram feitos merge dos experimentos #116/#120/#124 ou dos contratos auth #126/#128/#130. Sem credenciais, bucket, gasto, cliente ou rota. Merge da branch de revisão segue bloqueado até homologação de segurança e decisão expressa para produção.
