# PORTAL-F1-01-B-A — Decisão 02: merge controlado do PR #14

**Data:** 06/11/2026  
**Decisão:** APROVADA EXPRESSAMENTE pelo responsável ("aprovado") para merge do PR #14 **exclusivamente na branch de desenvolvimento** `dev/fast-track-portal-2026-11-05`.  
**Resultado:** **MERGE EXECUTADO E CONFIRMADO**. Não é autorização de uso operacional, liberação de gates, integração PSP/CRM/Performance, alteração de `main`, VPS ou deploy.

## Verificações imediatamente anteriores ao merge

- PR #14: https://github.com/rafhael100/imagine-portal/pull/14
- Head `dev/portal-f1-01-b-domain-2026-11-05`: `916894fe9c322961d28d7a578a9335f137ceeb15`.
- Base `dev/fast-track-portal-2026-11-05` antes: `098cdfd50024ea872861f3ba3a058409e5f976b7`.
- PR `mergeable=true`, `mergeable_state=clean`; diff 16 arquivos **todos em `apps/portal/`**.
- Check-runs vinculados ao head: Laravel PHP 8.3 e PHP 8.4 **success**; Node.js 22/24 **success**. CI Laravel: **18 testes, 36 asserções por versão**. Run https://github.com/rafhael100/imagine-portal/actions/runs/42167989718 .
- PR estava em draft; marcado como ready for review no GitHub apenas para cumprir o requisito de merge, **sem declarar revisão independente concluída além do parecer documental existente**.
- `main` antes `50b683a499e674641df5749051f3d05f7ac3f627`.

## Execução

- Método: **squash**; `expected_head_sha` exigido: `916894fe9c322961d28d7a578a9335f137ceeb15`.
- GitHub retornou `merged=true` e commit `358af2dbee82cf34b9b0d1ed7215918ada7c3959`.
- PR #14 `closed`, `merged=true`, base correta. Branch de fast-track passou a apontar para `358af2dbee82cf34b9b0d1ed7215918ada7c3959`.
- `main` continuou apontando para `50b683a499e674641df5749051f3d05f7ac3f627`. Comparação com a referência anterior da `main` mostrou `identical`.
- Após o merge, houve **uma atualização exclusivamente documental em `apps/portal/AGENTS.md` na branch de desenvolvimento**, commit `9899ba1157243cba5252c6678d8524f58cb165f9`. Portanto a HEAD posterior da branch de fast-track **não deve ser presumida igual** ao commit do squash.
- **Nenhum deploy ou mudança de VPS foi solicitado ou executado nesta sequência de operações.**

## Limitações e ressalvas não encerradas

O domínio usa objetos PHP puros, memberships e permissões explícitas, negação por padrão e testes em memória; **não valida autenticação real**, registro confiável de vínculos, status da organização, autorização em banco, MFA, RLS, revogação sob concorrência, criação de conta/serviço, nem integração com CRM/Performance. A [RV01](PORTAL-F1-01-B-A-RV01-REVISAO-SEGURANCA-PR14-2026-11-06.md) identifica essas lacunas; D02-SEC, D03 físico, P0-DOC e demais bloqueios seguem abertos.

## Próximo desenvolvimento proposto, não autorizado por este merge

**PORTAL-PERF-P02-A**: domínio do catálogo Performance AI com canais, capacidades e ofertas ainda não comercializáveis `not_for_sale`, usando apenas classes PHP e testes fictícios em branch filha da fast-track, PR draft. Precisa **aprovação de programação expressa** antes de iniciar. A integração com Performance, pagamento real e acesso dos dois clientes seguem em fases posteriores. Ver [handoff técnico P02-A](PORTAL-PERF-P02-A-PROPOSTA-CODEX-2026-11-06.md).
