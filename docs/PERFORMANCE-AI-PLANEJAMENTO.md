# Imagine Performance AI — arquitetura aprovada e planejamento funcional

**Data-base:** 2026-11-03  
**Produto:** Imagine Performance AI, integrante comercial do ecossistema Imagine.  
**Situação:** PERF-ARQ-01 **APROVADA**; PERF-01.01–01.14, PERF-02.01–02.22, PERF-03.01–03.28, PERF-04.01–04.30, PERF-05.01–05.36, PERF-06.01–06.34, PERF-07.01–07.36, PERF-08.01–08.42 e PERF-09.01–09.42 **APROVADOS FUNCIONALMENTE em 03/11/2026**; PERF-10.01–10.40 **PROPOSTOS, EM ANÁLISE**; demais módulos a detalhar. Nenhum módulo homologado tecnicamente.  
**Restrições:** não iniciar programação, integrações reais, provisionamento, gastos nem publicação de campanhas sem novo planejamento e aprovação.

## 1. PERF-ARQ-01 — decisão aprovada

O cliente utilizará o **Portal Imagine** como ponto central de acesso, autenticação, relacionamento comercial, contratação, cobranças, suporte, notificações e consulta dos serviços. O Imagine Performance AI será disponibilizado como módulo/experiência integrada ao Portal, mas seus agentes de IA, processos de campanha, integrações com provedores, execução, armazenamento operacional e monitoramento deverão ser isolados em domínio técnico próprio, com contratos de API e autorização entre sistemas.

O CRM Imagine terá **integração opcional**, sem ser pré-requisito para contratar Performance AI. O cliente poderá contratar exclusivamente Performance AI, ou combiná-lo com Sites Imagine e CRM Imagine. A situação de cada contrato, cobrança, acesso e suspensão deverá ser independente, preservando serviços não afetados.

**Não está decidido:** linguagem e framework finais do motor de Performance, fronteiras definitivas de implantação, tecnologia de mensageria, precificação, política final de autonomia dos agentes, limites orçamentários, contratos de API detalhados ou SLAs. A implementação atualmente existente no repositório imagine-portal não deve ser confundida com a arquitetura futura planejada.

## Documentos funcionais

- [PERF-01 — Estratégia, Qualificação de Leads e Gestão de Resultados](performance-ai/PERF-01-ESTRATEGIA-QUALIFICACAO-RESULTADOS.md): **APROVADO FUNCIONALMENTE** — PERF-01.01 a PERF-01.14, requisitos e critérios de aceite, sem homologação técnica.
- [PERF-02 — Onboarding Inteligente, Conexões, Diagnóstico e Liberação Controlada](performance-ai/PERF-02-ONBOARDING-INTELIGENTE.md): **APROVADO FUNCIONALMENTE** — PERF-02.01 a PERF-02.22, 22 critérios de homologação futura, sem programação.
- [PERF-03 — Gestão Inteligente de Google Ads](performance-ai/PERF-03-GESTAO-INTELIGENTE-GOOGLE-ADS.md): **APROVADO FUNCIONALMENTE** — PERF-03.01 a PERF-03.28, 28 critérios de homologação futura, sem programação.
- [PERF-04 — Gestão Inteligente de Meta Ads](performance-ai/PERF-04-GESTAO-INTELIGENTE-META-ADS.md): **APROVADO FUNCIONALMENTE** — PERF-04.01 a PERF-04.30, 30 critérios de homologação futura, sem programação.
- [PERF-05 — Produção Inteligente de Criativos, Anúncios e Landing Pages](performance-ai/PERF-05-PRODUCAO-INTELIGENTE-CRIATIVOS-ANUNCIOS-LANDING-PAGES.md): **APROVADO FUNCIONALMENTE** — PERF-05.01 a PERF-05.36, 36 cenários de homologação futura, sem programação.
- [PERF-06 — Mensuração Inteligente, Rastreamento e Atribuição de Resultados](performance-ai/PERF-06-MENSURACAO-RASTREAMENTO-ATRIBUICAO.md): **APROVADO FUNCIONALMENTE** — PERF-06.01 a PERF-06.34, 34 cenários de homologação futura, sem programação.
- [PERF-07 — Trilhas Estratégicas, Experimentos e Aprendizado Contínuo](performance-ai/PERF-07-TRILHAS-EXPERIMENTOS-APRENDIZADO-CONTINUO.md): **APROVADO FUNCIONALMENTE** — PERF-07.01 a PERF-07.36, 36 cenários de homologação futura, sem programação.
- [PERF-08 — Agentes de IA, Autonomia, Governança e Central de Aprovações](performance-ai/PERF-08-AGENTES-AUTONOMIA-GOVERNANCA-APROVACOES.md): **APROVADO FUNCIONALMENTE** — PERF-08.01 a PERF-08.42, 42 cenários de homologação futura, sem programação.
- [PERF-09 — Gestão Inteligente de Leads e Integração com CRM Imagine e CRMs Externos](performance-ai/PERF-09-GESTAO-LEADS-INTEGRACAO-CRM.md): **APROVADO FUNCIONALMENTE** — PERF-09.01 a PERF-09.42, 42 cenários de homologação futura, sem programação.
- [PERF-10 — Painéis Inteligentes, Relatórios e IA Consultiva](performance-ai/PERF-10-PAINEIS-RELATORIOS-IA-CONSULTIVA.md): **PROPOSTO PARA REVISÃO** — PERF-10.01 a PERF-10.40, 40 cenários de homologação futura, sem programação.

## 2. Inventário de módulos — PERF-01 a PERF-09 aprovados funcionalmente; demais sob planejamento

| ID | Módulo | Escopo proposto | Resultado a homologar |
| --- | --- | --- | --- |
| PERF-01 | Modelo de negócio e indicadores | ICP, objetivos, custos, lead válido/qualificado, oportunidade, venda, atribuição, metas e critérios de sucesso | Glossário único de KPI e contratos de qualidade |
| PERF-02 | Onboarding e diagnóstico | Briefing, acesso autorizado, auditoria de contas, pixels, formulários, landing pages, orçamento, operação comercial, linha de base | Diagnóstico rastreável antes de ativação |
| PERF-03 | Google Ads | Consulta, organização, campanhas, experimentos, otimização, relatório, limites e conciliação | Ações verificadas e compatíveis com API |
| PERF-04 | Meta Ads | Business/contas, campanhas, formulários, webhooks, eventos, otimização e acompanhamento | Permissões, leads e eventos homologados |
| PERF-05 | Criativos e páginas | Briefings, variações, políticas, revisão, prévia, testes e integração com Sites Imagine quando contratado | Publicação apenas quando autorizada |
| PERF-06 | Mensuração e atribuição | Origem, campanha, UTMs, identificadores de clique, eventos, deduplicação, consentimento, janelas de atribuição | Métricas reconciliáveis e confiabilidade explícita |
| PERF-07 | Experimentos e playbooks | Hipóteses, versões, comparadores, guardrails, evidências, promoção/reprovação de estratégias | Nenhum playbook tratado como validado sem evidência |
| PERF-08 | Agentes e governança | Agentes especialistas e supervisor, autonomia, central de aprovações, permissões, memória operacional e auditoria | Execuções tipadas e rastreáveis |
| PERF-09 | Leads e CRM | Ingestão resiliente, vínculo empresa/conta, qualificação, CRM Imagine opcional, APIs externas, feedback comercial | Integridade, idempotência e segregação por empresa |
| PERF-10 | Painéis e IA consultiva | KPIs, diagnósticos, ações, comparações, relatórios, perguntas do cliente, exportações | Exibir origem dos dados e período de atualização |
| PERF-11 | Monitoramento e contingência | Falhas, gastos, permissões vencidas, webhooks, APIs, reconciliação, retentativas, alertas e recuperação | Nenhuma execução crítica presumida como concluída |
| PERF-12 | Comercial e escalabilidade | Catálogo, planos, custos por empresa, capacidade, margens, fornecedores e retenção | Custos e regras testados antes de ofertas |

Os agentes são **papéis operacionais**, não necessariamente 12 processos ou sistemas independentes. Reaproveitar governança transversal sem criar acesso indevido entre empresas ou serviços.

## 3. Integrações propostas e separação de responsabilidades

| Origem ↔ destino | Finalidade | Limites propostos |
| --- | --- | --- |
| Portal Imagine ↔ Performance | Ativar acesso ao produto, receber situação comercial, mostrar painel e centralizar solicitações | Portal é autoridade comercial; Performance é autoridade de execução e métricas de anúncios |
| Performance ↔ Google Ads | Leitura de campanhas, custos, resultados; alterações previamente aprovadas | OAuth, projeto Google Cloud e nível de acesso homologados; verificar cotas, erros e resultado oficial |
| Performance ↔ Google Data Manager API | Ingestão autorizada de conversões comerciais elegíveis | Consentimento/base legal, normalização e proteção de identificadores, validação, diagnóstico e confirmação de processamento |
| Performance ↔ Meta Marketing API | Consulta, estrutura e gestão de anúncios e métricas | Permissões mínimas, revisão de aplicativo e nível de acesso aplicável |
| Meta Lead Ads → Performance → CRM opcional | Receber leads de formulário, recuperar detalhes autorizados, registrar e entregar | Webhook + reconciliação; deduplicação por identificadores externos; fila durável; autorização por empresa |
| CRM Imagine → Performance | Status confirmado de qualificação, oportunidade, venda, receita e eventual devolução de conversão ao provedor | CRM é autoridade da etapa comercial, não a inferência da IA; eventos versionados; proteção de dados |
| Performance ↔ Sites Imagine | Origem de formulários, URLs, alterações de landing pages e eventos | Apenas se contratado e expressamente autorizado; preservar governança de Sites e DNS |
| Performance ↔ CRM externo | Conectores opcionais de importação e feedback | Contratos homologados, sem pressupor CRM externo universal |
| Performance → Portal | Indicadores, recomendações, experimentos e auditoria exibidos ao cliente | Dados com origem, carimbo temporal, atualização e permissões de leitura |

### Contrato transversal mínimo proposto

Toda operação externa deve identificar **empresa (tenant), produto/serviço, cliente contratante, conta de anúncios, provedor, finalidade, responsável/ator, operação, versão, autorização, correlação, idempotência contextual e estado**.

Estados distintos: *solicitado; autorizado; em execução; recebido pelo provedor; confirmado; rejeitado; inconclusivo; reconciliado; cancelado*. Resposta de API não equivale automaticamente a mudança de campanha publicada nem a conversão aceita.

Eventos e comandos não devem duplicar vendas nem disparar múltiplos envios de leads. Reentregas devem reconhecer eventos já processados. Mudanças em gasto requerem limites explícitos por conta e contrato. Nenhum serviço acessa dados de outras empresas.

### Fluxo proposto de lead e conversão

1. Anúncio/formulário/página gera evento identificável e origem rastreável.
2. Serviço de ingestão autentica origem, registra recebimento durável, verifica campos, consentimento e deduplicação.
3. Lead é entregue ao CRM Imagine contratado e vinculado ou a destino externo homologado; quando não houver CRM, definir mecanismo mínimo de gestão/encaminhamento antes da ativação.
4. CRM registra estados comerciais confirmados e devolve eventos compatíveis e autorizados.
5. Performance calcula KPIs com tratamento de atrasos, mudanças de status, duplicidade, lacunas e atribuição incerta.
6. Eventos elegíveis podem ser enviados aos provedores mediante base legal e finalidade definida, com confirmação e reconciliação.

## 4. Autonomia e guardrails — proposta pendente

Três níveis: (1) IA apenas recomenda; (2) IA executa mudanças de baixo risco dentro de limites aprovados; (3) ações fora dos limites, despesas novas, mudança relevante de teto de gasto ou ações sensíveis exigem aprovação explícita. Prévia, simulação e rollback quando suportados. Pausa emergencial, regras de reativação e possibilidade de reverter devem ser homologadas separadamente: **não autorizar rollback ou suspensão autônomos por inferência**.

Regras de análise: não confundir CPL com CAC; distinguir lead bruto, lead válido, lead qualificado, oportunidade e venda; não supor causalidade de comparações simples; não tratar métricas estimadas como receita confirmada; identificar amostra pequena, janelas de conversão, atraso de dados e fase de aprendizado.

## 5. Critérios preliminares de homologação — proposta

- Identidade e autorização da empresa são verificadas em toda consulta, tarefa, evento e webhook.
- Contrato de Performance pode ser ativo/inativo sem alterar Site ou CRM pagos.
- Conexões Google/Meta são estabelecidas com consentimento e permissões mínimas, desconectáveis e monitoradas.
- Evento duplicado não gera dois leads nem duas conversões comerciais; envio inconclusivo é reconciliado antes de repetir.
- O sistema nunca aumenta orçamento fora da alçada contratual e sinaliza custos das plataformas separados da cobrança Imagine.
- O painel informa fonte, data, atualização, status de confiabilidade e possível diferença de atribuição.
- Falhas de API ou credenciais não são mascaradas como sucesso; ações têm trilha de auditoria.
- Qualquer feedback ao provedor de anúncios só usa dados apropriados para a finalidade autorizada, com requisitos de privacidade e plataforma.
- Pilotos e testes comprovam ganho operacional/comercial antes de escala; nenhuma promessa automática de desempenho.

## 6. Pendências para próximas aprovações

**P0:** aplicar as definições PERF-01 aprovadas de qualificação/metas; resolver autoridade comercial e vínculo de contas; autorização e teto de gastos; identidade/segurança multiempresa; privacidade; ingestão resiliente; contratos de eventos; segregação Portal/Performance/CRM; homologação de APIs.  
**P1:** onboarding e diagnósticos; playbooks, experimentos e auditoria de criativos; governança dos agentes; painel e monitoramento.  
**P2:** empacotamento comercial, precificação, escala e recursos avançados.

Etapa atual: **PERF-10 — Painéis Inteligentes, Relatórios e IA Consultiva em revisão**. As aprovações anteriores não representam aprovação da PERF-10, dos relatórios automáticos, das políticas comerciais e de retenção, das consultas de IA com dados reais, dos contratos técnicos ou da implementação.

## 7. Referências técnicas para futura homologação

- Google Ads API: https://developers.google.com/google-ads/api/docs/api-policy/developer-token (tokens de desenvolvedor desativados em 09/09/2026; acesso associado ao projeto Google Cloud).
- Google OAuth: https://developers.google.com/google-ads/api/docs/oauth/overview
- Google Data Manager API: https://developers.google.com/data-manager/api/devguides/events/google-ads/offline
- Meta: https://developers.meta.com/blog/updates-to-ads-management-standard-access-feature/ (mudanças de níveis de acesso em maio/2026).
- Meta Blueprint sobre qualidade de leads: https://www.facebookblueprint.com/student/path/264294-conversions-api-crm-lead-quality

> Este arquivo documenta planejamento e a decisão já aprovada. Não autoriza escrita de código, implantação, despesas, conexões de contas, alterações de campanhas ou acesso a dados de clientes.
