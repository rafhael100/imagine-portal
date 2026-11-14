# F1-389 — Resolvedor privado de atestados Portal → Sites

**Objetivo:** preparar a lógica do endpoint interno esperado pelo cliente mTLS do Imagine Sites F1-388 sem abrir uma rota HTTP, sem simular uma autenticação de produção e sem liberar contratação.

## Camada implementada

A classe `apps/portal/app/Domain/SitesIntegration/PortalSitesEntitlementResolver.php` recebe a consulta no formato `sites.portal-proof-fetch.v1` esperada pelo cliente `PortalProofMtlsHttpSource` do Sites. O corpo contém apenas `schemaVersion`, `tenantRef`, `orderRef`, `contractRef`, `contractRevision`, `productCode` e `operationFingerprint`. Não existem parâmetros que permitam ao consumidor marcar pagamento como aprovado, escolher papel, acessar outra organização ou alterar a autoridade financeira.

A classe não usa headers de navegador, cookies, dados de sessão do cliente, nem o campo `tenantRef` como prova de identidade. A identidade do serviço solicitante vem da interface independente `PortalVerifiedSitesServiceIdentity`; o provedor padrão `DenyingPortalVerifiedSitesService` retorna falso. O futuro adaptador real deve validar o certificado mTLS e a associação dele a um principal autorizado no IAM do Portal, sem confiar em cabeçalhos de TLS enviados pelo cliente sem autenticação do proxy.

Somente depois da confirmação de identidade, o resolvedor chama `PortalEd25519EntitlementIssuer` da F1-387. O emissor consulta as três fontes independentes de contrato, liquidação e revogação e assina a prova com Ed25519. O resolvedor compara todos os campos relevantes ao pedido original, inclusive o fingerprint e a revisão, e rejeita qualquer discrepância com erro sanitizado. **Ter uma assinatura válida para outro contrato não autoriza esta requisição.**

O método `parseJsonRequest` aplica limite de 2048 bytes e JSON estrito. A fonte do contrato é exclusivamente a integração confiável do Portal, não um status de compra ou pagamento enviado pelo consumidor.

## Evidências automáticas

A suíte F1-389 executa PHP8.3/8.4 com empresas e registros **100% fictícios**. Ela gera um par de chaves Ed25519 temporário, monta o documento de consulta com os mesmos campos de F1-388, verifica a cadeia de origem e valida a resposta usando o **código real do validador Sites F1-386** pinado em `4a2b7e4503cfb0b8d7bfb9d0d4bb7ea43c4198d5`.

Os testes precisam bloquear: identidade S2S ausente, contrato ausente, liquidação não confirmada, revogação, consulta por outra empresa, troca de produto/revisão/fingerprint, campos extras como `paid=true`, ordem de campos diferente, JSON inválido, corpo grande e chave privada indisponível. Nenhum teste ou executor pode registrar um endpoint público no Laravel.

## O que falta

A F1-389 é uma **camada de domínio**, ainda não uma API operacional. Para publicar o endpoint mTLS haverá necessidade de uma configuração privada de proxy validando certificados, um provedor real de identidade do Sites, bancos oficiais de contratos/pagamentos/revogação, resposta com controle de acesso e auditoria, rate limit, KMS/vault e testes de segurança do ambiente. A liberação de compra, renderização real, aprovação do cliente e publicação dependem dos gates separados no Sites.

Nenhuma credencial real, pagamento, webhook PSP, cliente, endpoint HTTP ou VPS foi alterado. **DRAFT/NO-GO — sem merge ou deploy.**
