# PORTAL-F44 — Revisão editorial de Sites com recibo SQL atômico

**09/11/2026 · PostgreSQL 18 efêmero em GitHub CI · sem cliente, API, publicação ou assinatura real.**

## Problema e abordagem

O F43 modela em PHP puro uma revisão fictícia de conteúdo gerado
para Onepage/Landing Page, vinculada ao **hash da versão exata**.
F44 testa a possibilidade de preservar a mesma semântica em
transação PostgreSQL, evitando gravar um status aprovado sem
o recibo correspondente.

Uma revisão é **apenas uma simulação editorial**: nem um status
`approved_demo` nem um hash SHA-256 assinam o aceite de um
cliente ou autorizam produção.

## Artefatos

- `schema.sql`: schema f44 com `site_review` e
  `review_receipt`, duas contas SQL efêmeras
  `f44_reviewer_a`/`f44_reviewer_b`; ENABLE/FORCE RLS
  em ambas as tabelas e políticas `session_user` por
  organização A/B; PK de site e chave idempotente de
  comando por empresa, FK para o registro do site.
- `F44SyntheticSiteReviewWriter`: usa a conta SQL tenant-bound,
  verifica login e role restrita, metadata FORCE RLS,
  e faz `SELECT ... FOR UPDATE` do candidato atual.
  Compara o SHA-256, a revisão do briefing e a revisão
  do conteúdo com a **versão que está na base** antes
  de buscar recibos anteriores. Reenvio idêntico retorna
  a decisão original; conflito de ID não altera dados.
  Uma decisão nova exige `awaiting_review_demo` e
  revisão esperada exata. Atualização de status/revisão
  e INSERT do recibo ocorrem na **mesma transação**,
  e qualquer falha resulta em ROLLBACK.
- `F44SyntheticReviewWriteResult`: não autoriza
  publicação, contrato, cobrança ou consentimento
  verificado, mesmo se a decisão de laboratório for
  `approved_demo`.
- `F44SyntheticSiteReviewIntegrationTest.php`:
  13 cenários reais PG18: aprovação/rejeição A/B,
  reenvio idempotente, conflito de comando, revisão
  de conteúdo/briefing que invalida aprovação antiga,
  decisão única terminal, stale revision, ator DEMO
  errado, login SQL de outra empresa/admin, perda
  de FORCE RLS, role BYPASSRLS, acesso cross-tenant,
  perda de INSERT em receipt causando rollback
  do status e do revision, lock timeout e negação
  de DELETE/ALTER/UPDATE do conteúdo por role restrita.
- Workflow de integração PHP 8.3 e 8.4: carrega
  `schema.sql` **somente no container primário PG18**
  e gera senhas SCRAM aleatórias para as duas roles,
  com os demais laboratórios F22–F40 preservados.
  O backup lógico F32 permanece sobre fixtures.

## Restrições críticas

Não existe servidor de IA, texto real de cliente,
site publicado, assinatura digital, prova de
visualização do conteúdo, confirmação de pessoa
autenticada, MFA, revogação operacional, política de
domínio, backup/PITR homologado, consentimento LGPD,
logs de auditoria confiáveis, controle de anexos,
aprovação por representante legal ou separação de
papéis comerciais. Um ator `DEMO-REVIEWER-*` **é
dado de teste**, não credencial de cliente.

Um admin do container pode substituir a linha do
conteúdo; estes ensaios não são atestação
independente de integridade contra rollback.
O teste de FORCE RLS/policy é de metadata e
**não verifica integralmente o SQL dessas políticas**.

**Nenhum controller, rota HTTP, service provider,
migração, worker, VPS Leaseweb, Cloudflare, domínio
ou fornecedor externo foi alterado. PR DRAFT sem
merge/deploy.** P0/D02/D03, SEC-R03/Z4,
T3/OPEN, IAM/MFA, AppSec/DBA continuam abertos.
