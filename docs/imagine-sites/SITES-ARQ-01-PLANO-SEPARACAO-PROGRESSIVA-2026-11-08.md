# SITES-ARQ-01 — Plano técnico de separação progressiva do Imagine Sites Engine

**Versão:** v1.0 — plano executivo de arquitetura (documentação, sem homologação operacional)  
**Data de referência:** 08/11/2026 (America/Sao_Paulo)  
**Decisão de produto do responsável:** Imagine Sites será **motor independente**, com produção/gestão pela Imagine e experiência do cliente pelo Imagine Portal. Não haverá editor livre de sites para clientes neste escopo.  
**Repositório de referência:** `rafhael100/imagine-portal`; repositório futuro candidato `rafhael100/imagine-sites` (ainda não criado por este plano).  
**Natureza:** direção arquitetural aprovada pelo responsável; os mecanismos, contratos, custos, implementação e homologações abaixo dependem dos gates já existentes. **Nenhuma disposição deste plano encerra P0, RF-PEND-01/RF-PEND-04 ou libera produção.**

## 0. Autorizações, limites e precedência

- Autorizado neste recorte: planejar, reconciliar fontes, registrar plano em **branch documental isolada** e submetê-lo a **PR draft sem merge**.
- **NÃO autorizado neste recorte:** criar serviços operacionais, alterar `main`, fundir PRs, publicar sites, configurar DNS/Cloudflare, criar APIs reais, adicionar segredos, usar dados pessoais, contratar fornecedores, gerar despesas, implantar na VPS ou habilitar rollback automático.
- As aprovações AUT-P01–P10 (todas alternativa B) são **normativas**, não contratos homologados. RV-CT01/RV-CT02 B também são diretrizes normativas, com contratos técnicos ainda abertos; RV-CT03–08 requerem detalhamento.
- Permanecem ABERTOS P0-DOC01–14 nos estados documentados em `P0-FECHAMENTO-DOCUMENTAL-01.md`, **P0-DOC03**, RF-PEND-01; RF-PEND-04 não homologado. **AGI-11-RB01 DESABILITADO**.
- Precedência: ratificações posteriores e expressas prevalecem **apenas** nas matérias que modificaram; documentos históricos preservados; divergências não podem ser resolvidas inventando equivalência.
- Este arquivo é o contrato de *organização de trabalho* e não deve ser usado como especificação executável sem revisão de segurança, dados, financeiro e homologação pertinente.

## 1. Objetivo e fora de escopo

**Objetivo:** extrair as responsabilidades técnicas de geração, revisão técnica, versionamento, publicação, manutenção e monitoramento de sites do Portal para o `Imagine Sites Engine` (serviço e banco independentes), preservando todas as jornadas, contratos e decisões do cliente **dentro do Portal**.

**Produtos da fase 1:** Onepage e Landing Page. Produção feita pela Imagine com apoio de IA. Cliente envia briefing, visualiza prévias, solicita alterações e aprova expressamente no Portal; não edita diretamente a estrutura ou a publicação final.

**Fora do escopo:** editor visual self-service; WordPress/CMS como requisito; contas compartilhadas Portal/CRM; SSO presumido; refazer preços/contratos; replicar módulo financeiro; novo motor de agentes; publicar anúncios via Sites; e criar um painel externo obrigatório ao cliente.

## 2. Inventário do que será preservado

| Origem | Conteúdo existente | Destino/ação | Critério |
| --- | --- | --- | --- |
| Portal legado `server/briefing.mjs` e `briefing-ui.js` | Materiais, validação, jornada | Reaproveitar regras/testes como referência; Portal Laravel conserva UI e autoridade do aceite do briefing | Sem migração cega de Node/SQLite para Laravel/PostgreSQL |
| Portal legado `server/production.mjs` | Estados, revisão, aceite, publicação registrada | Repartir estados por autoridade e adaptar contratos; não copiar tabela/SQL diretamente | Distinguir aprovação, publicação, saúde e entrega |
| Portal legado `production-ui.js` | UX e histórico do projeto | Reutilizar comportamento como especificação de componentes Blade | Testes de UX sem dados reais |
| Portal Laravel em branches `dev/fast-track-portal-2026-11-05` e filhas | Fundação Laravel, UI mockada, objetos de identidade/autorização | Preservar integralmente; integração via adaptador novo | Nenhuma alteração em `main` por este plano |
| Portal `docs/imagine-sites/` | AUT, RV-CT, índice e pendências | Manter no Portal como histórico; preparar referências cruzadas para o futuro repo Sites | Não atribuir status "homologado" a proposta |
| CRM `docs/planejamento/IN-122..IN-149` | Elegibilidade, vínculo, captação e retenção | Contrato entre sistemas, sem duplicar cadastro CRM | Retenção T0+432h e duas APIs independentes |
| Agents `AIA-12` | Contratos API-first e ferramentas autorizadas | Consumir núcleo de agentes via interface tipada | IA não é autoridade de publicação ou pagamento |
| Performance AI `BL-03` | Fronteiras com Sites/landing/criativos | Requests de landing sob contrato próprio | Performance não acessa Cloudflare ou banco Sites diretamente |

**Constatação:** não foi localizado motor independente do Imagine Sites operacional no GitHub. `rafhael100/imagine-sites` ainda não foi encontrado na consulta; a futura criação é tarefa separada.

## 3. Topologia alvo e propriedade de dados

```text
CLIENTE / EQUIPE IMAGINE
       |
     PORTAL (Laravel + PostgreSQL próprio)
       | contratos, briefing, aprovação, suporte e entrega oficial
       | API autenticada + outbox / eventos
       v
SITES ENGINE (Laravel + PostgreSQL próprio; worker/filas segregados)
       | project, releases, manifest, preview, publish jobs, monitors
       +--------------------> AI AGENTS (serviço central; tarefas tipadas)
       +--------------------> GITHUB (código/artefatos permitidos, versionamento)
       +--------------------> CLOUDFLARE (assets, domínio, publicação)
       |
FORMULÁRIO PÚBLICO --> API CAPTAÇÃO PRINCIPAL ----+
                   \-> API CAPTAÇÃO CONTINGÊNCIA --+--> CRM (persistência final)
                        (armazenamento independente)
```

- **Portal é autoridade:** identidade/autorização de usuários do Portal, contratação de site, termos comerciais, estado financeiro confirmado, aprovação expressa da revisão, relacionamento e evento oficial `ENTREGUE`.
- **Sites é autoridade:** projeto técnico, estrutura de páginas, revisões, manifesto, artefatos de publicação, fila técnica, versão observada em produção, health-checks e incidentes técnicos de Sites.
- **CRM é autoridade:** contas e permissões próprias, leads/contatos persistidos, eventual assinatura paga e validação da conclusão de entrega de leads.
- **Captação é autoridade:** `T0` durável para formulários nas APIs principal/contingência, com autorização específica da conta de destino; não confundir `202 Accepted` com registro no CRM.
- **Agents/n8n coordenam** com ferramentas de escopo reduzido; não são fonte de verdade de contratação, aprovação, deploy confirmado ou lead gravado.
- **Performance AI** pode solicitar Landing Page vinculada a contrato autorizado, mas não publica via credenciais alheias nem concede CRM cortesia.

Bancos separados logicamente e credenciais exclusivas. É aceitável usar **a VPS central em desenvolvimento** por contêineres isolados, mas isso não satisfaz os requisitos de independência física da API de contingência na produção. Sites públicos estáticos devem continuar disponíveis quando Portal estiver indisponível, desde que a infraestrutura de entrega esteja saudável.

## 4. Linguagem, serviços, armazenamento e deploy — proposta técnica

- **Backend:** Laravel compatível com a versão do novo Portal, porém com release/dependências independentes. A versão exata fica fixada no lockfile e validada em CI.
- **DB:** PostgreSQL independente para Sites, com isolamento de tenants, políticas de autorização e testes negativos. Sem SQL cross-database.
- **Jobs:** filas próprias do Sites, workers separados do processo HTTP, limites de concorrência e retries idempotentes. Backend de fila a homologar (não presumir Redis ou serviço pago).
- **Orquestração:** n8n e Imagine AI Agents compartilhados por adaptadores tipados, nunca com usuário SQL de privilégio total.
- **Frontend:** interface do cliente continua Blade/Laravel no Portal. Sites API retorna estados normalizados; não abrir segundo login de cliente.
- **Entrega:** Cloudflare Workers Static Assets (diretriz IN-096); GitHub para versionamento; DNS e SSL aprovados conforme titularidade/consentimento do cliente. O modelo de muitos hostnames/sites é gate específico de escala e custos, sem compra agora.
- **Artefatos e materiais:** segregar fontes privadas e segredos de repositórios públicos. GitHub público **não** é repositório de dados de cliente, tokens, briefings privados ou leads.
- **Backups:** backups separados por domínio, restauração ensaiada, controles de retenção e revogação; sem rollback automático sensível.

## 5. Vocabulário e identidades (candidatos, NÃO schemas aprovados)

| Entidade | Autoridade | Campos mínimos conceituais |
| --- | --- | --- |
| `portal_contract` | Portal | `contract_ref`, `tenant_ref`, `product_code`, `commercial_revision`, `eligibility` |
| `sites_project` | Sites | `site_id`, `portal_project_ref`, `tenant_ref`, `product`, `technical_state` |
| `site_release` | Sites | `release_id`, `site_id`, `manifest_digest`, `artifact_ref`, `parent_release` |
| `approval` | Portal | `acceptance_ref`, `release_id`, `manifest_digest`, `actor`, `authorization_revision`, `accepted_at` |
| `publication_operation` | Sites | `operation_id`, `release_id`, `environment`, `requested_at`, `observed_release`, `proof_ref` |
| `delivery_event` | Portal | `delivery_event_id`, `site_id`, `release_id`, `checklist_ref`, `delivered_at` |
| `site_crm_binding` | Autoridade de vínculo versionado | `site_id`, `crm_account_ref`, `binding_revision`, `eligibility`, `revocation_marker` |
| `lead_ingress` | API captação | `submission_id`, `T0`, `original_destination`, `delivery_deadline`, `delivery_state` |

**IDs opacos e estáveis:** não derivar empresa/conta de e-mail, hostname ou parâmetros fornecidos pelo visitante. `tenant_ref` nunca é sozinho autorização. Autoridades devem fornecer recibos assinados ou verificáveis, com versão/revogação, usando protocolo a homologar.

## 6. Contratos entre serviços — API-first (PROPOSTA, não endpoints ativos)

A API deve ser versionada, com autenticação forte serviço-serviço e escopo mínimo. Exemplos apenas de nomenclatura:

| Operação lógica candidata | Sentido | Resposta/garantia exigida |
| --- | --- | --- |
| `CreateSiteProject(v1)` | Portal → Sites | idempotency key, vínculo contrato/tenant, `site_id`; sem criar duplicatas |
| `SubmitApprovedBrief(v1)` | Portal → Sites | versão imutável do briefing, material autorizado e recibo |
| `RequestGenerateRelease(v1)` | Portal/equipe → Sites | job assíncrono, orçamento/limites IA, estado consultável |
| `GetProjectSnapshot(v1)` | Sites → Portal por consulta autorizada | estado/versionamento e permissões filtradas; sem dados de outras empresas |
| `RegisterReleaseAcceptance(v1)` | Portal → Sites | somente com evidência validada do aceite oficial do Portal, digest exato, frescor/revogação |
| `RequestPublication(v1)` | Portal/serviço autorizado → Sites | bloquear sem aceite, contrato, manifesto e gates válidos |
| `PublicationVerified(v1)` | Sites → Portal por evento/consulta | release observada, evidência, health, integridade |
| `DeliveryEligible(v1)` | verificador → Portal | checklist, ausência de bloqueador, assinatura/proveniência |
| `LeadStatus(v1)` | captação/CRM → Portal/Sites autorizado | consulta mínima, confirmação final só do CRM |
| `RevokeService(v1)` | Portal → Sites/captação | revogação versionada, falha fechada, impedimento de operações indevidas |

### 6.1. Envelope de evento candidato

```json
{
  "schema_version": "1",
  "event_id": "opaque-uuid",
  "event_type": "site.release.ready",
  "occurred_at": "ISO8601-UTC",
  "correlation_id": "opaque-id",
  "tenant_ref": "opaque-tenant",
  "site_ref": "opaque-site",
  "resource_revision": 5,
  "authority": "sites",
  "payload_ref": "private-reference-only"
}
```

Não embutir briefing confidencial/lead em evento ou log. Especificar autenticação, assinatura ou prova de origem, TTL, audience, idempotência, ordering por recurso, replay protection, retries com backoff e DLQ/estado inconclusivo. Envelope é **candidato**; contrato OpenAPI/AsyncAPI só poderá ser declarado válido após revisão.

### 6.2. Consistência sem transação distribuída

- Aplicar **transactional outbox/inbox**, recibo e idempotência por operação/autorização. Não usar transação SQL entre serviços nem assumir ordenação global.
- Estados de comando `requested/accepted/running/confirmed/failed/inconclusive` devem ser separados dos fatos oficiais `approved/published_observed/delivered`.
- Falha de resposta: consultar resultado pela mesma chave antes de reenviar, sem dupla publicação/cobrança/lead.
- Revogação: checar novamente autorizações perto de cada efeito sensível, com barreira durável; cache antigo não autoriza alteração.
- Portal indisponível não despublica automaticamente site estático; alterações comerciais pendentes precisam de procedimento reconciliador autorizado.

## 7. Máquina de estados e gates de publicação

**Produção:** `briefing_pending → briefing_validated → generating → release_ready → awaiting_client → revision_requested/accepted`.  
**Publicação:** `not_requested → requested → running → verified/failed/inconclusive`.  
**Operação:** `healthy/degraded/down/recovering/verified_recovered` por verificação independente.  
**Entrega oficial:** `not_eligible/blocked/eligible/ENTREGUE`; `ENTREGUE` é evento histórico único no Portal, sem duplicar Dia 0 da jornada REL-01.  
**Captação:** `disabled/pending_authorization/authorized/suspended` independente do site estar no ar.

Precondições de publicar: contrato/tenant correto e elegível, revisão com manifesto imutável, aceite do cliente devidamente autorizado para **aquele digest**, política LGPD aplicável, domínio e recursos autorizados, credencial de publicação restrita e checklist de bloqueadores. A publicação deve ter prova do artefato/release **observado** após deploy, não apenas um sucesso da API do fornecedor.

Uma falha na tentativa de publicar B não elimina a constatação anterior de A ainda no ar. Nenhum rollback automático AGI-11-RB01 é habilitado por esse plano. Recuperações sensíveis exigem procedimento e alçada específicos.

## 8. Construção por IA com execução supervisionada

- Sites Engine define tarefa, `site_id`, `release_id`, permissões, orçamento e entradas; Imagine AI Agents executam tarefas limitadas e retornam artefatos/diagnósticos.
- IA não lê banco geral, não recebe credenciais Cloudflare/GitHub de administração, não aprova trabalho próprio e não inventa decisão do cliente.
- n8n só orquestra; ferramentas de publicar/alterar domínio têm *policy enforcement* no backend e verificação de risco/alçada.
- AUT-P09 B: operações seguras de baixo/moderado risco só em playbook testado/homologado e ativo; alto/crítico exige **uma aprovação humana específica** quando aplicável; não criar aprovações duplicadas nem bypass.
- Saídas de IA são não confiáveis até validação: sanitização de conteúdo, CSP, dependências, links externos, privacidade, acessibilidade básica, responsividade, imagens/licenças e ausência de segredos.

## 9. Integração CRM e formulários

- Sites produz página/formulário e configuração autorizada; publicação do site **não autoriza** ativação de captação automaticamente.
- Formulário público chama apenas API de captação oficial (principal ou contingência), com defesa antispam/abuso, CORS, limites, validação do vínculo versionado e revogação na origem.
- A API persiste duravelmente antes de registrar **T0**; persistências independentes nas duas rotas. Confirmar efetivação no CRM pela autoridade CRM, não por HTTP 200/202 de fila/n8n.
- Fila: `T0+72h` inicial, até `T0+432h` total; não reiniciar relógio em reprocessamento ou troca de API. Após 432h, nenhuma nova tentativa; descarte e auditoria conforme IN127-R01–R06, IN-128, IN-147 e AUT-P07, sem quarentena artificial.
- Troca posterior da conta CRM por chamado da Imagine, com bloqueio seguro e novo vínculo revisionado; backlog antigo só para destino original se houver autorização específica atual. Nunca redirecionar silenciosamente ao novo CRM.
- Cortesia Básico e assinatura paga são autoridades comerciais próprias. Vários sites na mesma conta não multiplicam cortesia; perda de um site não suspende indevidamente uma conta que ainda tem outro vínculo elegível.

## 10. Segurança e privacidade — gates eliminatórios

1. Isolamento de tenant em Portal, Sites, CRM e captação; RBAC/MFA nos contextos aplicáveis, autorização por recurso e negação por padrão.
2. Credenciais de serviços próprias, escopos mínimos, rotação, revogação, proteção contra impersonação/SSRF, webhooks falsificados e replay.
3. Nenhum SQL direto de um serviço no banco de outro; nenhum papel de operador com bypass de tenant para fluxos normais.
4. Artefatos de cliente, briefs, leads e secrets segregados dos repositórios públicos. Validar licença/consentimento de mídias e código de terceiros.
5. Publicação exige controle de integridade, verificação pós-deploy, inventário de domínio/DNS/SSL e histórico de ações.
6. Logs sem payload pessoal, segredo ou tokens; referência auditável a decisões, usuário/serviço, tenant e evidência.
7. Backup/restore testado por ambiente: recuperação não pode reativar autorização revogada nem restaurar payload expirado em fila ativa.
8. Descartes legais por classe de dado; relação entre site publicado, cópias estáticas, formulários e dados de CRM precisa de matriz de retenção aprovada.
9. Segurança dos agents: baixa/moderada alçada condicionada, alto/crítico com aprovação específica, rollback automático DESABILITADO.
10. Caso falte autorização, verificação de elegibilidade ou prova da versão exata: **falhar fechado** e registrar bloqueador.

## 11. Infraestrutura por ambiente

| Ambiente | Objetivo | Regra |
| --- | --- | --- |
| `docs` | Documentação e contratos | Sem execução, segredos, VPS ou dados reais |
| `lab/mock` | Unit tests, contract tests, UI offline, adversarial synthetic | Sem terceiros reais, cobrança, DNS ou deploy |
| `dev` | Portal e Sites contêineres isolados, PostgreSQLs separados | VPS somente após gate e autorização operacional específica |
| `staging` | Integração end-to-end e Cloudflare de teste, domínio fictício autorizado | Sem dados de clientes nem custos não aprovados |
| `production` | Sites reais e captação independente | Revisão de segurança, LGPD, custo, contrato, disponibilidade e autorização explícita |

- Não comprometer disponibilidade de sites com login/painel Portal.
- APIs de captação principal e contingência **não** podem depender do mesmo banco/VPS em produção. Diferenciar redundância de captação do simples isolamento de containers.
- Não adquirir Cloudflare pago, Workers for Platforms, storage extra ou novo provedor pela simples aprovação deste documento. Dimensionar volumes, hostnames, limites, quotas e custos antes de decisão.
- DNS permanece sob escolha/gestão do cliente; não assumir DNS autoritativo próprio. Evitar alterar registros de e-mail ou transferir domínio sem autorização.

## 12. Sequência de migração progressiva — sem destruição do legado

| Marco | Entrega candidata | Pré-requisitos e critério de saída |
| --- | --- | --- |
| M0 — Inventário | Mapear código legado, PRs, dependências, AGI faltantes e decisões vigentes | Evidências com SHAs; RF-PEND-01 continua até conferência integral |
| M1 — Fronteiras | ADR de sistemas, ownership, APIs/eventos, segurança, billing, leads | Revisão P0-DOC03/RV-CT01–08; sem supor homologação |
| M2 — Repo separado | `imagine-sites`, AGENTS, STATUS, CI documental/lint | Criação em autorização de execução posterior; zero segredos e deploy |
| M3 — Contratos simulados | Mock Portal↔Sites, organizações, projetos, versões e eventos | Nenhum cliente real, nenhuma API externa, testes positivos/negativos |
| M4 — Motor F1 | Models/serviços Sites em Laravel e PostgreSQL isolados, jobs e manifests | Gate de código próprio; unit/integration tests e isolamento |
| M5 — Produção IA F2 | Adaptador Agents/n8n, geração de site estático e validação | Playbooks, budgets, licenças, sandbox e autorização por ferramenta |
| M6 — Publicação teste F3 | Preview aprovado, publish sintético e provas de versão | Cloudflare sandbox somente após permissão específica; sem auto rollback |
| M7 — Integração F4 | Captação principal/contingência, contratos CRM, relatórios de falhas | Independência física efetiva e testes de 432h por relógio sintético |
| M8 — Piloto | Homologação operacional e primeiro site real | P0/RF encerrados com evidências, risco/custo aprovado, autorização explícita |
| M9 — Expansão | Observabilidade, quotas, múltiplos domínios, escala | Novas decisões de orçamento e capacidade; sem publicação presumida |

**Estratégia:** *strangler* por capacidade, não big-bang. O legado Node/SQLite permanece como fonte histórica de comportamento, não como banco compartilhado. Não duplicar a jornada comercial no Sites. Testes sintéticos devem anteceder qualquer operação com clientes reais.

## 13. Plano de testes e critérios mensuráveis

**Contrato/identidade:** cross-tenant deny, vínculo revogado, token expirado, assinatura inválida, autorização de outra conta, replay, sessão e login de outro produto recusados.

**Projeto/versão:** criação duplicada pela mesma idempotency key; requests concorrentes; manifesto alterado após aceite; aprovação da versão N não publica N+1; cancelamento/retentativa preserva histórico.

**Publicação:** publish solicitado sem aceite = bloqueado; provider 202 mas release errada = inconclusivo; falha em B preserva observação válida de A; URL saudável sem checklist = não ENTREGUE; evento ENTREGUE duplicado não reinicia Dia 0.

**Captação:** duas rotas e bancos independentes; envio malicioso com destino adulterado é recusado; troca de vínculo não redireciona backlog; indisponibilidade CRM não perde T0; confirmação só por CRM; desduplicação real e TTL exatamente 432h; descarte sem ressurreição após restore.

**IA/segredos:** prompt injection para publicar é negada; credencial não chega ao agente, logs ou GitHub; orçamento excedido suspende tarefa; ferramenta crítica não executa sem aprovação válida.

**Operação:** backup restaurável, anti-rollback de revogação, health-check, métricas, alertas; documentação de falha e runbook; testes não-prod em runners autorizados, sem afirmar PASS antes dos resultados.

**Critério de conclusão de cada marco:** documentação com commit SHA, contrato versionado, testes executados/evidenciados, revisão adversarial, riscos resolvidos ou bloqueados, e decisão de promoção expressa para próxima classe de ambiente. Não usar percentuais artificiais de progresso como prova de entrega.

## 14. Governança GitHub/PRs

- **Este PR:** `docs-only`, `draft`, sem merge, sem CI que faz deploy, sem alteração de `main`, VPS, Cloudflare ou outros repositórios.
- **Repo Sites futuro:** branch `main` protegida, `dev/*` para código, `docs/*` para contratos; manter `AGENTS.md`, `STATUS-ATUAL.md`, matriz P0, changelog e ownership; PRs pequenos e testáveis.
- **PRs Portal existentes:** não fazer cherry-pick, merge, rebase forçado ou fechamento automático. Preparar adaptadores em PRs filhos, após verificar SHAs e compatibilidade.
- **Proteção operacional:** sem auto-merge e sem auto-deploy; mudanças técnicas críticas somente conforme alçadas; preview automatizada no CI não significa publicação.
- **Matriz de dependências:** alterações em contrato Portal↔Sites implicam revisão de ambos os lados; mudanças na captação exigem revisão CRM/IN127; mudanças em IA exigem AIA-12.

## 15. Riscos, respostas e critérios de pausa

| Risco | Resposta obrigatória | Pausa se... |
| --- | --- | --- |
| Fronteira de autoridade ambígua | ADR + contratos de estado/aceite | Dois serviços puderem afirmar `ENTREGUE` |
| Vazamento entre tenants | Autorização de recurso/DB e testes negativos | Um único caso cross-tenant for aceito |
| Site B publicado sem aceite B | Digest + proof de publicação | Versão divergente for marcada como publicada/entregue |
| Captura sem CRM autorizado | Gate backend nas duas APIs | Formulário receber dados com vínculo revogado |
| Fila sem segurança de expiração | T0 imutável, idempotência, descarte | Retry possível após 432h |
| Dependência de provedor/custo | Prova de conceito e teto aprovado | Contratação ou uso faturável imprevisto |
| Rollback inseguro | Recuperação manual sob alçada | Agente puder reverter produção sozinho |
| Documentação divergente | Rastreabilidade com versões e precedência | Plano assumir contrato ainda aberto como homologado |

## 16. Decisões já tomadas versus pendências reais

**Direção aprovada em 08/11/2026:** Sites independente; execução/gestão pela Imagine; Portal como interface comercial e de acompanhamento; separação progressiva; **sem merges/deploys automáticos**.

**Propostas técnicas neste plano, não homologadas:** stack exata do Sites, esquema SQL, formatos API/eventos, workers, autenticação serviço-serviço, armazenamento privado, estratégia de hostname, políticas definitivas de SLA/limites, custos, ambientes de teste, criar repositório e habilitar programação.

**Não reabrir por rotina:** Onepage/Landing, aceite explícito, CRM cortesia condicional e vínculos, benefícios financeiros ratificados, política IN127, LGPD, Cloudflare como diretriz inicial, AGI-11-RB01 desabilitado.

**Pendências impeditivas:** encerramento documentado dos P0, recuperação do corpus AGI, contratos Sites↔Portal↔CRM, teste de isolamento/revogação, segurança Cloudflare/domínios, contingência de captação, custos aprovados e promoção de ambiente. A aprovação da arquitetura **não é aprovação de go-live**.

## 17. Próxima ordem de trabalho documental (sem perguntar por decisões rotineiras)

1. Produzir `SITES-ARQ-02` — ADR definitivo de ownership + esquema de integrações e contratos candidatos, com histórico de precedências.
2. Produzir `SITES-CT-01..05` — Portal↔Sites (projeto/brief/aceite), Sites↔Publisher (manifesto/prova), Sites↔Agents (tool scopes), Sites↔CRM/Captação (autoridades), Incidentes/Recovery (risco).
3. Produzir `SITES-SEC-01` — matriz tenant/identidade/revogação, testes hostis e critérios de retenção LGPD.
4. Produzir `SITES-DEV-01` — ordem de bootstrap isolado e CI sintético, com bloqueios claros e pré-requisitos.
5. Produzir `SITES-OPS-01` — estudo de Cloudflare/hostnames/custo, domínio e captação realmente redundante.
6. Submeter ao responsável **somente** decisão que altera custos, riscos, publicidade/segredos, fornecedor, dados reais ou operação crítica; demais detalhamentos podem seguir em PRs documentais isolados.

## 18. Referências de rastreabilidade

- [Índice mestre Imagine Sites](INDEX-MESTRE-PLANEJAMENTO-2026-11-03.md)
- [P0-FECHAMENTO-DOCUMENTAL-01](P0-FECHAMENTO-DOCUMENTAL-01.md)
- [P0-DOC03/RV01](P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md)
- [AUT-P04 B](AUT-P04-aprovacao-alternativa-B-2026-11-04.md)
- [AUT-P08 B](AUT-P08-B-aprovada-2026-11-04.md)
- [AUT-P09 B](AUT-P09-B-aprovada-2026-11-04.md)
- [RV-CT01 B](P0-DOC03-RV-CT01-B-APROVADA-2026-11-04.md)
- [RV-CT02 B](P0-DOC03-RV-CT02-B-APROVADA-2026-11-04.md)
- CRM: `docs/planejamento/IN-122`, `IN-124`, `IN-127-R01-R06-ratificacoes-2026-11-03.md`
- AI Agents: `docs/AIA-12-SDK-APIS-CONTRATOS-PRODUTOS.md`
- Performance: `docs/performance-ai/PERF-P0-BL03-ECOSSISTEMA-CRM-SITES-CRIATIVOS-DIREITOS.md`

**Situação do marco atual:** plano técnico estruturado **somente para revisão documental**, sem demonstração de implementação, testes, homologação, merge, deploy ou contratação.
