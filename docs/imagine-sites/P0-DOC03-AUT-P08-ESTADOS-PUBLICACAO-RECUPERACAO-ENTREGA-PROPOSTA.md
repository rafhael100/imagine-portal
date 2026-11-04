# P0-DOC03 / AUT-P08 — Estados oficiais de publicação, falha, recuperação e entrega

> **DECISÃO POSTERIOR (04/11/2026):** a alternativa B foi aprovada. Ver [registro vigente AUT-P08 B](AUT-P08-B-aprovada-2026-11-04.md). Esta minuta permanece como histórico de pré-aprovação; trechos que a classificam como pendente eram válidos antes da decisão. Contratos técnicos, AUT-P09/10, P0-DOC03 e homologação permanecem pendentes.


**STATUS: PROPOSTA PARA DELIBERAÇÃO — NÃO RATIFICADA.**  
**Preparação:** 04/11/2026. **Frente:** Imagine Sites / Portal Imagine / agentes / publicação e observabilidade.  
**Precedências:** AUT-P01 a AUT-P07 alternativas B aprovadas; PORTAL-D01, PORTAL-COM-13/14/15 (especialmente COM-15 seção 14 vigente), PORTAL-REL-01; IN-101/124/143/156 e IN127-R01 a R06 nos pontos aplicáveis.  
**Limites:** AUT-P09 e AUT-P10 **PENDENTES**; P0-DOC03 e RF-PEND-01 **ABERTOS**; RF-PEND-04 **NÃO HOMOLOGADA**. Somente documentação: **não programar, testar, implantar, publicar sites, homologar nem modificar infraestrutura/workflows**. **AGI-11-RB01 permanece DESABILITADO.**

## 1. Problema de autoridade

Não é correto chamar todo projeto de "entregue" apenas porque o deploy terminou, a URL responde, um agente concluiu sua execução, o cliente aprovou uma prévia ou uma página está no ar. Também não é correto apagar o evento histórico de entrega porque houve incidente posterior.

AUT-P08 deve separar: **(a)** produção e aceite de versão; **(b)** comando de publicação e resultado técnico comprovado; **(c)** operação acessível, incidentes e recuperação; **(d)** checklist de entrega e evento oficial ENTREGUE; **(e)** elegibilidade específica de formulários/CRM, contratos e privacidade. Cada dimensão possui autoridade e prova próprias.

## 2. Regras aprovadas que não podem ser alteradas

1. **Aceite expresso:** PORTAL-D01 e AUT-P04 B exigem cliente autenticado/autorizado aprovando a versão exata vinculada ao manifesto. Silêncio, leitura de URL e IA não constituem aceite. Alterações materiais exigem nova avaliação e aceite quando aplicável.
2. **Publicação/entrega distintas:** PORTAL-COM-13 exige versão aprovada efetivamente implantada e comprovada, checklist íntegro por produto, evidências válidas, requisitos LGPD e ausência de bloqueadores. Publicação, aceite, testes e **ENTREGA OFICIAL** são fatos diferentes.
3. **Entrega automática, não presumida:** agente IA autorizado pode conduzir verificação e plataforma registrar automaticamente **ENTREGUE** quando todos os critérios aplicáveis estiverem confirmados, sem clique manual rotineiro da Imagine; ações críticas ainda exigem autorização humana pertinente. Não presumir que esse agente pode autoaprovar um aceite ou excepcionalidade.
4. **REL-01:** Dia 0 somente pelo **primeiro evento oficial de entrega válida** registrado no Portal para a jornada/projeto correspondente, com idempotência; nunca pela simples publicação nem por execução de workflow. Republicação e retentativas não disparam Dia 0 duplicado.
5. **Incidente posterior:** falha após entrega cria incidente e tratamento de suporte/recuperação; **não apaga nem altera retroativamente** a evidência de entrega. Regras de correção, reversão administrativa de estado e eventual reabertura/reinício da jornada carecem de decisão própria.
6. **Política de privacidade padrão vigente:** para Onepages/Landing Pages simples dentro de modelo jurídico/técnico homologado, COM-15 seção 14 autoriza política previamente revisada, individualizada e publicada com o site **sem clique prévio de aceite individual do texto**. O aceite do SITE permanece próprio; tratamentos excepcionais requerem revisão específica. Não reintroduzir o bloqueio histórico superado.
7. **Site estático sem CRM:** IN127-R06 permite publicação sem conta CRM sob aceite do cliente e demais condições, mas **proíbe ativar e receber formulários** sem destino válido oficialmente autorizado, inclusive nas APIs e páginas em cache. Site no ar e captação habilitada são fatos independentes.
8. **Autoridades independentes:** COMERCIAL/FINANCEIRO atesta contrato/pagamento; camada de publicação atesta artefato implantado; Portal governa estado oficial de entrega; CRM atesta registro de leads; segurança governa revogações. Agentes, n8n e resposta de CDN não podem declarar fatos fora de sua competência.
9. **Recuperação e rollback:** IN-143 e IN-156 requerem verificação, auditoria e alçadas. **AGI-11-RB01 de rollback automático permanece desabilitado**; qualquer restauração ou reversão sensível precisa de autorização e contrato específico, sem execução automática presumida.

## 3. Alternativas AUT-P08

### A — Um estado único do projeto

Apenas `em_producao / publicado / entregue / falhou`, alterado conforme última ação de agente ou publisher.

**Vantagem:** telas simples. **Problemas:** uma falha no deploy de nova versão pode esconder uma versão anterior ainda disponível; incidentes apagam o histórico de entrega; site no ar pode ser confundido com captação habilitada. **Não recomendada.**

### B — Modelo de estados por autoridade e eventos comprovados (RECOMENDADA; NÃO RATIFICADA)

Separar as dimensões oficiais, correlacionadas por projeto e revisão, sem atribuir várias competências ao mesmo booleano.

| Dimensão lógica | Fonte/autoridade proposta | Exemplos de fatos distintos |
|---|---|---|
| **Produção e aprovação** | Produção de Sites + Portal/cliente autorizado | Revisão preparada, submetida ao aceite, aceita, ajustes solicitados, revisão substituída |
| **Publicação técnica** | Publicador/validador técnico de Sites | Publicação solicitada, em execução, artefato verificado no destino, falha comprovada, resultado inconclusivo |
| **Disponibilidade operacional** | Verificação/monitoramento sob governança técnica | URL acessível, degradação, indisponibilidade, incidente ativo, recuperação verificada |
| **Checklist/entrega oficial** | Portal como autoridade do projeto, baseado no verificador COM-13 | Pendente de checklist, bloqueada, elegível, **ENTREGUE oficial**, evidência posterior de incidente |
| **Funcionalidades e conformidade** | Autoridades específicas Site × CRM, segurança, privacidade | Formulários desabilitados, integração autorizada, política vigente, consentimentos necessários |
| **Contrato/cobrança** | Sistemas comerciais e financeiros oficiais | Contrato vigente, suspensão válida, regularização; **não derivar** de status técnico |

**Diretrizes propostas para a B:**

1. **Comando não é fato:** solicitar publicação, receber HTTP 2xx do provedor ou terminar workflow não prova que o artefato correto está acessível. Registrar solicitação, aplicação e verificação independente.
2. **Revisão exata:** evento de publicação referencia `site_id`, `release_id`, manifesto/artefato de AUT-P04, ambiente, URL prevista e versão verificada. Revisão A no ar não comprova que B entrou em produção.
3. **Conservação de estado anterior:** falha ao publicar B não significa que A deixou de estar no ar. Registrar separadamente tentativa B, versão efetivamente servida, estado de disponibilidade e eventuais riscos. Não declarar o site disponível se a verificação falhar.
4. **Incidente e correção:** abrir incidente correlacionado e classificar impacto; uma tentativa de reparo ou restore não altera o estado para "recuperado" sem confirmação posterior. Não usar rollback automático AGI-11-RB01.
5. **Gate de entrega:** o Portal só registra **ENTREGUE** após avaliação determinística das provas aplicáveis, aceite de versão, correspondência do manifesto, publicação funcional, checklist vigente por produto, segurança e LGPD aplicáveis e inexistência de bloqueadores. `não aplicável` exige justificativa verificável.
6. **Entrega idempotente:** um único evento oficial de primeira entrega por projeto/jornada elegível; atualização posterior cria histórico de revisão/publicação, **não** um novo Dia 0 automaticamente. Regras para nova entrega/reentrega ou reabertura da REL-01 são pendentes e não presumidas.
7. **Depois da entrega:** manter registro histórico **ENTREGUE em data/hora**, juntamente com um **estado operacional atual** que pode ser degradado ou indisponível. Incidente posterior não desfaz o fato histórico nem implica que a entrega original foi falsa; eventual correção de erro de registro requer processo auditável próprio.
8. **Site sem CRM:** pode estar publicado e elegível a entrega de site estático quando cumprimento contratado/checklist for comprovado, apesar de **formulários desabilitados**; não transformar isso em consentimento para captar leads.
9. **Política padrão COM-15:** incluir prova do modelo jurídico homologado, personalização validada, política publicada e controles técnicos; **não exigir aceite individual prévio da política padrão** nos casos elegíveis. Exceções jurídicas seguem autoridade competente.
10. **Mudanças relevantes:** nova versão/manifesto ou mudança material posterior pode exigir novo aceite e novo checklist; não sobrescrever histórico anterior nem considerar aceites antigos suficientes.
11. **Conciliação de eventos:** perda de resposta do publicador, observações divergentes de CDN, monitor e Portal, retries e atualização concorrente devem gerar `inconclusivo` ou bloqueio seguro até prova; não completar projeto por suposição do agente.
12. **Transparência:** Portal exibe estado de produção/aceite, publicação, disponibilidade, funcionalidades disponíveis, entrega oficial e incidentes nos limites de permissão. Separar mensagens ao cliente de estado técnico/financeiro.
13. **Auditoria:** evento e transição referenciam autoridade, identidade, projeto, versão, resultado, momento/fuso, provas, exceções, correções e autorizações humanas quando exigidas; nenhuma IA autoaprova operação crítica.
14. **Separação de estado financeiro:** site comercialmente suspenso, com versão ainda tecnicamente servida/cached, não equivale a entrega autorizada ou reativação. Aplicar política de contrato e exposição controlada por fonte oficial, sem inventar cancelamentos.

**Benefícios:** prova correta de publicação, entrega não duplicada, incidentes preservados e monitoramento coerente. **Desafios:** máquina de estados por domínio, versão observada via edge/CDN, resultado inconclusivo, autoridade de cada evento, idempotência e custo de monitoramento — a especificar posteriormente.

### C — IA/n8n é a autoridade única

Um agente ou execução n8n marca publicação, recuperação e entrega como concluídas quando entende que o trabalho terminou.

**Não recomendada / incompatível** com PORTAL-D01, COM-13, REL-01 e AUT-P04 B. A IA coordena, verifica e documenta, mas não inventa resultado oficial.

## 4. Exemplos concretos da alternativa B — NÃO RATIFICADOS

| Cenário | Publicação / operação | Entrega / efeitos |
|---|---|---|
| Cliente aprovou prévia, ainda não publicou | Sem prova de publicação | **Não ENTREGUE**; não inicia REL-01 |
| Deploy solicitado, retorno do provedor inconclusivo | **Publicação inconclusiva**, verificar artefato | Não declarar sucesso ou entrega |
| Artefato B falha no deploy e A continua no ar | A pode seguir servido, B falhou; validar fato | Não declarar B publicada nem reentregar A |
| URL disponível, mas checklist obrigatório falhou | Publicação pode estar factual | **Entrega bloqueada** |
| Site estático publicado sem CRM | Página pode estar no ar, formulários bloqueados no backend | Entrega possível apenas quando escopo e checklist estático satisfeitos; nenhum lead aceito |
| Política padrão elegível publicada sem clique do cliente | Comprovar modelo/revisão/adequação COM-15 | Não bloquear entrega por ausência desse clique; aceite de versão do site continua |
| Agente registra ENTREGUE com provas válidas | Versão exata e testes confirmados | Criar **evento oficial único**, marco Dia 0 REL-01, sem comunicação automática não autorizada |
| Site entregue fica fora do ar depois | Incidente e indisponibilidade operacional | Preservar entrega histórica; registrar problema e recuperação |
| Agente reinicia verificação da mesma entrega | Sem publicação nova necessária | **Não duplicar** ENTREGUE nem Dia 0 |
| Versão material muda depois de entregue | Nova revisão e validações | Novo aceite quando aplicável; não reabrir REL-01 por inferência |
| Recuperação por backup restaura versão antiga | Conferir conteúdo e autorizações, intervenção humana quando crítica | Não declarar recuperação por término do comando; não ativar rollback automático |
| Site suspenso financeiramente | Estado comercial oficial distinto | Não confundir cache ativo com autorização de prestação, captação ou nova entrega |

## 5. Contrato lógico AUT-P08-CT01 (proposta, NÃO HOMOLOGADO)

Possíveis referências: `site_id`, `project_id`, `contract_id`, `release_id`, `manifest_digest`, `acceptance_ref`, `publish_operation_id`, `publish_technical_state`, `observed_live_release_ref`, `health_observed_at`, `checklist_ref`, `delivery_event_ref`, `delivered_at`, `incident_id`, `recovery_ref`, `authority_ref`, `event_idempotency_key`.

**Não aprovados:** nomes/enums definitivos, gatilhos, timers, critérios de health-check e limiares, protocolo de autoridade, transações, semântica de CDN/cache, mecanismo de prova, retentativas, RPO/RTO, rollback, arquitetura, retenção de eventos, escopo da reentrega e política de reinício REL-01. Não configurar operação com base nesta minuta.

## 6. Fontes conferidas

- [PORTAL-COM-13 — checklist e entrega automática](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-13-entrega-automatica-checklist-qualidade-agente-ia.md).
- [PORTAL-D01 — aceite de versão](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D01-aceite-eletronico-versoes-orcamentos.md).
- [PORTAL-REL-01 — jornada após entrega](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-REL-01-jornada-pos-entrega-onboarding-30-dias.md).
- [PORTAL-COM-15 — política de privacidade padrão vigente, seção 14](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-15-central-documentos-legais-consentimentos.md).
- [IN-143 — continuidade/recuperação](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-143-continuidade-operacional-recuperacao-inteligente-desastres.md).
- [AUT-P04 — identidade da versão aprovada](AUT-P04-aprovacao-alternativa-B-2026-11-04.md).
- [IN127-R01 a R06 — captação sem CRM e demais políticas](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).

## 7. Pedido de deliberação — SOMENTE AUT-P08

**Recomendação: aprovar a alternativa B** — estados independentes, ligados a autoridade e evidência, para versão/aceite, publicação efetiva, disponibilidade/recuperação, checklist/entrega oficial, formulários/CRM e situação comercial; evento ENTREGUE idempotente sem revogar histórico em incidentes posteriores.

**Status final:** AUT-P01 a AUT-P07 alternativas B aprovadas; **AUT-P08 PENDENTE**, AUT-P09 e AUT-P10 PENDENTES; P0-DOC03 e RF-PEND-01 abertos, RF-PEND-04 não homologada, AGI-11-RB01 desabilitado. Nenhuma execução autorizada.
