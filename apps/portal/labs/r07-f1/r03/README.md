# SEC-R03 — recuperação fail-closed em laboratório descartável

Aprovação em 08/11/2026, Issue #64. Execução limitada a branch e PR draft. Sem merge, VPS, cliente real ou gastos novos.

A experiência usa PG18 sintético da R07-F1; pg_dump antes da revogação; restore separado em portal_r07_restored. Z2-restored PHP só aceita conexão quando um serviço fence Python em data_net autoriza; o estado fence fica no RUNNER_TEMP do GitHub runner fora do backup PostgreSQL. Z1 Python sem SQL tenta GET e POST com a sessão A antiga.

O clone fica sem CONNECT para Z2 até reconciliação. Ao reconciliar, o runner revoga epoch, sessão e membership A no clone, verifica DB e só então abre o fence. Sem fence, estado inválido ou geração antiga, HTTP 503. Após abrir, sessão A antiga deve retornar 401; B nunca recebe efeito.

| Teste | Situação |
| --- | --- |
| N01 serving fechado antes do restore | CI |
| N02 sessão/grants reaparecem no clone, mas HTTP nega | CI |
| N03 fence ausente | CI |
| N04 old session depois de reconcile/open | CI |
| N05 old grant negado | CI |
| N06 intents não reproduzidos | NOT RUN |
| N07 outbox/jobs sem duplicação | NOT RUN |
| N08 cache/pool/writers obedecem | NOT RUN |
| N09 fence inválido | CI |
| N10 restore/revogar concorrentes | NOT RUN |
| N11 B sem efeitos | CI |
| N12 destruição dos volumes | Cleanup CI |

Não é um mecanismo de produção: arquivo externo efêmero não é autoridade monotônica durável, não resiste a snapshot de host inteiro ou abuso de operador. Sem prova para jobs, MFA/recovery, outbox, tokens reais, todos os writers, pool, T3 ou Z2 comprometida. P0 e gates permanecem abertos, revisão interna apenas (Issue #58).