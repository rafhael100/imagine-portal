# Portal F117 — Contrato de metadados Comercial/Portal/CRM

**Escopo exclusivamente experimental:** PHP 8.3/8.4 puro em `apps/portal/labs/commercial-intake-f117`. Nenhuma rota Laravel, controlador, provedor, autenticação real, migração, servidor HTTP, dado pessoal, chamada ao CRM, gasto ou deploy.

## Por que existe

A F96 do `rafhael100/imagine-comercial`, PR #124, demonstrou HTTP em `127.0.0.1` e reserva + outbox em PostgreSQL descartável usando metadados sintéticos de oito campos. O Portal Laravel ainda não possui receptor de orçamento publicado ou homologado. A F117 dá ao **próprio repositório do Portal** a primeira verificação de compatibilidade estrutural que pode ser testada independentemente do Comercial, sem abrir um endpoint.

## Contrato de entrada
Envelopes contêm exatamente as oito chaves do CRM F3-11 / Comercial F78:

- `protocol='imagine.lead-intake'`, `schema_version=1` (inteiro literal);
- `organization_reference`, `binding_reference`, `submission_reference`, `site_reference`, `form_reference`, `destination_reference` — UUIDs não nulos (normalizados para comparação, não tratados como autorização).

O código compara organização, vínculo, site, formulário e destino com **uma fixture de binding separada**. Identificadores fornecidos pelo navegador não podem conceder pertença nem direito de roteamento. A submissão representa o envio lógico e pode mudar entre novos pedidos, mas a F117 não valida idempotência persistente.

Entradas com campos extras (inclusive nome, e-mail, telefone, mensagem, consentimento, cargo e celular), chaves ausentes, versão errada ou IDs adulterados são rejeitadas. Esses dados pessoais precisariam de um canal operacional seguro e de aprovação LGPD específica, **não** deste envelope apenas técnico.

Os resultados são apenas códigos diagnósticos. Até em `STRUCTURE_MATCH_UNTRUSTED`, todas as permissões `canAcceptVisitorData`, `tenantAuthorized`, `canCreateLead`, `canQueueOutbox`, `canDeliverToCrm`, `crmReceiptProven`, `canAcknowledgeVisitor` e `productionAuthorized` são **sempre falsas**.

## Verificação
`test_commercial_metadata.php` roda independentemente do Laravel, em PHP 8.3/8.4, com cenário positivo apenas estrutural e negativos para troca de tenant, site, destino, campos extras, dados pessoais, envelope incompleto, tipos inválidos, versões erradas e IDs nulos. O workflow dedicado `portal-f117-commercial-intake-ci.yml` executa teste de sintaxe e matriz em CI, sem dependências externas nem configuração de banco.

## Pendências operacionais
Portal e CRM possuem logins separados. Para efetuar a integração real será necessário criar receptor autorizado, autenticação e revogação de serviço, vinculação de tenant através de fonte confiável, consentimento/aviso legal, mitigação de bots, TLS, retenção, outbox persistente, recibo de gravação real CRM, reconciliação em caso de timeout e QA independente no staging autorizado. Não confiar apenas em UUIDs, MAC de chave fornecida pelo remetente ou testes CI.

**A F70 do Comercial continua bloqueada.** Nenhuma `main`, VPS, DNS, endpoint público ou produção é alterada nesta entrega.
