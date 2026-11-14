# P0 — auditoria automática do banco da prévia privada (NO-GO operacional)

## Problema

O fluxo Comercial → Portal já pode ser percorrido com cadastro individual, pagamentos fictícios e listagem em Meus serviços. Entretanto, o banco real da **prévia isolada** ainda utiliza um login compartilhado `purchase_rehearsal_a` com `SELECT` direto em `purchase_rehearsal.customers` e `purchase_rehearsal.customer_orders`. As policies `FORCE ROW LEVEL SECURITY` protegem apenas a empresa fictícia e **não** o cliente individual. Portanto, um comprometimento desse login poderia ultrapassar o filtro por cliente do PHP.

## O que a CI agora comprova

Depois de instalar o esquema original em PostgreSQL18 descartável, o workflow de prévia Docker consulta **metadados SQL reais**, sem ler nenhum registro de pessoas, pedidos ou passwords:
- GRANTs `SELECT` do papel de aplicativo nas tabelas de contas e pedidos;
- `FORCE RLS` de ambas as tabelas;
- se as policies de tabela restringem efetivamente por `customer_id`;
- `SUPERUSER` e `BYPASSRLS` do papel.

O script `audit_shared_role_acl.py` recusa metadados incompletos, alterações inesperadas de acesso e o afastamento silencioso da configuração que foi efetivamente auditada. Ele grava `p0-customer-sql-no-go-evidence.json` sanitizado com **NO_GO**, SHA256 de integridade (não assinatura), nada que represente dados pessoais ou autorização para deploy, e o guarda como artifact por 7 dias.

Quando o modelo SQL mudar, o teste **falhará para exigir revisão independente** em vez de concluir automaticamente que a versão nova está aprovada. O ensaio `owner-capability-lab.sql` da PR #707 segue separado e não é instalado na prévia operacional.

## Como resolver o gate

1. Aprovar a arquitetura IAM multiempresa/usuário com MFA, revogação e proteção contra rollback.
2. Escolher uma barreira SQL que **não aceite IDs de cliente arbitrários enviados no navegador** e não exponha a tabela via login de serviço. A PR #694 ensaia logins distintos; #707 ensaia funções SQL com HMAC de serviço e permissões diretas revogadas. Nenhuma é ainda implementação aprovada.
3. Migrar com funções e grants mínimos, transações, concorrência, idempotência e testes de isolamento SQL direto por cliente.
4. Testar dois clientes da mesma empresa, outra empresa, cliente desabilitado, sessão revogada, replay, pedido legado, pagamentos, recuperação após restart e backup/restore; exigir revisão AppSec/DBA/LGPD independente.
5. Só depois reescrever o presente gate e reconsiderar homologação privada na VPS.

**Esta PR não remove o risco. Ela o mede no banco efetivamente usado pela prévia e impede uma afirmação enganosa de readiness.** Nenhuma operação real é autorizada.

Status: **DRAFT/NO-GO.** Sem merge, alteração da VPS, DNS, pagamento ou captação real.
