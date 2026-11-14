# F1-387 — Emissor de atestado comercial Ed25519 do Portal para Imagine Sites

**Escopo:** código de domínio realista, mas **não ligado a produção**. Este PR não cria chaves reais, pagamentos, sessões de cliente, endpoints HTTP ou permissões de publicação.

## Integração realizada

O Imagine Sites F1-386 já valida um atestado de elegibilidade do Portal assinado Ed25519, mas não havia emissor correspondente no Portal. A classe `PortalEd25519EntitlementIssuer` implementa esse formato **campo a campo, na ordem canônica exigida pelo Sites**, incluindo o mesmo hash semântico de `SiteProjectCommand`.

O emissor recebe um `orderRef` opaco, um `operationId`, uma chave de idempotência, o tempo do serviço e uma chave privada binária Ed25519 **injetada pelo componente de segurança**. A classe não carrega automaticamente secrets de `.env`, arquivos, headers HTTP ou parâmetros de navegador.

Três fontes independentes e obrigatórias são consultadas **a cada emissão**:

1. `PortalSiteContractSource`, já existente no Portal, deve provar que a ordem possui contrato elegível Onepage ou Landing Page, pertencente à organização correta e na revisão atual.
2. `PortalCurrentSettlementSource`, novo contrato para o ledger financeiro oficial/PSP, deve emitir `PortalSettlementEvidence` apenas após verificar liquidação atual, ausência de estorno e identidade exata de tenant, pedido, contrato, revisão e produto. Um campo simulado `payment='approved'` **não basta**.
3. `PortalCurrentRevocationEpochSource`, nova origem independente de revogação, retorna epoch positivo somente para uma concessão atualmente válida.

O emissor compara os três resultados, limita o TTL a 1–120 segundos, assina os bytes canônicos e retorna `sites.portal-entitlement.ed25519.v1` com `issuer=imagine-portal` e `audience=imagine-sites`. Falta, recusa ou indisponibilidade de qualquer origem interrompe a emissão.

As implementações disponíveis `DenyingPortalSettlementSource` e `DenyingPortalRevocationEpochSource`, junto com a `DenyingPortalSiteContractSource` preexistente, **negam todas as operações por padrão**. Não existe ServiceProvider operacional nem rota que use a classe.

## Teste cruzado com a implementação real do Sites

O workflow `portal-f1-387-ed25519-sites-parity.yml` verifica a classe do Portal com a implementação **original do Sites**, fixada em `rafhael100/imagine-sites@4a2b7e4503cfb0b8d7bfb9d0d4bb7ea43c4198d5` (F1-386). A suíte não reimplementa o validador do outro repositório. Em PHP 8.3 e 8.4, com par de chaves efêmeras apenas na memória do CI, verifica assinatura válida, equivalência de fingerprint, PSP e revogação independentes, replay sem cache de autoridade e negações de prova adulterada, contrato ausente, liquidação incompleta, revogação, expiração, chave inválida e discrepância entre registros.

**Esses testes usam exclusivamente empresas, pedidos, PSP e epochs fictícios.** A assinatura criptográfica é genuína, mas a fonte financeira não está integrada a fornecedor real.

## Gate ainda pendente para clientes

São necessárias implementações operacionais dos três adaptadores com identidade S2S, API de PSP/cobrança escolhida, política de reconciliação de Pix/cartão e cancelamentos, epoch independente e gestão de chaves em vault/KMS. O Sites também deve revalidar liquidação e revogação de maneira independente ao receber a mensagem e registrar idempotência/nonce duráveis sob login SQL autorizado. Não basta verificar Ed25519.

A fonte do Portal precisa estar vinculada ao seu banco de contratos e à identidade autenticada do cliente, com LGPD, auditoria, autorização, exigências do plano, aprovação do briefing e operações de suporte. Até tais dependências existirem, nenhuma rota deve habilitar emissão, e nem a existência de um atestado pode liberar o publisher.

**DRAFT/NO-GO. Sem merge, deploy, DNS, Cloudflare, Leaseweb, pagamento real, dados de cliente ou despesa externa.**
