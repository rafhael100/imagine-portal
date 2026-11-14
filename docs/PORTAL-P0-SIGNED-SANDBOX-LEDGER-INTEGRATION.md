# P0 — primeira cobrança Sandbox com pedido de cliente isolado no PostgreSQL

**Estado: DRAFT / NO-GO.** Esta branch de integração empilha a PR #755 (checkout aprovado, RLS por cliente com principal HMAC, restore, Asaas F390–F392, GET-only preflight e aceite Sites) com o trabalho de intenção de pagamento/worker da PR #760. **Não é merge da ancestralidade da #760** e não implica deploy.

## Jornada implementada para CI

1. Cliente fictício entra no Portal privado e cria pedido a partir da oferta versionada. O orçamento e o valor da primeira cobrança são do servidor, não do formulário.
2. No próprio pedido, opta pela cobrança Sandbox. O controller verifica cliente e revisão, chama `SandboxCheckoutIntent` com `CustomerPreviewStore::withVerifiedScope` (claim HMAC, `SET LOCAL`, transação PostgreSQL).
3. `sandbox_payment_intents` é única por `order_id`, com `FORCE RLS`; política do usuário SQL comum exige assinatura válida do cliente. Uma role worker NOLOGIN diferente é a única autorizada a alterar referência e estado financeiro. O login da aplicação não é membro dessa role.
4. Worker externo (somente host) consulta recurso por referência antes do POST, usa pagador fictício sem e-mail/telefone e sem notificação e não repete chamadas de resultado incerto automaticamente. Nenhuma chave API é montada na aplicação web.
5. Somente resposta de liquidação **RECEIVED** coerente com referência, pagador e valor altera o status sintético. `CONFIRMED` de cartão não basta. Reembolso, exclusão ou chargeback ativam `payment_hold` persistente, que bloqueia novo briefing e ação de produção, preservando o histórico existente.
6. CI testa PHP/Chromium, roles, chave de autorização forjada, identidade de outro cliente, consulta SQL não assinada, reabertura após restart e `pg_dump/pg_restore` de intenção/hold e políticas.

## Limites operacionais

- O worker usa role temporária via `SET ROLE` de PostgreSQL no **laboratório**, executada por superuser no runner. Isso **não é** a arquitetura de autenticação aprovada para um worker de produção.
- Execução real de um POST de cobrança exige provedor Sandbox, identidade e contrato verificados, revisão AppSec/DBA, configuração de segredos e autorização específica. Nenhum teste CI usa credenciais reais.
- Apenas a primeira cobrança sintética é coberta. D30/D60, assinatura D90, Pix Automático, cálculo mensal Performance com mídia real, faturamento/NFS-e, vínculo de contrato, IAM/MFA, webhooks HTTPS e publicação Sites continuam bloqueados.
- Não executar migrações na Leaseweb nem usar chaves reais a partir desta branch. Não modificar `main`, DNS, worker operacional ou gateway sem gates.

**Release gate:** NO-GO / NO DEPLOY / NO BILLING / NO PRODUCTION.
