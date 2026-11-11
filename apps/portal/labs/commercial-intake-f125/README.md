# Portal F125 — Fila somente de leitura para revisão de envios incertos

**Escopo:** fixture de PostgreSQL 18 descartável no CI, PHP 8.3/8.4, UUIDs fictícios e nenhuma ligação com o CRM real.

A F122/F123 impede reenvio automático após uma solicitação possivelmente transmitida. A F124 demonstra resposta perdida após COMMIT no segundo banco. Porém, o operador precisa identificar **quais envios exigem reconciliação sem inferir sucesso de atendimento ou liberar retry**.

## Novo contrato de revisão

`delivery_review_queue` é uma VIEW, não fila de processamento. Usa `security_invoker=true` e `security_barrier=true` para fazer valer RLS/GRANT do próprio leitor, ao invés dos direitos do proprietário da view. Permite apenas SELECT para os papéis de CI tenant A/B com `NOLOGIN/NOBYPASSRLS`.

A view apresenta **somente** UUIDs de empresa/submissão, versão do claim, quantidade de tentativas, data técnica e razão de revisão:

- `unknown_outcome`: resposta incerta registrada; não enviar novamente.
- `expired_dispatch_may_have_committed`: envio potencialmente iniciado e lease vencido; nenhum retry automático.
- `claim_attempt_limit_reached`: três reservas sem resolução; não afirmar ausência de envio remoto, exigir investigação.

Todos os itens apresentam `requires_manual_review=true`, `automatic_retry_allowed=false`, `real_crm_persisted_verified=false` e `can_acknowledge_visitor=false`. Não existem tokens, dados de contato, recibos, payload, client secret ou grant de alteração. A view não apaga os registros nem permite transição `delivered`.

## Evidência por CI

O teste executa o banco real PostgreSQL 18 após F117–F123 e confere as três classificações nos cinco registros fictícios persistidos. Repete isolamento entre empresas, falsificação de `tenant_id` por GUC, SELECT com RLS, recusa de INSERT/UPDATE/DELETE/TRUNCATE sobre a view, proteção de schema e bloqueio de qualquer referência à fixture dentro do Laravel. Matriz PHP 8.3/8.4.

## Antes de uma operação real

Este módulo não é interface administrativa e não está autorizado a expor dados a clientes. Um painel futuro precisa de IAM/MFA reais, autorização de empresa/responsável por recurso, logs de leitura, LGPD, regras de SLA e trilha auditável de decisões. A reconciliação deve validar recibos do **CRM real**, não do mock. Somente revisão externa AppSec/DBA, testes na VPS homologada e aprovação de publicação poderão modificar o estado operacional. Ver [SEC-REVIEW-CRM-DELIVERY #315](https://github.com/rafhael100/imagine-portal/issues/315).

Nenhuma alteração em produção, `main`, VPS, DNS, segredos ou despesas.
