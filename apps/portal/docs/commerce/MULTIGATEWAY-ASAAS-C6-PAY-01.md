# PORTAL-PAY-01 — Roteamento multi-gateway Asaas/C6 (candidato offline)

Decisão de produto aprovada em 14/11/2026: **manter Asaas como PSP padrão**, preparar **C6 Bank** como segundo PSP e permitir escolha **manual** ou por **menor custo elegível**, por meio de configuração administrativa auditável. O Portal é o dono da política de cobrança; CRM, Sites e Performance consomem contratos de faturamento, não credenciais de gateways.

## Entrega desta PR (não operacional)

- \`App\Domain\PaymentRouting\GatewayRoutingPreview\` é uma função pura e isolada. Recebe uma fotografia de configuração aprovada e estima tarifa de Pix, boleto, cartão ou Pix Automático em BRL, sem acessar rede, banco, segredos ou APIs.
- No modo \`manual\`, usa somente o gateway preferencial **se explicitamente habilitado, aprovado, saudável e com taxas válidas**. Se indisponível, falha fechado.
- Em \`lowest_cost\`, considera apenas PSPs elegíveis **para o método de pagamento solicitado**; desempata por menor prazo de liquidação e preferência administrativa. Preços são informados em centavos e basis points, sem floats.
- O contrato de resultado identifica gateway, método, versão de tabela, valor bruto, estimativa da taxa, valor líquido estimado e prazo. **Tarifa estimada não substitui tarifa efetiva conciliada**.
- \`enforcePinnedGateway\` rejeita migração de um registro financeiro já criado. Cobranças, webhooks, estornos, parcelamentos e assinaturas devem permanecer vinculados ao PSP original.

## Regras para evolução até produção

1. **Configuração por tenant e modalidade** somente por administrador autorizado, com autenticação forte, registro de quem/por que/quando, versionamento de taxas e opção de rollback. Configuração armazenada e revisada; nunca carregada de parâmetro do checkout.
2. **C6 só passa a elegível** após contrato, confirmação dos produtos realmente disponíveis na conta (Pix, boleto, cartão, Pix Automático), documentação e credenciais por ambiente, testes de integração e aprovação financeira/segurança. Os valores de testes não são ofertas comerciais reais.
3. **Persistência atômica**: pedido/organização, PSP selecionado, método, custo estimado, versão da tarifa, referência externa e chave de idempotência ficam imutáveis na intenção de pagamento antes de qualquer chamada de criação.
4. **Sem troca silenciosa** após um POST de resultado desconhecido. Timeout, HTTP 5xx, webhook pendente ou conectividade incerta exigem consulta e reconciliação no PSP inicial. Fallback é permitido para uma *nova* intenção apenas se o sistema comprovar que não existe cobrança no original.
5. **Conciliação separada por PSP**: webhook autenticado com segredo específico, \`(provider,event_id)\` deduplicado, conferência independente via API, ledger append-only, reembolso e contestação no PSP proprietário, idempotência de integração CRM.
6. **Assinaturas ativas não migram automaticamente**. Exigem autorização do cliente quando aplicável, novo meio de pagamento e controle da janela de transição para evitar duplicação. Pix Automático exige mandato/autorização própria conforme provedor.
7. **Custo efetivo** em produção incluirá taxas fixas, percentuais, antecipação, parcelamento, prazo de liquidação, limites comerciais e condições específicas. Nunca deduzir que menor taxa nominal = menor custo total.
8. **Homologação** por modalidade com falhas, reenvios, duplicatas, reembolso, cancelamento e fluxo de cobranças futuras. Preservar gates SEG/DBA, IAM/LGPD, antifraude, tesouraria, fiscal, CI e aprovação de deploy.

## Limites do recorte

Esta PR é **DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-KEYS**. Não modifica endpoints de pagamento, não ativa C6 e não altera Asaas Sandbox, planos, assinaturas ou valores faturados. A PR integra apenas **modelo de seleção e testes determinísticos**. Exige trabalho posterior: persistência, painel admin, adaptadores PSP, conciliação e testes reais com credenciais em ambiente seguro.

### Critérios de aceite do protótipo
- Default Asaas permanece, e nenhum cliente muda de PSP;
- C6 desabilitado e sem tarifas contratadas nunca pode ser selecionado;
- Comparação Pix e boleto é independente;
- Falha e taxa incompleta não roteiam para PSP desconhecido;
- Mesmo gateway não pode ser alterado em registro financeiro existente;
- CI \`php artisan test --filter=GatewayRoutingPreviewTest\` deve executar dentro do app Laravel antes do merge.
