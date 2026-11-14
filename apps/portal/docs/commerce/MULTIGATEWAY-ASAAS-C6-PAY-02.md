# PORTAL-PAY-02 — Revisões e ciclo de vida de cobrança (apenas domínio offline)

Recorte empilhado sobre PORTAL-PAY-01 / PR #784. **DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE**.

## Entrega
- \`GatewayPolicyReview\`: valida estrutura completa da política e produz revisão hash SHA-256, referência do operador e data para trilha de auditoria candidata. Revisões obsoletas recusadas. Sem alterar configuração ativa. C6 habilitado requer referências externas para contrato, homologação sandbox, SEG, financeiro e liberação operacional. Essas referências são **dados de revisão**; assinatura, legitimidade e permissão do operador PRECISAM ser comprovadas pelo IAM e persistência reais antes de produção.
- \`PaymentIntentLifecyclePreview\`: estado isolado \`planned -> submitting -> pending -> settled\`; escrita incerta \`submitting -> uncertain -> reconciled_created/pending | verified_absent/absent\`. Uma intenção jamais muda de PSP. Repetição de fato confirmado com mesmo identificador é idempotente. Não é permitido disparo repetido após timeout e reconciliação indefinida.
- Testes determinísticos de revisão concorrente, C6 sem evidência, depreciação da preferência manual, transições financeiras, duplicidade e PSP diferente.

## Futuro operacional obrigatório
1. Contrato de termos comercialmente aprovados por modalidade, vigência e taxas reais com hash/revisão imutável.
2. Integração do painel à autenticação forte, autorização e tenant do Portal. Referências de homologação exigem evidências assinadas e verificadas, não campos livres.
3. Banco PostgreSQL com transação, RLS por tenant, lock em intenção financeira, índices únicos de idempotência por PSP e por organização, segredo em vault e ledger imutável.
4. Mecanismo de comparação por custo total incluindo antecipação, parcelamento, liquidação, taxas escalonadas, descontos negociados e limites; em \`credit_card\`, distinguir parcelas e liquidação.
5. Webhooks Asaas e C6 autenticados e deduplicados por \`(provider,event_id)\`; reconciliar com leitura independente antes de liberar pedidos. Dúvida sobre estado da escrita nunca autoriza fallback automático.
6. Testes e credenciais C6 obtidos no portal do desenvolvedor. C6 não tem endpoints concretos aqui: **não inventamos URLs, autenticação nem payloads**.
7. Rollout com feature flag desligada, plano de rollback, auditoria, revisão independente, aprovação de deploy e observabilidade.

Referências: https://www.c6bank.com.br/apis-integracao/ ; https://docs.asaas.com . O anúncio da API não equivale ao credenciamento da Imagine.
