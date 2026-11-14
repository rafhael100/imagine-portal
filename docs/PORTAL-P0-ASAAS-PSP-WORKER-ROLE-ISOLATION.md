# Imagine Portal P0 — roles SQL distintas para cliente e conciliação Asaas Sandbox

## Problema encontrado na CI

Na candidata de primeira cobrança da [PR #761](https://github.com/rafhael100/imagine-portal/pull/761), o teste integrado de navegador executava o checkout e criava uma intenção persistida, mas o worker devolvia `Ledger operation unavailable` ao ler o PostgreSQL 18.

A tabela `sandbox_payment_intents` tinha política de cliente assinada e política de worker restrita, porém, quando ambas ficam visíveis a todas as roles, a consulta do worker pode depender de uma função HMAC exclusiva do cliente e de colunas que a role financeira não tem autorização para ler. Além disso, a revisão-base continha `TO ... TO ...` duplicados na política SQL, inválidos no PostgreSQL.

## Correção mínima e explícita

- Na base dos clientes, `customer_order_scope` aplica-se **apenas à role `purchase_rehearsal_a`**, com `preview_order_allowed(customer_id)` e token HMAC autenticado.
- Na base de intents, `sandbox_owner_intent_scope` aplica-se **apenas à role `purchase_rehearsal_a`**. A política `sandbox_worker_intent_scope` destina-se **somente à role `purchase_rehearsal_psp`**, sem acessar a função assinada de cliente.
- `sandbox_worker_orders` limita a leitura/atualização do estado de pagamento à role PSP, ao tenant sintético e às colunas concedidas originalmente. Não recebe acesso ao `customer_id`, `offer_snapshot`, CPF/CNPJ, briefing ou dados pessoais.
- Mantém-se `FORCE ROW LEVEL SECURITY`, a separação de roles, `NOLOGIN` para worker e **nenhuma filiação** da role web no worker. O script de teste comprova as listas de roles das políticas e executa `SELECT` real do worker sobre intents e status sem credencial de cliente.
- O login web sem HMAC válido deve continuar vendo **zero** intents e pedidos, incluindo quando tenta forjar a assinatura do administrador.

A alteração está restrita a scripts de laboratório. **Nunca aplicar o schema CI na Leaseweb ou migrar para produção.** O PSP Sandbox já possui atividade de homologação documentada em outra linha, mas esta PR não cria cobranças, não acessa a VPS e não recebe qualquer segredo.

## Gates

A melhoria é válida somente após execução do workflow `Portal isolated persistent private preview` com PostgreSQL18, login de cliente, worker e Chromium na **HEAD desta PR**, seguido de regressões de isolamento e restauração. Uma CI verde não substitui autenticação real, conta Asaas validada, migração revisada/backup, idempotência e autorização de Sites.

**DRAFT / NO MERGE / NO DEPLOY / NO CHARGE / NO PUBLISH**.
