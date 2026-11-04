# P0-DOC03 / AUT-P04 — Identidade da versão do site, prova de aceite e integridade do artefato publicado

> **APROVAÇÃO POSTERIOR (04/11/2026):** o responsável aprovou a **alternativa B**. Consultar [registro AUT-P04 B](AUT-P04-aprovacao-alternativa-B-2026-11-04.md). Esta minuta conserva as alternativas e a situação **anterior** à aprovação; os trechos que dizem “não ratificada” são históricos. Contratos técnicos, P0-DOC03, RF-PEND-01 e RF-PEND-04 seguem em aberto.


**STATUS:** PROPOSTA DOCUMENTAL PARA DELIBERAÇÃO — ALTERNATIVAS A/B/C NÃO RATIFICADAS.  
**Data:** 04/11/2026. **Projeto:** Imagine Sites / Portal Imagine / Produção Inteligente.  
**Objetivo:** assegurar que a versão efetivamente apresentada e aceita pelo cliente autorizado seja precisamente a que poderá ser publicada/entregue, com prova e integridade verificáveis.  
**Precedências preservadas:** PORTAL-D01 (aceite expresso e versionado), PORTAL-COM-13 (checklist de entrega automática com evidências), PORTAL-COM-14/15 (LGPD/documentos legais), AUT-P01 B, AUT-P02 B, AUT-P03 B, IN127-R01 a R06 e demais políticas pertinentes.  
**Fronteiras:** **AUT-P04 pendente**; AUT-P05 a AUT-P10 não analisadas/ratificadas nesta decisão; P0-DOC03 e RF-PEND-01 ABERTOS; RF-PEND-04 ainda não homologada. **Nenhuma programação, teste, implantação, homologação ou mudança de infraestrutura autorizada. AGI-11-RB01 DESABILITADO.**

## 1. Decisões anteriores que devem ser preservadas

1. O **aceite do site** é manifestação **expressa**, por pessoa autenticada e autorizada no contrato/projeto, sobre **a versão/escopo exatos**. Silêncio, visita à prévia, abertura de link, mensagem de IA ou expiração de prazo **não aprovam** site. Fonte: PORTAL-D01.
2. A evidência do aceite deve manter identidade e autoridade do aprovador, objeto e versão, manifestação, conteúdo/condições essenciais, instante e fuso, proteção de integridade e histórico. Mudanças materiais não reescrevem o aceite antigo e exigem novo aceite quando aplicável. Fonte: PORTAL-D01.
3. A entrega formal pode ser **automática pelo agente IA**, sem clique final rotineiro da equipe Imagine, **somente** após checklist integral com evidências verificáveis, aceites exigidos válidos e ausência de bloqueadores; o evento oficial **ENTREGUE** é próprio, distinto de prévia/aceite/publicação, e marca o Dia 0 da REL-01. Fonte: PORTAL-COM-13.
4. A **Política de Privacidade padrão para Onepages/Landing Pages simples** dentro do modelo homologado pode ser adaptada e publicada com o site **sem aceite individual prévio do texto da política**, conforme decisão posterior de COM-15, seção 14. Isso **não dispensa o aceite expresso da versão do site**, nem a verificação jurídica/técnica da política; exceções legais e consentimentos de visitantes têm fluxos próprios.
5. A versão do formulário e seu destino CRM são verificações próprias de AUT-P01/02 e IN127-R06; **aceite visual do site não autoriza captação** sem vínculo CRM válido e autorização comprovada. Um site estático sem CRM pode ser publicado dentro das condições existentes.
6. Agentes não aprovam em nome do cliente, não falsificam provas e não dispensam controles determinísticos.

## 2. Problema P0-DOC03 / AUT-P04

Uma URL de prévia pode continuar igual enquanto arquivos, textos, imagens, scripts, integração de formulários ou configuração mudam. Um simples botão "Aprovar" ou um registro de URL não comprova **o que estava efetivamente disponível para o cliente** nem que o artefato que chega à produção é aquele aprovado.

A decisão precisa identificar a autoridade sobre **(a) versão e artefato**, **(b) identidade/autorização do aprovador**, **(c) registro de decisão** e **(d) correspondência com a publicação e entrega**. Isso não deve misturar aceite do site com consentimentos específicos LGPD nem aceite de orçamento.

## 3. Alternativas para ratificação

### A — Aceite por URL e número de versão

O Portal registra a URL de prévia, um número de versão e o clique do cliente; a publicação se orienta por esses campos.

**Vantagens:** registro simples e baixo esforço inicial.  
**Limitações:** URL pode apontar para conteúdo mutável; número de versão sem integridade não comprova artefato; prova insuficiente em corrida entre edição, aceite e deploy. **Não recomendada como mecanismo único.**

### B — Manifesto imutável por versão + aceite expresso vinculado + verificação de igualdade no gate (RECOMENDADA, NÃO RATIFICADA)

Cada versão submetida a aceite possui **manifesto identificável e imutável**, contendo referências verificáveis ao pacote de publicação e suas configurações relevantes, com **hashes de integridade dos artefatos sob controle da Imagine**. No Portal, o cliente autorizado recebe acesso à representação da **mesma versão congelada** e registra uma decisão expressa que referencia o manifesto.

O motor de publicação/checklist **compara a identidade da versão aprovada** com a identidade do artefato realmente selecionado e implantado; falha, ausência de prova ou divergência **bloqueia a entrega e, quando aplicável, a publicação** até tratamento autorizado.

**Controles propostos:**
1. Identificadores persistentes: empresa, contrato, projeto/site, revisão do artefato, manifesto e ambiente.
2. Manifesto de versão: artefatos estáticos e assets, configuração de publicação, variantes/referências relevantes e dependências externas que possam alterar comportamento; digest calculado por método tecnicamente homologado posteriormente.
3. **Congelamento antes do aceite:** o conteúdo apresentado para decisão não é sobrescrito silenciosamente. Ajustes que alterem o objeto criam nova revisão ou registram alteração material autorizada, conforme classificação ainda a definir.
4. **Decisão por pessoa autorizada:** autenticação, autorização no momento do clique, texto/escopo/condições exibidos e decisão positiva explícita; recusa ou pedido de ajustes não equivalem a aceite.
5. **Comprovante de aceite:** vínculo ao manifesto exato e representação exibida, identidade/autoridade, tempo e fuso, resultado, referências técnicas de integridade, eventual contestação e histórico protegido.
6. **Gate determinístico:** para publicar/entregar, verificar que o manifesto aprovado ainda corresponde à versão preparada e ao conjunto de condições atuais; não aceitar uma aprovação de versão A para publicar a versão B.
7. **Publicação comprovada:** relacionar revisão implantada e seu resultado observado na URL de destino à referência do manifesto, sem tomar mero sucesso do deploy como prova de entrega oficial.
8. **Mudança após aceite:** mudança material invalida aprovação anterior para o objeto modificado; mudanças comprovadamente não materiais seguem política explícita posterior, sem presumir liberdade irrestrita para agentes.
9. **Falha e correção:** divergência, falta de artefato, arquivo substituído, prova inconsistente ou acesso do aprovador revogado devem parar o gate e gerar pendência. Não reescrever aceites antigos.
10. **Proteção multiempresa:** não expor materiais nem provas de outras empresas; preservar controle de acessos, integridade, retenção legalmente apropriada e auditoria.

**Vantagens:** rastreabilidade do objeto aprovado, resistência a alterações concorrentes, defesa auditável de aceite, compatibilidade com GitHub/versionamento e checklist.  
**Desafios:** armazenamento de snapshots, hashing de assets e configuração, efeitos de dependências externas, custo de preservar prévias/evidências, critérios de mudanças materiais e mecanismo jurídico/técnico de prova a homologar. **Hash isolado não demonstra que a pessoa visualizou o conteúdo**: é necessário vínculo entre a apresentação, a decisão e o manifesto.

### C — Aceite por agente IA ou publicação presumida

Agente determina que a prévia está suficientemente boa, ou considera ausência de resposta como aprovação e publica.

**Não recomendada e incompatível** com PORTAL-D01 e COM-13. A IA pode propor, verificar e executar dentro dos gates, nunca substituir manifestação expressa do cliente.

## 4. Autoridades lógicas propostas na alternativa B (não aprovadas)

| Assunto | Autoridade lógica sugerida | Observação |
|---|---|---|
| Versão do site e manifesto | Sistema de produção/versionamento de Sites sob governança Imagine | Identificação independente de URL mutável |
| Cliente e autorização para aprovar | Identidade/permissões verificadas pelo Portal e vínculo com o contrato | Verificação no momento do aceite |
| Ato de aceite/recusa/ajuste | Portal Imagine, evento expresso com trilha auditável | Não é marketing nem aceite jurídico genérico |
| Artefato realmente publicado | Camada de implantação/validação técnica de Sites | Confirma versão implantada; não cria aceite |
| Entrega oficial | Estado de projeto competente no Portal após COM-13 | Checklist e evento idempotente; não apenas deploy |
| Políticas e consentimentos legais | Central COM-15 e suas autoridades competentes | Fluxo separado; precedência da seção 14 para padrão simples |

## 5. Casos críticos para preservar na especificação

| Caso | Tratamento recomendado pela B |
|---|---|
| Cliente abre prévia antiga e aprova depois de nova revisão | Validar que a decisão corresponde à revisão exibida e ainda elegível; não aplicar à nova |
| URL da prévia idêntica, assets alterados | Identificar divergência pelo manifesto/digest; exigir versão válida |
| Agente corrige conteúdo após aceite | Nova revisão e nova avaliação de necessidade de aceite, conforme regra material |
| Alteração de domínio ou ambiente sem mudar código | Não deduzir equivalência; verificar efeitos no objeto aceito e checklist aplicável |
| Política de Privacidade padrão simples | Validar texto oficial adaptado, sem exigir clique individual prévio da política; **aceite do site permanece** |
| Formulário sem CRM autorizado | Pode haver publicação estática conforme R06, mas captação bloqueada |
| Entrega IA após o deploy | Só gerar ENTREGUE se artefato, aprovações, testes, privacidade e bloqueadores estiverem consistentes |
| Contestação do aceite | Preservar prova original e registrar contestação/correção auditável sem apagar histórico |
| Provedor externo altera conteúdo/script | Identificar risco e governar dependências; limites de snapshot e homologação pendentes |

## 6. Contrato lógico candidato AUT-P04-CT01 (NÃO ratificado)

Classes de dados a detalhar: `tenant_id`, `contract_id`, `site_id`, `release_id`, `manifest_revision`, `manifest_digest`, `preview_snapshot_ref`, `approved_scope_ref`, `actor_id`, `actor_authorization_ref`, `decision_type`, `decision_at`, `decision_timezone`, `acceptance_record_ref`, `publication_artifact_ref`, `deployed_digest`, `checklist_version`, `delivery_event_ref`, `superseded_by` e evidências proporcionais de auditoria.

**Não aprovados por inferência:** schema, hash específico, formato de prova/assinatura eletrônica, tecnologia de armazenamento, TTL, política de retenção, classificação detalhada de mudança material, experiência de usuário, momento exato de bloqueio de publicação e critérios de homologação.

## 7. Pergunta para decisão — somente AUT-P04

**Minha recomendação:** **alternativa B**, identidade imutável da versão via manifesto e evidências de conteúdo, aceite expresso/autenticado vinculado ao objeto exato e gate determinístico para verificar identidade com o artefato publicado/entregue; ausência ou divergência de provas bloqueia. Preservar a regra vigente da Política de Privacidade padrão simples, sem misturar os eventos.

**Situação final:** AUT-P01 B, AUT-P02 B e AUT-P03 B aprovadas; **AUT-P04 apenas proposta**, AUT-P05 a AUT-P10 pendentes. **P0-DOC03/RF-PEND-01 abertos; AGI-11-RB01 desabilitado.** Não há autorização de desenvolvimento.
