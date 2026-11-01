# Assinatura incluída na compra

Novos pedidos Onepage usam o modelo subscription-v2. A contratação autoriza R$ 330 imediatamente, R$ 330 em D+30 e R$ 330 em D+60, além de assinatura mensal de R$ 79,90 com primeiro vencimento em D+90, independentemente da publicação. Os dias são calculados a partir da contratação e conferidos no fuso de Brasília. As três cobranças são transações separadas programadas no cartão, e não um parcelamento bancário que compromete o limite total.

Após a aprovação do primeiro pagamento, a assinatura Asaas é criada automaticamente com o cartão tokenizado e nextDueDate D+90. Não há aprovação da Imagine por cliente. As parcelas futuras são executadas pela fila somente no dia devido; a recorrência mensal posterior é gerada pelo Asaas. Mantenha a aplicação e o worker ativos.

Atualização de cartão e retomada: o portal consulta uma prévia com o valor que poderá ser cobrado agora. A troca de cartão não antecipa mensalidades futuras. Quando existe cobrança vencida, o cliente autoriza explicitamente a tentativa no pagamento existente; a implementação regulariza uma cobrança vencida por operação e mantém as demais visíveis. Alterações de valor ou situação exigem nova conferência antes de autorizar. A recorrência é retomada sem liberação administrativa, após a confirmação do Asaas.

Respostas inconclusivas entram em conciliação automática. Não se repete uma criação financeira cegamente. Webhooks e consultas por referência recuperam cobranças/assinaturas existentes. IDs de operações impedem repetir a mesma regularização. Recusa no cartão fica identificada para atualização pelo cliente; disputas/estornos da compra inicial impedem novas parcelas e suspendem a geração de mensalidades.

O formulário envia os dados do cartão transitoriamente ao servidor e ao Asaas para tokenização. Não registra PAN ou CVV. Tokens opacos são criptografados com AES-256-GCM usando BILLING_TOKEN_KEY do servidor. A API retorna apenas bandeira/final do último cartão informado. HTTPS é obrigatório em produção; tokenização precisa estar habilitada na conta Asaas.

Contratos aceitos anteriormente mantêm o modelo legacy e seus valores. Não há alteração retroativa de preço. A mesma atualização pelo cliente pode retomar uma assinatura legada, com concordância às condições daquele contrato. Novas contratações não usam o checkout opcional legado.

Configuração: ASAAS_API_KEY, ASAAS_WEBHOOK_TOKEN, emitente e BILLING_TOKEN_KEY de 64 caracteres hexadecimais no ambiente privado. APP_TRUST_PROXY só deve ser true atrás de proxy local que sobrescreva X-Forwarded-For. Dados de demonstração e chaves não devem ser copiados para produção.

Limites: sem credenciais e habilitação, formulários financeiros ficam desativados; não há homologação bancária real nesta entrega. O checkout público avulso em checkout.html continua uma prévia; a contratação integrada está no pedido autenticado do portal. A política existente de encerramento do serviço e a publicação/retirada do site não são executadas automaticamente por esta rotina.

Referências oficiais:
- https://docs.asaas.com/docs/criando-assinatura-com-cartao-de-credito
- https://docs.asaas.com/reference/tokenizacao-de-cartao-de-credito
- https://docs.asaas.com/changelog/novo-endpoint-atualiza%C3%A7%C3%A3o-de-cart%C3%A3o-de-cr%C3%A9dito-em-assinaturas-sem-cobran%C3%A7a-imediata
- https://docs.asaas.com/reference/pagar-uma-cobranca-com-cartao-de-credito
