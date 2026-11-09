# PORTAL-F4 — Corrida entre gravação e revogação (laboratório multi-conexão PG18)

**Data:** 08/11/2026. **Objetivo:** ampliar os testes F3 em duas conexões PostgreSQL reais, com transações sobrepostas e dados fictícios, sem login público ou ambiente de produção.

## Contrato de sincronização experimental

Acessos de escrita participantes de `LabPortalGateway::submitRequest` (legado de teste F1) e `submitIdempotentRequest` (F3) entram em uma transação PostgreSQL e adquirem **advisory transaction-level lock compartilhado** para o escopo `organization_id + actor_id` **ANTES** de verificar membership e de gravar. A rotina autorizada de revogação em teste, `LabMembershipFence::revoke`, adquire o mesmo lock em **modo exclusivo** antes de atualizar o membership para `revoked`. Ambos liberam locks somente no COMMIT/ROLLBACK da transação externa.

As chaves derivam de um prefixo versionado de laboratório e `hashtextextended(..., 0)`. Uma colisão de hash de locks pode bloquear desnecessariamente scopes distintos; não concede acesso. A conexão de runtime não ganha permissão de UPDATE em memberships. Alterações por administradores ou workers que contornem o protocolo são **fora do modelo validado**.

## Cenários executáveis

| ID | Conexões PostgreSQL | Ação / resultado esperado |
|---|---|---|
| F4-C01 | writer runtime e revoker owner, PIDs distintos | Writer adquire lock compartilhado, executa gravação e mantém a transação externa aberta. Revoker tenta lock exclusivo com timeout e **não pode avançar**. Após COMMIT da gravação, revoker adquire o lock e marca vínculo revogado. Nova escrita é negada. |
| F4-C02 | revoker owner e writer runtime, PIDs distintos | Revoker adquire lock exclusivo, marca vínculo revogado e mantém transação aberta. Writer tenta lock compartilhado com timeout e **não pode avançar**. Após COMMIT da revogação, writer tenta novamente, vê membership revogado e não grava. |
| F4-C03 | rotina transacional sem bloqueio ativo | `LabMembershipFence::lockWriter` e `lockRevoker` recusam uso fora de transação. Este caso pode ser adicionado como regressão adicional. |

**Medidas de QA:** testes gravam/consultam SQL em conexões distintas e usam `lock_timeout` curto para não travar CI. A ocorrência esperada de erro `lock timeout` não deve ser confundida com falha geral do CI. Capturar o SHA e os logs.

## Restrições e risco de bypass

Este modelo **não comprova revogação universal e não é middleware de produção**. A garantia condicional requer que **todos** os escritores, caminhos administrativos de revogação, filas, webhooks, chamadas n8n, scripts, restores e workers usem o mesmo protocolo e role. Uma atualização direta de `lab_portal_memberships` sem lock pode concorrer livremente; a role dona do banco pode contornar o protocolo. As consultas HTTP ainda estão sob `APP_ENV=testing`, `PHP_SAPI=cli` e `PORTAL_CLIENT_LAB_ENABLED=1`.

Ainda em aberto: concorrência de múltiplos writers sob locks reais em produção, retry sob deadlocks, cache/outbox e side effects, isolamento transacional durante restore, distribuição por múltiplos clusters, anti-rollback de revogação, controle de tempo, MFA operacional, CSRF/cookies reais e ameaça Z2 comprometida. Não converter advisory lock do lab diretamente em mecanismo de autorização de produção sem revisão e inventário integral de escritores.

**Governança:** branch filha de PR #82; código e testes em `apps/portal/labs/client-auth` e no PHPUnit do laboratório; fluxo de clientes, migrations operacionais, `main`, `dev`, VPS e Cloudflare não alterados. Gates D02, D03/RLS, P0, SEC-R03/T3 e release continuam abertos.
