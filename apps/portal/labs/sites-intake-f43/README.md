# PORTAL-F43 — Revisão versionada de conteúdo de site com aprovação *somente simulada*

**09/11/2026 · PHP puro · não registra cliente, autorização, assinatura ou publicação.**

## O problema de produto

O Imagine Sites deverá gerar conteúdo com apoio de IA, mas
**a publicação exige aprovação documentada do cliente**.
Aprovar um conteúdo e depois modificá-lo torna a decisão
anterior insuficiente. Por isso, o Portal precisa ligar a
decisão à versão exata do briefing e do conteúdo, e não
apenas ao ID do site.

F43 especifica esse comportamento em **memória e fixtures**,
sem presumir a existência de um usuário autenticado.

## Contrato de domínio

- `SiteContentCandidate`: texto ilustrativo de título,
  descrição e CTA, vinculado ao `SiteBriefDraft` F42
  (organização, produto Onepage/Landing Page, referência
  da solicitação, revisão do briefing) e à revisão própria
  do conteúdo. Valida tamanho, UTF-8, caracteres de controle,
  texto invisível e HTML. Calcula SHA-256 determinístico
  de todos os componentes; o hash é **impressão digital,
  NÃO assinatura de autoria ou aceite**.
- `SiteReviewDecision`: três saídas editoriais possíveis:
  `approve`, `request_changes` e `reject`.
- `SiteReviewCommand`: somente DEMO, contém ID de comando,
  empresa, referência do site, SHA-256 do conteúdo exato,
  `DEMO-REVIEWER-A/B`, revisão esperada e decisão.
  O ator não é login nem prova de consentimento.
- `DemoSiteReviewJournal`: uma instância representa
  **uma única versão de conteúdo**; compara empresa,
  referência, hash e ator DEMO **antes de procurar
  idempotência**. Exige revisão esperada correta; registra
  uma decisão terminal por candidato com revisão 1,
  eventId de comando, hash, empresa, produto e carimbo
  temporal sintético. Replay idêntico retorna mesmo
  resultado, sem segundo evento; reenvio com o mesmo ID e
  decisão diferente falha. A nova versão de conteúdo
  começa em **novo diário `awaiting_review_demo`**,
  sem herdar a aprovação anterior.
- `SiteReviewOutcome`: informa resultados editoriais
  `approved_demo`, `changes_requested_demo` ou
  `rejected_demo`. Mesmo quando `isSimulatedApproval()`
  é verdadeiro, `mayPublishSite()`,
  `mayActivateContract()` e
  `isVerifiedClientConsent()` são sempre **false**.
- `DemoSiteReviewJournalTest.php`: 12 testes PHP:
  aprovação fictícia e negação de publicação; repetição
  idempotente; conflito de ID; edição de conteúdo e
  revisão do briefing invalidando hashes anteriores;
  mudanças/rejeição terminais; revisão stale;
  mismatch de empresa, documento, hash e ator; B
  isolada de A; HTML/Unicode inválidos; normalização
  determinística e falha em falsificação de comandos.

## Homologação pendente antes de publicação

Não existe login Portal-only, verificação de identidade,
MFA, sessão ativa, associação de organização, permissão,
assinatura de aceite, prova de visualização do conteúdo,
registro de IP, política de retenção LGPD, trilha durável
de auditoria ou integração com o motor Imagine Sites.
Nenhuma aprovação DEMO tem efeito no mundo real.
A futura solução deverá exigir identidade verificada
e integrar a aprovação com o artefato HTML real (hash),
controlar concorrência/rollback, e deixar a publicação
bloqueada até autorização válida e política de domínio
aprovada.

**Não há rotas, controllers, migrations, agendamentos,
VPS, Cloudflare, CRM, sites publicados, dados pessoais ou
gastos novos. PR DRAFT, sem merge/deploy.** Gates
P0/D02/D03, SEC-R03/Z4, T3/OPEN, IAM/MFA, AppSec/DBA
permanecem bloqueados.
