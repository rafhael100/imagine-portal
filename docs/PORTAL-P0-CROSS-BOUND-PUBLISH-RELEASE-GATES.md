# Imagine Portal P0 — vínculo obrigatório entre contrato e versão publicada (F1-395)

## Motivo da entrega

Os dois protocolos de emissão existentes eram independentes: (1) o atestado financeiro/comercial F1-387 é direcionado a um pedido e contrato; (2) o aceite editorial F1-394 é direcionado à versão específica de um site. A existência dos dois atestados **não prova automaticamente** que o pedido e a versão editorial pertençam à mesma contratação. Para impedir combinações incorretas, a F1-395 introduz vínculo **servidor-servidor e default-deny**, sem criar endpoint ou publicação.

## Regras executadas no Portal

- `PortalSitesReleaseAssignment` representa a ligação imutável e versionada entre empresa, pedido, contrato/revisão, produto elegível, site, release, SHA-256 do artefato, revisão editorial e versão da associação. Seus campos **não** podem vir de formulário, webhook ou token assinado.
- `PortalCurrentSiteReleaseAssignmentSource` deve resolver a associação atual a partir de uma autoridade de pedidos/contratos e release operada no servidor, com regras próprias de IAM, tenant e validação antifraude. **A implementação incluída nesta PR sempre nega**: `DenyingPortalSiteReleaseAssignmentSource`.
- `PortalSitesReleaseProofBundleBuilder` somente reúne dois atestados se o vínculo da fonte confiável corresponder integralmente aos atributos de ambos os emissores. Os emissores continuam responsáveis pelas suas fontes oficiais de contrato, liquidação, revogação, MFA, aceite explícito e versão editorial.
- São necessárias **duas chaves secretas Ed25519 distintas**, obtidas por uma futura chamada autenticada a um cofre de segredos. Chave ausente ou reaproveitada para os dois protocolos é recusada.
- Após emitir ambos os atestados, a associação confiável é consultada **novamente**, para recusar mudanças de contrato/release ou `bindingRevision` ocorridas durante a emissão. Essa dupla leitura diminui risco de concorrência, **não elimina mudanças posteriores**.
- Resultado retorna apenas `commercialProof` e `clientAcceptanceReceipt` para verificação posterior. **Não há** `allowed=true`, atualização de pedido, cobrança, criação de site, aprovação implícita ou operação de publish.

## Verificador original do Imagine Sites

A CI consome as duas classes **originais**, não réplicas:
- `IndependentPortalCommercialGateway` do Sites F1-386: assinatura comercial, PSP e revogação independentes.
- `SignedPortalClientApprovalGate` do Sites F1-391: assinatura do aceite da versão exata e testemunha de revogação independente.

A revisão usa a SHA fixa `1bb80823a112cd935f4498e36ad9e0da853f851f` do repo `rafhael100/imagine-sites`, PHP 8.3/8.4, identidades e chaves **fictícias**, sem rede Asaas. O teste cobre aceite sem pagamento, pagamento sem aceite, alteração de HTML, substituição do pedido, tenant errado, revision divergente, perda de registro, revogação de contrato, falta de MFA, indisponibilidade da fonte, `bindingRevision` alterada entre leituras e chave igual para ambos os protocolos.

## Trabalho restante para homologação/produção

Esta etapa não cria a fonte operacional de mapeamento, não atribui uma contratação de cliente real a um site, não assegura transação distribuída entre Portal/Sites, não registra IAM/MFA, não conecta a conta Asaas Sandbox, não realiza deploy, não implementa outbox/nonce e não ativa aprovação em interface. O Sites deve verificar os atestados com suas próprias autoridades a cada tentativa de publicação, incluindo proteção contra repetição e revogação **após a emissão**. A governança de versão, controle financeiro de parcelas, backups e recepção do aceite continua pendente de validação operacional.

### Estado

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-BILLING / NO-PUBLISH**, mesmo que a CI conclua com sucesso.
