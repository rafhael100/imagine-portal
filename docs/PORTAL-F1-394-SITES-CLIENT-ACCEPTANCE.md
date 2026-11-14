# Portal F1-394 — Emissor candidato de aceite expresso de release Sites

Esta entrega acrescenta uma biblioteca PHP de emissão canônica **Ed25519** para o verificador já criado no Imagine Sites [F1-391](https://github.com/rafhael100/imagine-sites/pull/473). O CI usa o verificador **original do Sites** em SHA fixa `1bb80823a112cd935f4498e36ad9e0da853f851f` para validar compatibilidade. Nenhuma credencial real, conta real ou site de cliente foi utilizado.

## Garantias do emissor

`PortalEd25519ClientAcceptanceIssuer::issue(siteRef, releaseRef, editorialRevision, now, privateKey)` não recebe tenant, SHA-256, decisão do cliente nem sua identidade do navegador. Exige uma fonte privilegiada `PortalCurrentClientAcceptanceSource` que leia da futura trilha de consentimento e do IAM. A fonte padrão `DenyingPortalCurrentClientAcceptanceSource` sempre retorna `null`.

O snapshot confiável vincula empresa, projeto, release, SHA-256 e revisão exata, número de época e referência de aceite, ação expressa registrada e identidade do cliente verificada com MFA. Todos precisam ser atuais; qualquer ausência, divergência, fonte indisponível ou chave inválida resulta em recusa. A chave privada binária é passada somente pelo serviço confiável que a recuperará de um vault, nunca do HTTP. A emissão tem TTL máximo de 60 segundos.

O recibo resultante segue os 16 campos canônicos exatos de F1-391. O Sites exige a assinatura e uma **testemunha independente do estado atual** (incluindo revogação), consultada a cada verificação. Um aceite de versão anterior ou invalidado pelo cliente não autoriza a nova publicação. A assinatura isolada não substitui a verificação de condições comerciais, domínio, LGPD e segurança.

## Ainda necessário antes de produção

Implementar autenticação e titularidade reais no Portal, registrar e auditar a ação explícita sobre a versão entregue (rejeição/retrabalho/revogação incluídos), persistir e consultar época monotônica independente de restore, integrar política de retenção/LGPD, habilitar serviço S2S mTLS com chaves protegidas, contratar e homologar o Asaas conforme o fluxo comercial aprovado, conectar contrato de domínio e publisher seguro com rollback, além de backups/restauração observados. A biblioteca ainda não está registrada em Laravel nem possui rota pública.

**DRAFT / NO-GO / NO MERGE / NO DEPLOY**. O CI é totalmente fictício e não implica cliente aprovado ou publicação liberada.
