# PORTAL-PAY-04 — Produtor Portal de contrato de revisão CRM (offline)

Empilhado em #789, #788 e #784. O produtor \`PortalGatewayReviewExporter\` converte um resultado de \`GatewayRoutingPreview::quote\` para \`imagine.payment-routing.review.v1\`. É uma mensagem HMAC de **revisão sem autorização financeira** consumível, em princípio, pelo candidato \`PortalGatewayDecisionEnvelope\` no CRM (PR #581).

- Cobranças, assinaturas, faturas, ativação e webhooks permanecem desabilitados.
- A mensagem carrega organização e pedido, PSP, método, valor bruto e taxa estimada em centavos, versão comercial e revisão da política.
- Campos \`can_charge\` e \`can_activate\` são **sempre falsos**.
- Teste verifica HMAC canônico compatível entre os dois repositórios com valores exclusivamente fictícios. O uso de uma assinatura de teste não equivale a segurança operacional.
- Sem rota HTTP, sem conexão ao CRM, sem escrita persistida, sem credenciais bancárias.

Próximos gates: autenticação de serviço, chave secreta operacional com rotação, replay/nonce, janela temporal, autorização por tenant, conciliação e implantação aprovada. C6 permanece bloqueado até documentação contratual e homologação.

**DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-KEYS.**
