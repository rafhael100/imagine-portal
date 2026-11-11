# Portal F133 — Contrato CreateProject real do Imagine Sites

A validação local F55 comparava o Portal a um arquivo de esquema copiado do Sites. A F133 introduz uma **verificação bilateral em CI** que obtém, em checkout GitHub separado, o código real do Imagine Sites fixado no commit `ed6cf956fb62c3b9496fcd026afd0c1fa66adf61` (F1-145). Compara os bytes de `contracts/portal-sites/v1/create-project.schema.json` à fotografia já existente em `apps/portal/labs/sites-intake-f55/create-project.schema.snapshot.json`, exigindo o Git blob `042f4ba31776b486ced757e9624bc42757c46e74`.

O teste executa a classe `SiteEngineSchemaDriftGate` do **Portal atual**, e confirma que a estrutura continua fechada (`additionalProperties=false`), com oito campos obrigatórios, produtos `onepage` e `landing_page`. Alterações legítimas mas incompatíveis do esquema, adulteração da cópia do Portal e arquivos ausentes produzem recusa explícita.

A CI executa com PHP 8.3/8.4 e ambos os checkouts de revisão em SHA; não cria API, sessão, credenciais, servidor, compra ou acesso de cliente.

**Limite:** bytes e versões iguais não comprovam autenticação, consentimento, propriedade de tenant, assinatura de mensagens ou emissão autorizada. A migração para uma integração operável depende de fontes confiáveis, revisão independente AppSec/DBA, homologação da VPS e gate operacional de ambos os projetos. Não altera `main`, deploy ou DNS.

## Compatibilidade do handoff Sites → Portal

A mesma rodada verifica os contratos reais `review-handoff-epoch-envelope.schema.json`, `review-handoff-envelope.schema.json` e `project-authorization-snapshot.candidate.schema.json`, todos por SHA Git blob fixo. O envelope v2 precisa manter exatamente os nove campos `SiteEngineContractVocabulary::reviewProposalV2Keys()`, emissor `imagine-sites`, destinatário `imagine-portal` e versão tipada. O conteúdo do review v1 ainda exige `portalAuthorityRequired=true`, `officialClientAcceptance=false` e `publishable=false`, enquanto o snapshot de autorização só admite `eligible`, `suspended`, `revoked` e deve ficar vinculado ao comando CreateProject. Nenhum desses valores é um atestado operacional.
