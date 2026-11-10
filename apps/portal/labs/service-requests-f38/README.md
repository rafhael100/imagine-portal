# PORTAL-F38 — Escrita transacional de solicitações com idempotência (somente PostgreSQL descartável)

**09/11/2026 · Backend PHP/Laravel · PostgreSQL 18 em CI · zero clientes reais.**

## Avanço funcional

A F37 criou um diário de solicitações **apenas em memória** para
demonstrar a semântica do fluxo de negócio, da revisão otimista e
da deduplicação de comandos. A F38 implementa essas mesmas
garantias em uma transação SQL de **laboratório descartável**,
sem registrar rotas, controladores, middleware ou login.

## Contrato implementado

- `labs/service-requests-f38/schema.sql`: schema PostgreSQL
  `f38.service_request` + `f38.command_receipt` e roles
  `f38_writer_a` e `f38_writer_b` com passwords aleatórias
  por job GitHub Actions. Ambas as tabelas mantêm ENABLE/FORCE
  RLS, policies por `session_user` e tenant da fixture,
  e FK/PK de unicidade por organização + ID de comando.
  Roles podem apenas SELECT, INSERT e UPDATE permitidos
  por RLS, sem DELETE/TRUNCATE/DDL.
- `F38SyntheticRequestWriter`: usa a role da empresa,
  exige identidade SQL restrita e FORCE RLS nas duas tabelas,
  abre transação PDO e faz `SELECT ... FOR UPDATE`
  no pedido. Retorna a **resposta original** para chave
  idempotente de payload igual, sem nova escrita; recusa
  reutilização da chave com outro payload; exige revisão
  anterior exata e transição aceita pela máquina de
  estados F37. Atualiza estado e insere recibo na **mesma
  transação**; em falha, faz rollback integral.
- `F38SyntheticWriteResult`: distingue `applied_synthetic_sql`,
  `replayed_synthetic_sql`, conflito, stale, fonte
  indisponível e papel SQL inseguro. Em todos os casos,
  `mayAuthorizeHttp()`, `mayWriteProduction()` e
  `mayCreateContractOrPayment()` são **sempre false**.
- `F38SyntheticRequestWriteIntegrationTest.php`:
  dez provas contra PostgreSQL18 real em Docker efêmero:
  separação de A/B; reenvio depois de evolução do estado;
  conflito de chave; revisão stale; ação de papel
  fictício incorreto; tenant/resource mismatch; ausência
  de permissão para DELETE/DDL; FORCE RLS retirado; role
  BYPASSRLS; lock timeout; falha de INSERT no recibo e
  rollback do UPDATE do estado.

## Segurança e limites

- A coluna `actor_kind` (cliente ou time Imagine) continua
  sendo **entrada simulada**, não identidade derivada de
  um autenticador confiável. Esta PoC NÃO pode servir
  como API real, mesmo com RLS, antes de implementar
  autenticação, membership, permissão, ownership de serviço
  e revogação transacional homologadas.
- A tabela de recibos apenas simula idempotência;
  a operação real precisará de políticas para retenção,
  deleção LGPD, índice de chave do cliente, auditoria,
  suporte a anexos, controle de abuso, pagamento e
  integração com CRM/Sites/Performance, conforme escopo
  comercial e legal aprovado.
- As roles são criadas somente no container PG18;
  `F38SyntheticRequestWriter` não está registrado em
  rotas/controllers/providers nem consulta clientes.
  Nenhum novo backend Node.js é usado.
- Senhas SCRAM são geradas por job e o container usa somente
  `127.0.0.1` em porta aleatória. Ao final do CI, Docker
  e dados são destruídos; nenhuma VPS, Cloudflare ou
  dado real é atualizado.
- O workflow mantém as regressões F22–F36 e o
  `pg_dump/pg_restore` fictício F32, agora também
  incluindo as tabelas F38 no dump primário de teste.
- Esta etapa NÃO é autorização para migração em produção.
  **PR DRAFT sem merge/deploy.** P0/D02/D03, SEC-R03/Z4,
  T3/OPEN, IAM/MFA e AppSec/DBA continuam bloqueados.

Apenas marcar PASS mediante jobs PHP 8.3/8.4 efetivamente
executados e logs com asserções na revisão exata; falhas
da plataforma GitHub sem jobs não comprovam a validade
nem a invalidade do código.
