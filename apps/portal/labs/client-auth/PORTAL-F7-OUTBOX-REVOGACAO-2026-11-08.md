# PORTAL-F7 — Outbox sintético e bloqueio de efeitos após revogação

**Data:** 08/11/2026. **Status:** desenvolvimento autônomo autorizado, condicionado a teste e revisão. Laboratório PostgreSQL 18 descartável, sem usuários reais, integração externa, deploy ou merge. Branch filha do PR #91.

## Problema tratado

Um pedido criado corretamente pode deixar um evento em fila para execução posterior. Sem revalidação no worker, a revogação da organização ou da conta entre **enqueue** e **consume** não impede que uma tarefa antiga prossiga. O F7 ensaia uma barreira colaborativa, sem presumir que qualquer prova em CI substitua a segurança do ambiente real.

## Contrato técnico implementado apenas no laboratório

- `lab_portal_outbox` contém `org_id`, `actor_id`, `request_id`, `event_type`, `membership_revision`, `account_revision`, `status` e `attempts`, com **chave primária composta (org_id,id)**, FK composta para pedido e vínculo e UNIQUE(organization_id, request_id, event_type). Nenhum payload de cliente.
- `lab_portal_delivery_receipts` contém somente um recibo **sintético** por (org_id,event_id), com FK composta para o evento. Recebimento de teste, não e-mail, webhook, cobrança ou notificação. INSERT de recibo + mudança do estado do evento ocorrem **na mesma transação PostgreSQL**, evitando declaração enganosa de exatamente uma entrega externa.
- A role `portal_lab_runtime` pode inserir/consultar somente eventos de seu escopo autorizado conforme policy RLS; **não pode marcar como delivered**. Ela usa o lock F4 compartilhado no vínculo antes de consultar permissões, versão da associação e recurso da própria empresa.
- A role **distinta** `portal_lab_worker` apenas lê/grava status de eventos, cria recibos artificiais e consulta estados de usuário/empresa/membership. Não recebe privilégio de atualizar membership nem de abrir tickets; a criação do papel é parte do bootstrap descartável.
- `LabOutboxWorker::consumeOne` seleciona candidato pendente, adquire lock transacional compartilhado da concessão **antes** de FOR UPDATE SKIP LOCKED no evento, revalida membership ativo e mesma revisão, conta ativa com mesma revisão e empresa ativa. Se qualquer condição falhar, evento é marcado **blocked**, sem recibo. Caso continue válido, grava recibo DB-local e marca delivered, em transação única. Reenvio não deve produzir efeitos adicionais.
- `LabMembershipFence::revoke` passa a incrementar a `revision` do membership quando revoga; uma reativação posterior de teste **não reabilita** evento antigo.
- As rotas HTTP de F6 não foram ampliadas: F7 funciona exclusivamente no CLI PHPUnit com SQL de laboratório. Não há worker de produção, cron ou integração acionada.

## Matriz de testes

| Teste | Evidência esperada |
|---|---|
| F7-Q01 | Enqueue idempotente antes de consumo; worker produz **um único recibo DB-local** e próximo poll é vazio. |
| F7-Q02 | Revogação de membership após enqueue e antes de consume: **blocked**, zero recibos. Reativação não reexecuta evento já barrado; revisão incrementada. |
| F7-Q03 | Revisão de conta alterada após enqueue ou organização suspensa: worker bloqueia ambos, zero recibos. |
| F7-Q04 | Runtime não pode falsificar evento de outro tenant sem GUC/authority; worker não pode alterar membership. |
| F7-Q05 | Duas **conexões PostgreSQL distintas**: `FOR UPDATE SKIP LOCKED` impede segundo worker de capturar registro bloqueado; depois de rollback, um worker o consome. |
| F7-Q06 | Regressões F1–F6 permanecem aprovadas; F6 HTTP+PG testa ambiente isolado sem novas rotas. |

**Não chamar esses testes de cobertura de SQS/Redis/Cloudflare/n8n ou exatamente uma entrega de sistema externo.**

## Limites e riscos ainda em aberto

1. **Ator Z2 comprometido, DBA/owner e UPDATE direto:** scripts que ignorem `LabMembershipFence` podem furar a coordenação. A política RLS usa GUCs definidas pela aplicação; isso não prova origem confiável se o app for comprometido.
2. **Rollback completo de backup:** se outbox, associação e sua revisão voltarem simultaneamente para uma cópia anterior, a revisão no mesmo banco também volta. A F7 **não possui uma autoridade de geração/revogação independente do snapshot**; SEC-R03/T3 permanece aberto. Antes de produção, é obrigatória uma barreira externa verificável e fail-closed para restauração antiga.
3. **Efeitos externos:** na vida real, enviar uma mensagem/pagamento e confirmar outbox não são uma transação PostgreSQL única; pode ocorrer entrega duplicada após crash. Necessários idempotência no destino, reconciliação e logs com retenção/privacidade apropriadas.
4. **Múltiplos workers:** dois PIDs com `SKIP LOCKED` comprovam apenas exclusão na seleção, não cobertura de todos os caminhos de reprocessamento, erro de rede, rollback e tentativas paralelas em múltiplos clusters.
5. **Canais e serviços:** nenhum fluxo de outbox foi ativado para Imagine CRM, Sites, Performance, Universidade, n8n ou clientes reais. Não inferir automação comercial real de um recibo fictício.
6. **Segurança de conta:** MFA real, cookies HTTPS Secure, recuperação, sessões distribuídas, antifraude, UX de cliente, auditoria e LGPD ainda em planejamento.

**Gates preservados:** P0, D02-SEC, D03/RLS, SEC-R03/T3 e release abertos; `main`, `dev`, VPS, Cloudflare e produção intocados. Sem merge automático, alterações destrutivas, segredos reais ou despesas.

**Próximo recorte:** ensaio adversarial de restore anterior à revogação, com uma autoridade de corte **fora do snapshot restaurado** (sintética neste estágio), e verificação de fail-closed nos workers, API e sessões. O plano de recuperação operacional e RPO/RTO depende de prova e aprovação crítica.
