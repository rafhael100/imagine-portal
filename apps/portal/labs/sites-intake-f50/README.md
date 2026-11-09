# PORTAL-F50 — Compatibilidade de nomes e direções com contratos candidatos do Imagine Sites

**09/11/2026 · PHP puro · nenhuma mensagem, HTTP, autorização ou publicação.**

## Divergência descoberta

O Portal F48/F49 criou um envelope `imagine.portal.sites-handoff-preview.v1`
para **análise de metadados sintéticos**. Ao consultar o trabalho
já existente no repositório independente `rafhael100/imagine-sites`,
confirmou-se que o motor Sites possui **outros protocolos
candidatos**, com responsabilidades distintas.

É essencial **não promover o envelope F48 como contrato de API**.
Ele é somente um artefato local de comparação, sem criptografia,
assinatura, direito de publicar ou identidade confiável.

## Matriz de responsabilidades e schemas

| Artefato | Direção | Finalidade |
|---|---|---|
| Portal F48/F49 `imagine.portal.sites-handoff-preview.v1` | **Interno / não transmitido** | Conferir metadados do briefing e revisão fictícios |
| Sites `contracts/portal-sites/v1/create-project.schema.json` (`schemaVersion: "1"`) | Portal → Sites | Comando candidato de criação de projeto, **não autorização** |
| Sites `contracts/portal-sites/v1/review-handoff-epoch-envelope.schema.json` (`sites.synthetic.review-handoff.v2`) | Sites → Portal | Proposta de revisão com epoch, nonce e HMAC **sintéticos** |
| Sites `SITES-CT-01-PORTAL-SITES-v0.1.md` | Dois sentidos conforme comando | Futuro briefing, aceite expresso, pedido de publicação, observação e entrega |

O motor Sites já exercitou paridade Portal↔Sites no F1-23,
proposta de revisão no F1-31/F1-45, wire v2 no F1-46/F1-50
e barreira transacional de nonce/revogação fictícia no F1-60.
Estas provas não habilitam autenticação ou publicação real.

**Fontes observadas em 09/11/2026:**
- https://github.com/rafhael100/imagine-sites/blob/test/sites-f1-66-readiness-cli-contract-2026-11-09/contracts/portal-sites/v1/create-project.schema.json
- https://github.com/rafhael100/imagine-sites/blob/test/sites-f1-66-readiness-cli-contract-2026-11-09/contracts/portal-sites/v1/review-handoff-epoch-envelope.schema.json
- https://github.com/rafhael100/imagine-sites/blob/test/sites-f1-66-readiness-cli-contract-2026-11-09/docs/SITES-CT-01-PORTAL-SITES-v0.1.md

## Um detalhe de integração que precisa de mapeamento

O catálogo de apresentação do Portal utiliza:

- `PortfolioProduct::Onepage->value = sites_onepage`
- `PortfolioProduct::LandingPage->value = sites_landing_page`

O `CreateProject` candidato do Sites exige, respectivamente,
`productCode: onepage` ou `landing_page`. O F50 cria
`SiteEngineContractVocabulary::createProjectProductCode()`
para traduzir **somente as duas ofertas elegíveis**, rejeitando
CRM, Performance AI, Universidade e variantes arbitrárias.
Esse método não valida contrato, pagamentos ou tenant.

## O que foi programado

- `SiteEngineContractVocabulary`: inventário literal das
  direções e versões já documentadas, nomes exatos das oito
  chaves do comando CreateProject e nove do review v2;
  mapeamento explícito do produto entre domínios.
- `SiteEngineContractVocabularyTest.php`: sete testes
  unitários: mapeamento bidirecional de códigos; exclusão
  de produtos não elegíveis; distinção de direção; chaves
  exatas; confirmação de que o envelope F48 não contém
  schemaVersion/mac/idempotencyKey/authorizationRevision;
  rejeição dos protocolos CreateProject e review v2 pelo
  validador estritamente interno F49; e negação constante
  de publicação, aceite e execução no motor.

## Próximo passo correto para integração

Não criar mais um `SitesEngine` remoto em paralelo.
Antes de integrar APIs reais:
1. Conciliar com o contrato candidato atual do Sites
   `CreateProject`/proposta de revisão assinada e revisar
   seu SHA de referência em ambos os repositórios.
2. Definir a autoridade confiável Portal-only para
   contrato ativo, membership/owner/MFA, idempotência,
   versão/epoch e aceite do cliente, com AppSec/DBA.
3. Só então implementar adaptadores com rotas privadas,
   autenticação de serviço e observabilidade; nenhum
   material privado ou PII nos envelopes de evento.

**F48/F49 são somente ferramentas diagnósticas e NÃO
substituem CreateProject, RegisterClientAcceptance,
RequestPublication, nem o review v2 do Sites.**

PR DRAFT, sem merge/deploy, sem alterações no repositório
imagine-sites, VPS, Cloudflare, cliente ou pagamento.
P0/D02/D03, SEC-R03/Z4, T3/OPEN, IAM/MFA,
LGPD e AppSec/DBA continuam abertos.
