# PORTAL-F45 — Confirmação editorial de Sites somente para leitura (sem publicar)

**09/11/2026 · Laboratório PostgreSQL 18 descartável · PHP/Laravel.**

## Escopo

As etapas F43 e F44 comprovam comportamento sintético de uma
decisão de revisão de conteúdo gerado para Onepage/Landing Page,
gravando decisão e recibo em uma transação da fixture PostgreSQL.

F45 adiciona um **verificador separado, exclusivamente de leitura**,
que compara o conteúdo que está armazenado, a decisão `approved_demo`
e o recibo do mesmo hash/revisão. O verificador não publica nenhum
site e não afirma que houve aceite por cliente identificado.

## Implementação

- `read-consistency-schema.sql`: duas novas roles
  `f45_consistency_reader_a/b LOGIN NOINHERIT`, com
  políticas RLS `FOR SELECT` para cada empresa na tabela
  F44 de conteúdo e recibos. As contas recebem USAGE de schema
  e SELECT, **nenhum INSERT, UPDATE, DELETE ou DDL**.
- `F45SyntheticReleaseConsistencyReader`: abre
  `REPEATABLE READ, READ ONLY`, timeout de 2 segundos,
  confere `session_user=current_user` e role sem
  `SUPERUSER/BYPASSRLS/INHERIT`. Verifica metadata das
  duas tabelas com `ENABLE/FORCE RLS` e **exatamente
  quatro policies** (duas roles F44 + duas F45). 
  Consulta o conteúdo atual da empresa, confere SHA-256
  e revisão do briefing e do conteúdo, exige
  `approved_demo` e encontra **um único recibo** para
  o hash corrente com `simulated_decision=approve`,
  status `approved_demo` e revisão correspondente.
  Dados e registros antigos permanecem inaptos.
  Excesso de recibos, campo divergente, fonte ausente,
  falta de permissão ou role incorreta são recusados.
- `F45EditorialConsistencyResult`: motivo fechado de
  `consistent_approved_demo` ou recusa. Mesmo
  `isConsistentInLab()=true`, todos
  `mayPublishSite()`, `isVerifiedClientApproval()`
  e `mayAuthorizeContractOrCharge()` retornam **false**.
- `F44SyntheticSiteReviewWriter`: manutenção da checagem
  de drift de policies, agora exigindo 2 na fixture F44
  ou exatamente 4 quando F45 estiver habilitado.
  Nenhuma mudança no estado real de um cliente.
- `F45ReleaseConsistencyIntegrationTest.php`: 13 cenários
  em duas bases PostgreSQL efêmeras por job do workflow.
  Aguardando revisão, resultado consistente do F44,
  isolamento A/B, role SQL errada, hash anterior a mudança
  de conteúdo, alteração do briefing, aprovação forjada
  sem recibo, recibo contraditório, dois recibos para
  a mesma versão, FORCE RLS retirado, SELECT revogado,
  conta de leitura incapaz de fazer UPDATE/INSERT/DELETE/DDL
  e candidato removido.

## Threat model e avisos

O PostgreSQL do laboratório **não é autoridade de consentimento**:
uma conta privilegiada pode forjar conteúdo, decisão e
recibos correspondentes. Além disso, a própria role F44 de
revisão de laboratório ainda possui DML permitido para as
transações fictícias; uma conta comprometida pode mentir
sobre a decisão do usuário. A checagem de policy_count
não prova integridade do texto SQL de policies.

O verificador nunca acessa HTML/asset real, não verifica
conteúdo realmente apresentado ao cliente, identidade
de cliente, MFA, prova de aceite, assinatura digital,
retenção LGPD, motor Sites, segredos, filas, auditoria,
custódia independente ou defesa SEC-R03/Z4.
`consistent_approved_demo` significa **apenas que os
dados sintéticos atuais concordam entre si**.

Não há rota HTTP, controller, service provider,
migração produtiva, pagamento, deploy, VPS Leaseweb,
Cloudflare ou recurso externo habilitado.
**PR DRAFT, sem merge.** P0/D02/D03, SEC-R03/Z4,
T3/OPEN, AppSec/DBA e IAM/MFA seguem pendentes.
