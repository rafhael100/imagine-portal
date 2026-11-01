# Imagine — Produção Inteligente de Sites

> **Documento de planejamento v0.2 — RASCUNHO PARA REVISÃO; DECISÃO AD-001 APROVADA**
>
> Data: 2026-11-01. Base examinada: `imagine-portal` v0.18.2 (`main`).
> Este documento **não autoriza implementação, ativação de agentes, geração de cobranças, deploy ou alteração de produção**.
> Regras já aprovadas do Onepage prevalecem até deliberação explícita em contrário.

## 1. Objetivo e limites

Automatizar a jornada após a contratação do **Site Onepage**: pagamento confirmado → briefing e materiais → complementação → validação → design/conteúdo → geração do site → testes → prévia versionada → aprovação ou duas rodadas de ajustes → publicação em domínio do cliente → verificação → comunicação de entrega → suporte.

A automação deve reutilizar o portal, a administração, a conversa por pedido, a trilha de auditoria e as transições de produção existentes. **Decisão AD-001 (aprovada em 2026-11-01): operação altamente automatizada**, com atendimento, triagem, desenvolvimento, testes, prévia e publicação definitiva automáticos **após aprovação explícita do cliente e gates técnicos determinísticos**; intervenção da equipe Imagine somente em exceções e nas mudanças comerciais/administrativas restritas. A IA não ganha autonomia para mudar preços, termos contratuais, criar cobranças, emitir NFS-e, acessar projetos de terceiros ou dispensar autorização do titular.

**Primeiro escopo:** somente Site Onepage; sistemas, aplicativos e projetos personalizados de IA continuam com proposta e gestão humana.

## 2. Estado atual do repositório (evidenciado no código/documentação)

| Área | Existente | Lacuna |
| --- | --- | --- |
| Site público | `index.html`; apresentação e contratação | Checkout público direto ainda em prévia |
| Portal | contas, pedidos, mensagens, cobrança integrada ao pedido | interlocutor IA com autoria própria, eventos e notificações |
| Materiais | `server/briefing.mjs`; rascunho/envio/revisão; upload privado de logo/manual | fotos, documentos gerais e referências; processamento seguro e inventário |
| Produção | `server/production.mjs`; etapas, progresso real 0/25/50/75/100, prévia versionada e aprovação do titular | gerador, testes e deploy efetivos |
| Administração | equipe, atividades, BI, reclamações, financeiro, fiscal | visão dos trabalhos dos agentes e mecanismos de pausa/retomada |
| Pagamentos | Asaas integrado por código, com conciliação | homologação real ainda pendente; IA não modifica esta área |
| Fiscal | Focus NFe configurável | ativação e validação fiscal ainda pendentes; IA não modifica esta área |
| Testes | suíte automatizada e workflow GitHub Actions | testes novos de agentes, isolamento, publicação e recuperação de falhas |

O estado atual registra `production.preview_url` e `production.live_url`, mas não executa o deploy nem altera DNS. O briefing aceita apenas tipos de materiais previstos atualmente (logo e manual); as mensagens do pedido são textuais. O código deve ser inspecionado novamente imediatamente antes de iniciar a implementação.

## 3. Princípios de arquitetura

1. **Backend é a fonte da verdade.** Agentes propõem resultados; regras determinísticas verificam autorização, estados e transições.
2. **Isolamento por pedido/cliente.** Um trabalho nunca lê arquivos, mensagens, repositórios ou credenciais de outro pedido.
3. **Execução assíncrona durável.** Jobs persistidos, reentrantes e idempotentes; não depender do navegador do cliente nem de uma conversa aberta no Codex.
4. **Separação de ambientes.** Servidor da aplicação Imagine, workers de programação, prévias e sites de produção com privilégios distintos.
5. **Aprovação explícita da versão atual.** Nenhum site vai ao ar por simples passagem de tempo ou por aprovação de prévia antiga.
6. **Visibilidade e reversibilidade.** Cliente acompanha marcos; Imagine vê logs e custos; falha de deploy não vira entrega concluída.
7. **Escopo comercial preservado.** IA não inventa serviços, garantias, valores, prazos ou concessões não contratados.

## 4. Agentes propostos

| Agente | Entrada | Saída/ação permitida | Escalada |
| --- | --- | --- | --- |
| 1. Atendimento | histórico autorizado do pedido, FAQ, status | responder com identificação de IA, pedir complementos, registrar resumo | reclamação, disputa, desconto, dúvida fora do escopo, pedido de humano |
| 2. Analista de materiais | briefing e arquivos previamente verificados | checklist, detecção de ausências, resumo técnico com referências às fontes | informações contraditórias, direitos de uso, arquivos suspeitos |
| 3. Conteúdo e design | briefing aprovado, catálogo de componentes | plano de páginas, seções, textos e estilo em artefato versionado | conteúdo sensível, afirmações não fornecidas, necessidade de serviço extra |
| 4. Desenvolvedor (Codex) | especificação aprovada e template Imagine | mudanças no repositório isolado do projeto, testes de build | falha repetida, dependência insegura, mudança fora do Onepage |
| 5. Qualidade e publicação | build produzido e critérios de aceitação | testes, relatório, deploy de **prévia**; deploy final somente após gates | testes bloqueantes, DNS sem controle, indisponibilidade, erro no deploy |
| 6. Entrega e pós-venda | site definitivo verificado e status contratual | URL final, instruções, registro de entrega, encaminhamento ao suporte | falha de entrega, cobrança, reclamações, alterações com preço |

**Orquestrador (software, não IA autônoma):** seleciona tarefas, aplica regras, limites de custo, permissões e escalonamento. As seis especialidades não exigem seis modelos ligados continuamente; a execução pode reaproveitar modelos com ferramentas/credenciais diferentes.

## 5. Jornada de ponta a ponta e regras dos gates

| Ordem | Gatilho | Ação | Critério de avanço |
| --- | --- | --- | --- |
| 0 | pedido Site Onepage + primeiro pagamento efetivamente confirmado | liberar briefing e registrar início do atendimento | estado financeiro conciliado pelo servidor |
| 1 | briefing enviado | analisar campos e arquivos autorizados | checklist estruturado, anexos seguros e pendências reconhecidas |
| 2 | faltam informações | IA solicita complemento no pedido | cliente responde, novo snapshot é avaliado |
| 3 | informações completas | IA gera parecer de completude + serviço de regras valida o briefing automaticamente | avançar somente se todos os requisitos objetivos forem satisfeitos; dúvida ou risco gera pendência Imagine, sem aprovação automática insegura |
| 4 | briefing aprovado | iniciar produção e calcular prazo | prazo inicial de 7 dias úteis após validação, sem simular feriados |
| 5 | produção iniciada | design/conteúdo e execução Codex em sandbox | artefatos versionados, builds e testes mínimos concluídos |
| 6 | testes aprovados | criar deploy de prévia e registrar versão | URL HTTPS real, acessível ao titular e conferida pelo sistema |
| 7 | prévia pronta | avisar cliente via portal; e-mail se canal configurado | registro de notificação, sem alegar leitura não confirmada |
| 8a | cliente pede alterações | documentar pedido e direcionar nova geração | contar rodadas conforme contrato, máximo de 2 incluídas |
| 8b | cliente aprova | gravar autorização explícita da **versão atual** | titular autenticado e confirmação versionada |
| 9 | versão aprovada | controles determinísticos de liberação para produção | validar identidade do cliente, aprovação imutável da versão, testes, domínio autorizado, SSL, backups/rollback e limites; exceção abre revisão Imagine |
| 10 | publicação autorizada automaticamente pelos gates | implantar **exatamente o artefato aprovado**; gerenciar DNS apenas sob autorização e credenciais limitadas; executar testes de fumaça | URL final HTTPS funcionando, versão/ambiente conferidos; falha não conclui pedido |
| 11 | site verificado | registrar publicação e comunicar entrega | projeto passa a `published`; suporte e contrato continuam no portal |

Caminho 8a → novo build → novos testes → nova prévia → etapa 7. Ajustes extras exigem avaliação da Imagine e **não** podem ultrapassar automaticamente as condições contratadas.

A evolução 0/25/50/75/100 decorre de marcos efetivos, nunca de timer. A publicação não altera por si só o início da assinatura, que segue as condições comerciais aprovadas.

### Exceções

- Pagamento sem confirmação, estorno ou disputa: não iniciar/continuar operações dependentes do pagamento; exigir política específica para projetos já iniciados.
- Documentos ausentes ou conflitantes: pausar apenas o job de materiais; pedir complemento sem repetição excessiva.
- Falha de modelo/worker: retentativa limitada, backoff, fila de incidentes e atendimento humano.
- Limite de custo/tentativas: pausar e solicitar revisão da Imagine.
- Cliente sem domínio configurado: permitir prévia, manter publicação pendente e informar exatamente os registros necessários.
- Site aprovado mas novo código foi gerado: invalidar autorização anterior e solicitar aprovação da nova versão.
- Falha após deploy: manter fase de publicação pendente, executar rollback quando possível e registrar diagnóstico; não declarar entrega concluída.
- Pedido de alteração após publicação: encaminhar à área atual de manutenção/suporte, com orçamento quando cabível.

## 6. Papéis e limites de autorização

| Ação | Cliente titular | IA | Equipe Imagine |
| --- | --- | --- | --- |
| Ver e alterar próprio briefing | Sim, conforme estado | Ler somente pedido autorizado; sugerir complemento | Conforme perfil |
| Ler materiais/mensagens de outro cliente | Não | Nunca | Somente perfis explicitamente autorizados |
| Validar briefing | Envia/corrige | Avaliação IA + validação automática por regras; não faz exceções | Atua apenas em inconsistência, material de risco ou exceção |
| Criar artefatos e versões | Não | Sim, em sandbox isolada | Sim |
| Publicar prévia | Ver | Sim, depois de testes e regras | Sim/supervisionar |
| Aprovar versão de site | Sim, somente titular e versão atual | Nunca | Não em nome do cliente |
| Publicar produção | Aprova explicitamente a versão exata | Executa só após todos os gates determinísticos | Supervisiona e intervém em exceções, sem aprovação manual rotineira |
| Alterar preço/assinatura/fiscal | Somente operações previstas no portal | Nunca | Perfis autorizados e regras existentes |
| Pausar/reiniciar agente | Não | Nunca por conta própria | Sim, com auditoria |

**Identidade:** não reaproveitar sessão administrativa nem criar agente como `admin` comum. Evoluir modelo de identidade de serviço com escopos, tokens efêmeros e trilha de auditoria para máquina/humano.

## 7. Dados e integrações (proposta, não esquema implantado)

- `agent_runs`: id, order_id, specialty, input_snapshot_id, status, retry_count, cost_estimate, trace_id, started/finished.
- `agent_run_events`: etapa, autor humano/serviço, horário, tipo, resumo sem segredos.
- `project_assets`: arquivo, tipo, tamanho/hash, revisão, verificação antimalware, origem e política de acesso.
- `brief_snapshots`: versão imutável do briefing e IDs dos materiais utilizados.
- `project_artifacts`: plano, código, commit SHA, build, testes, versão da prévia.
- `deployments`: ambiente, versão, URL, status, data, logs de verificação, opção de rollback.
- `notifications`: canal, template, estado de envio/erro, idempotency key (não presumir entrega/leitura).
- `agent_messages` **ou extensão auditada** de `messages`: autoria do serviço, conteúdo, motivo e vinculação ao job.

Esses nomes são preliminares: antes da implementação, confrontar com tabelas, migrações e perfis existentes para evitar duplicidade. Não introduzir mudanças destrutivas e preservar pedidos legados.

### Arquitetura sugerida

`Portal/Admin Node.js → API do pedido → Orquestrador e fila persistente → workers de IA/Codex com isolamento → repositório privado por projeto → build/testes → hospedagem de prévias → aprovação do cliente → gate da Imagine → deploy de produção → monitoramento/entrega`.

**Separação da fila financeira:** o loop atual do Asaas não deve executar builds de sites ou tarefas de IA. Os workers precisam ter limites de concorrência, timeout, retentativa e observabilidade próprios. SQLite pode permanecer inicialmente para o portal, sujeitando o desenho da fila às garantias de persistência e concorrência necessárias; estudar evolução quando houver escala.

### Contrato entre etapas

Handoffs devem transmitir objeto validado, não apenas um prompt livre: `order_id`, `brief_version`, `assets[]`, `scope_snapshot`, `release_id`, `checks[]`, `deployment_target`, `approval_token/ref` e `agent_run_id`. Nenhum agente aceita diretamente texto do cliente como instrução para operações privilegiadas.

## 8. Conteúdo, arquivos, privacidade e segurança

- Permitir fotos e documentos necessários, com extensão/MIME e assinatura verificados, limites por tipo, quota por pedido, quarentena e varredura antimalware antes do processamento.
- Arquivos armazenados fora da raiz pública; downloads e URLs temporárias limitados ao pedido; política de exclusão/retenção e backups privados.
- Mensagens e documentos do cliente são **dados não confiáveis**, nunca instruções para executar comandos, exfiltrar dados, mudar política ou ler outros pedidos.
- Modelos acessam apenas arquivos e mensagens do pedido por meio de ferramentas com controle de escopo e auditoria. Não fornecer `.env`, chaves de Asaas/Focus, sessão do administrador ou repositório do portal aos jobs de cliente.
- Execução de código em containers/sandboxes isoladas, com limites de CPU/memória/duração, rede restrita, dependências aprovadas, sem acesso a arquivos do host ou secrets.
- Sites publicados devem ser verificados contra scripts não autorizados, conteúdo não fornecido, vulnerabilidades, formulário de contato e uso indevido de marcas/imagens.
- Política de tratamento de dados conforme LGPD: informar uso de IA, finalidade, provedor, retenção e direitos do titular; garantir base legal e contratos adequados; não usar material do cliente em treinamento sem autorização pertinente.
- Logar decisões e IDs de artefatos sem copiar textos pessoais sensíveis para logs permanentes. Administradores devem poder pausar o fluxo e retirar acesso de agentes.

## 9. Comunicação no portal

As mensagens devem aparecer como **“Assistente virtual da Imagine”**, com horário e indicação de automatização. O agente deve usar status verdadeiros do pedido. Não pode afirmar “publicado”, “pagamento recebido”, “enviado por e-mail” ou “analisado por humano” sem confirmação do sistema.

Tipos iniciais: boas-vindas pós-pagamento, pedido de material, briefing recebido, complemento necessário, material aprovado, produção iniciada, prévia disponível, ajuste recebido, nova versão, aprovação registrada, publicação pendente de domínio, publicação concluída, falha/encaminhamento humano.

Sem integração SMTP homologada, a notificação permanece no portal, marcada como pendente de envio externo. WhatsApp automático não está implementado e requer integração e consentimento próprios.

## 10. Padrões de produção do Onepage

O desenvolvedor não deve começar cada projeto do zero. Criar uma **biblioteca de templates/componentes Imagine**, com seções padronizadas, responsividade, acessibilidade, SEO básico, formulário/WhatsApp, segurança e critérios de performance. O modelo escolhe e personaliza componentes segundo o briefing.

O conteúdo não deve inventar CNPJ, depoimentos, credenciais, certificações, endereço, preços ou resultados comerciais. Imagens do cliente precisam de direito de uso confirmado. Conteúdo gerado por IA deve ser identificado internamente até aceitação.

Cada site terá código/artefatos isolados e versionados; a prévia tem URL HTTPS distinta do site definitivo e acesso controlado, evitando indexação prematura. A hospedagem de clientes e o modelo de DNS ainda exigem decisão técnica específica.

## 11. Regras comerciais existentes — não alterar

- Onepage: implantação total R$ 990 em D0/D30/D60 (R$ 330 cada).
- Assinatura obrigatória R$ 79,90/mês, primeiro vencimento em D+90, independente da publicação; contrato legado mantém suas condições.
- Prazo anunciado: 7 dias úteis após validação dos materiais, sujeito a regras de bloqueios/feriados a detalhar sem mudar unilateralmente o contrato.
- Duas rodadas de ajustes incluídas.
- Cliente fornece domínio. Hospedagem do **site**, SSL, backup, suporte e licença seguem o contrato da oferta. **HD-001 aprovada em 2026-11-01: hospedagem de e-mail profissional e caixas postais não estão incluídas no Onepage**; preservar servidores e registros de e-mail existentes do titular.
- Publicação somente após aprovação explícita da versão atual pelo titular.
- Integrações Asaas e Focus NFe ainda exigem homologação/configuração real e aprovação fiscal; automação IA nunca antecipa estas ativações.

## 12. MVP proposto e plano de entrega

**Fase A — fundações:** escopos de identidade de serviço, auditoria, filas/jobs persistentes, snapshots, armazenamento seguro, painel de execução/pausa, simulação integral.
**Fase B — atendimento e materiais:** ingestão autorizada, análise, checklist, solicitações de complemento, validação automática baseada em regras e escalonamento humano somente nas exceções.
**Fase C — geração:** biblioteca base de templates, design/planejamento, integração programadora, versionamento por cliente, builds e testes.
**Fase D — prévia e revisões:** publicação de prévia HTTPS, notificações, versionamento, aprovação do titular e duas rodadas.
**Fase E — publicação e entrega:** gate automático de regras, DNS/SSL/rollback, verificação final, entrega e suporte; intervenção humana só nas exceções.

Não implementar produção real antes da documentação aprovada, dos testes e do piloto controlado.

## 13. Critérios mínimos de aceite do MVP

1. Pedido sem pagamento confirmado não dispara IA nem libera materiais.
2. Um cliente ou agente de outro pedido recebe 403/404, nunca arquivos, mensagens, código ou URL privada.
3. Mesmo evento/webhook repetido não gera dois jobs, cobranças ou sites.
4. Material incompleto leva a complemento sem avanço indevido.
5. Conteúdo malicioso em PDF/arquivo/mensagem não executa comandos nem vaza segredos.
6. Build com falha não gera link de prévia como se estivesse pronto.
7. Prévia é acessível em HTTPS ao cliente e corresponde exatamente ao commit/release registrado.
8. Solicitar alteração invalida a versão antiga e registra corretamente uma rodada; terceira rodada incluída é bloqueada.
9. A aprovação de `release=N` não autoriza publicar `release=N+1`.
10. Falha de DNS/SSL/deploy não conclui pedido e pode ser recuperada/retentada com segurança.
11. Agente não consegue emitir nota, alterar contrato/preço, token de cartão, chamar API financeira diretamente ou publicar sem gates.
12. A equipe pode pausar, auditar e retomar; logs mostram responsável, input, commit, custo, versão, ações e resultado.
13. Mensagens automáticas possuem autoria visível e transferência humana.
14. A jornada financeira/fiscal preexistente continua sem regressões, inclusive para contratos legados.

## 14. Decisões propostas para aprovação — pendentes

**AD-001 — Modelo de autonomia:** APROVADO em 2026-11-01: **altamente automatizado**, com autorização explícita do cliente para a versão atual e controles determinísticos obrigatórios antes da publicação. A Imagine trata exceções, não aprova cada produção de rotina.
2. **Hospedagem dos projetos dos clientes:** definir provedor, ambientes, isolamento, política de DNS, certificados, rollback, custos e backups antes do agente de deploy.
3. **Fonte de código por cliente:** recomendado repositório privado independente ou isolamento equivalente com regras de acesso restritas; não guardar sites de clientes no repositório de código do portal.
4. **Arquivos e quotas:** ampliar limites atuais de 10 MB/arquivo e 50 MB/pedido de modo controlado, após estimativa de armazenamento/segurança.
5. **Notificações:** começar por mensagens internas + e-mail após homologação do SMTP; avaliar WhatsApp em fase posterior.
6. **Prazos e pausas:** detalhar tratamento de feriados, cliente ausente, complementos e revisões sem mudar oferta contratada.
7. **Critérios editoriais:** aprovar biblioteca de templates, checklist e situações que obrigam revisão humana.
8. **Orçamento de IA:** teto por pedido, por rodada, por tentativa e por mês antes de liberar execução.
9. **Retenção e dados:** finalizar política contratual e de privacidade aplicável aos arquivos e ao uso de IA.


## 15. Decisão AD-001 — Modelo altamente automatizado (APROVADA)

**Quem decidiu:** responsável pelo projeto Imagine, na conversa de planejamento em 2026-11-01. **O que foi aprovado:** preferir autonomia alta, com os agentes atendendo, verificando materiais, desenvolvendo, testando, colocando prévia no ar, recebendo ajustes, publicando a versão aprovada e comunicando a conclusão, com equipe Imagine envolvida somente em exceções.

**Limites inegociáveis deste modo:**

- **Aprovação do cliente:** precisa ser explícita, autenticada e vinculada à versão/artefato específico. Silêncio ou passagem de prazo nunca significam aprovação.
- **A IA não é a autoridade de segurança:** o backend/orquestrador valida condições objetivas e bloqueia a publicação se qualquer condição estiver ausente ou inconclusiva.
- **Sem aprovação manual rotineira da Imagine:** o gate humano anteriormente proposto foi substituído por gates automáticos determinísticos e escalonamento por exceção.
- **Autonomia não inclui** alterar contrato, emitir nota fiscal, mexer em pagamentos/cartões, liberar cobrança, adquirir domínio, contratar serviços, criar despesas extraordinárias, utilizar credenciais administrativas genéricas ou expor dados de outro cliente.
- **Falhar em segurança significa parar**, não escolher um caminho mais permissivo.

**Observação técnica:** `server/briefing.mjs`, `server/production.mjs` e `server/team.mjs` atualmente esperam perfis humanos em várias transições. A implementação futura precisará criar contas de serviço com permissões granulares e APIs internas específicas; nunca improvisar um login `admin` para a IA.

## 16. Matriz proposta de controles para alta automação (DETALHAMENTO PARA APROVAÇÃO)

### C01 — Pagamento e elegibilidade do pedido

**Disparo permitido:** produto `site`, pedido ativo e primeiro pagamento confirmado pelo estado financeiro real conciliado. A mera chegada de webhook, uma cobrança aberta ou uma resposta de IA não são prova. Eventos repetidos não geram trabalhos duplicados. Estorno, disputa, cancelamento ou suspensão impedem novas ações conforme política comercial; caso já iniciado, abrir incidente para definição.

**Gate:** `eligible(order_id, snapshot_financeiro)` com identificador idempotente do pedido.

### C02 — Materiais, direitos e briefing

A IA verifica presença e consistência de nome empresarial, serviços, contatos, identidade visual, domínio e conteúdo básico. Arquivos entram em armazenamento privado, passam por validação de formato/tamanho e varredura de segurança antes da leitura por modelos. O sistema guarda o snapshot imutável dos materiais e o motivo da avaliação. A IA pode solicitar complemento automaticamente, mas a validação positiva requer regras objetivas além do parecer do modelo.

**Bloquear e escalar:** documentação contraditória, logomarca de terceiro sem autorização, alegações comerciais não comprovadas, pedido de funcionalidades fora da oferta, conteúdo regulado ou perigoso, arquivo suspeito, análise inconclusiva ou tentativas insistentes de manipular instruções. A exigência atual de domínio para validar briefing deve ser analisada: um domínio ainda não configurado não deve impedir a prévia; pode manter **apenas a publicação definitiva** bloqueada, mediante aprovação dessa mudança de regra.

### C03 — Atendimento e consentimento

O agente lê apenas o pedido e os materiais autorizados, identifica-se como “Assistente virtual da Imagine”, informa status verdadeiro e não inventa prazos, valores ou confirmação de envio. Não divulga dados da administração, senhas ou outros clientes. Usuário pode solicitar atendimento humano. Definir política de notificações e frequência de cobranças de resposta; não mandar mensagens repetidas durante falhas. Reclamações, ameaças, pedidos de reembolso, disputas ou dúvidas jurídicas são encaminhados.

### C04 — Produção e isolamento técnico

Cada pedido possui workspace, repositório ou isolamento equivalente, credencial de serviço com duração e escopo restritos, modelo de template aprovado e cota de recursos. Executar geração, dependências e testes em sandbox sem acesso ao banco do portal, chaves fiscais, cartões, outros projetos, rede interna ou servidor de produção. Limitar dependências a fontes permitidas, vetar execução arbitrária de comandos decorrentes de textos enviados pelo cliente e auditar chamadas de ferramenta.

**Gate:** build reproduzível e artefato versionado por `commit SHA`, `build hash` e `release id`.

### C05 — Testes automáticos bloqueantes

Antes da prévia e novamente antes da produção, exigir: build bem-sucedido; ausência de vazamento de segredos; checagens de código/dependências; páginas e links principais; mobile/responsividade; recursos e formulários; HTTPS na publicação; conteúdo dentro do briefing; verificação de scripts externos e acessibilidade/SEO definidos no checklist. Critérios quantitativos devem ser aprovados posteriormente. Teste falhou = sem avanço.

### C06 — Prévia versionada e revisão

Gerar endereço HTTPS real para a prévia, com proteção de acesso compatível com o portal, prevenção de indexação e expiração/limpeza. Registrar `order_id + release_id + commit_sha + build_hash + preview_url` no banco. O cliente pode pedir ajustes pela conversa; cada rodada cria novo snapshot e versão, sem reutilizar autorização antiga. O controle atual de **duas rodadas incluídas** permanece; adicionais seguem para orçamento/decisão humana.

### C07 — Consentimento do titular e integridade da versão

Ao clicar “Aprovar e autorizar publicação”, titular autenticado confirma **qual domínio** e **qual versão** revisou. Persistir evento imutável com usuário, horário, release, build hash, domínio/target e termos apresentados. A autorização não deve ser inferida pelo agente. Mudanças em código, artefato, conteúdo aprovado ou domínio/target invalidam a autorização e exigem novo aceite. Deploy deve usar **o mesmo artefato** aprovado, sem rebuild mutável.

### C08 — Gate determinístico de publicação

Liberação automática somente se, no mesmo instante:
1. pedido elegível, sem bloqueios e sem pausa operacional;
2. aprovação atual/autêntica do titular corresponde ao hash do artefato e ao domínio de destino;
3. testes obrigatórios verdes e artefato verificável;
4. domínio e hospedagem pertencem ao escopo contratado, com autorização/documentação para DNS;
5. DNS controlável ou comprovadamente configurado; certificado HTTPS possível e destino permitido;
6. credenciais de deploy restritas ao projeto e ambiente, com política de rollback/backup aplicável;
7. limites de custo, tentativas e concorrência não excedidos;
8. não há incidente, disputa, sinalização de segurança nem dependência externa inconclusiva.

Se qualquer verificação falhar: **NÃO publicar e não marcar como entregue**; registrar erro compreensível e criar pendência para a Imagine ou para o cliente, conforme a causa.

### C09 — Deploy, verificação e entrega

Deploy efetivo por serviço técnico confiável com permissões mínimas — nunca por comando textual arbitrário do agente diretamente na produção. Após implantação, verificar URL HTTPS real, certificado, código/artefato, conteúdo, rotas, formulário e disponibilidade em teste de fumaça. Em falha, executar rollback automático quando suportado e registrar incidente. Só então registrar `published`, gravar `live_url`, disparar os efeitos fiscais **já autorizados no sistema** e comunicar a URL. A automação de notas fiscais não pode ser ativada ou autorizada por agente.

### C10 — Limites financeiros e operacionais da IA

Controlar orçamento por pedido, versão, rodada de ajuste, tentativa e mês; limites de tokens, tempo, número de ferramentas, CPU/memória, conexões externas e concorrência. Desligamento de emergência global e por pedido, por agente ou por provedor. Valores absolutos serão definidos após piloto de custo; **não há teto numérico aprovado nesta versão**. Sem saldo orçamentário, suspender job e solicitar decisão humana, sem contratação automática.

### C11 — Resiliência, histórico e auditoria

Fila durável de jobs independente da conciliação financeira; chaves de idempotência por etapa e versão; tentativas limitadas, backoff, leasing e recuperação após reinício. Armazenar entradas/snapshots, decisões, versões, saídas, custos e logs sanitizados. Painel administrativo deve permitir **Pausar, Retomar, Reexecutar etapa, Encaminhar para humano**, visualizar causa da falha e evitar operações paralelas conflitantes. Nenhuma retomada deve ignorar versões ou consentimentos existentes.

### C12 — Segurança, privacidade e revisão por exceção

Autorização de ferramentas no backend, segregação por cliente, uploads antimalware, defesas contra prompt injection, egress controlado, política LGPD e segredos somente em cofres/variáveis do serviço; proibir conteúdo confidencial nos prompts/logs além do estritamente necessário. Acionar a equipe Imagine em suspeita de ataque, conteúdo de terceiros sem direitos, nicho regulamentado que precise de revisão, custo excedido, incapacidade de confirmar dados, testes bloqueantes, problemas de DNS/hospedagem, mudanças comerciais ou solicitação explícita de humano.

## 17. Fluxos de exceção e tempos (a definir)

| Situação | Comportamento automático | Responsável pela resolução |
| --- | --- | --- |
| Falta uma foto/texto | Solicita complemento uma vez por item pendente; aguarda nova resposta | Cliente |
| Arquivo perigoso ou suspeito | Quarentena, bloqueio de processamento e registro | Imagine |
| Conteúdo contraditório ou de alto risco | Suspende avanço, apresenta divergência e não inventa informação | Imagine/cliente |
| Cliente solicita alteração (1ª ou 2ª rodada) | Cria versão e reprocessa testes/prévia | Agentes |
| Solicita 3ª rodada incluída | Bloqueia execução gratuita e abre avaliação comercial | Imagine |
| Cliente não responde à prévia | Lembretes limitados, sem aprovação por silêncio | Cliente |
| Domínio não configurado | Prévia permanece; solicita passo de DNS, sem marcar publicação | Cliente/Imagine |
| Deploy ou teste falha | Retry limitado; rollback quando aplicável; incidente | Sistema e, se necessário, Imagine |
| Orçamento/limite excedido | Suspende job, sem novas cobranças/execuções | Imagine |
| Cliente muda escopo | Impede novo escopo não contratado; abre avaliação | Imagine |
| Aprovação antiga versus versão nova | Recusa publicação e solicita aprovação da versão correta | Cliente |

**Política de SLA, feriados, lembretes, prazo de inatividade e número de retentativas:** a aprovar, preservando os sete dias úteis anunciados após materiais validados e sem fingir que o tempo de produção corre durante bloqueios causados pelo cliente.

## 18. Testes adicionais exigidos pelo modo automático

1. IA nunca pode se autoaprovar como titular, mesmo usando ferramentas, mensagens ou replay de eventos.
2. Trocar `release_id`, `build_hash`, domínio ou destino entre aceite e deploy impede publicação.
3. Dois workers e reentrega de webhook não criam deploy duplo nem notificações indevidas.
4. Falta/expiração de credencial ou DNS inconclusivo não produz sucesso falso.
5. Prompt malicioso em PDF, imagem ou mensagem não acessa credenciais nem executa ferramentas privilegiadas.
6. Tentativa de alterar preço/Asaas/Focus, publicar outro cliente ou alterar DNS não autorizado é negada e auditada.
7. Falha no teste de fumaça aciona rollback (quando disponível) e não avança para `published`.
8. Terceira rodada de ajuste não executa automaticamente como inclusão gratuita.
9. Reembolso/contestação/estorno ou bloqueio administrativo antes do deploy exige reavaliação financeira.
10. Dashboard informa exatamente etapa, erro, custo e permissões do agente, sem segredos.
11. Recuperação após reinício respeita budget, pausa, versão e aprovação anteriores.
12. Comunicação só afirma “site no ar” se URL final e verificação de produção tiverem sucesso.

## 19. Próximas decisões ainda não tomadas

- **Infraestrutura de hospedagem:** stack, provedor, quem mantém DNS e recursos por site; **HD-001 já aprovada exclui e-mail profissional do Onepage**, mas não seleciona provedor.
- **Biblioteca de templates e critérios de QA:** primeiro conjunto de layouts, componentes, testes bloqueantes e revisão de conteúdo sensível.
- **IN-001 aprovada como diretriz:** formulários dos Onepages e da futura Landing Page alimentarão o CRM Imagine automaticamente, com provisionamento/vínculo ao Plano Básico; arquitetura técnica e condições comerciais em [INTEGRACAO-CRM-IMAGINE.md](INTEGRACAO-CRM-IMAGINE.md).
- **Limites de arquivos, custos/concorrência e políticas de retry:** valores numéricos e prioridades de escala.
- **Canais e prazos de comunicação:** e-mail/WhatsApp, lembretes e atendimento humano.
- **Políticas de domínio e entrega:** autorização DNS, domínio externo, portabilidade e acesso à hospedagem.
- **Mudança da regra de briefing sobre domínio:** decidir se é obrigatório na validação ou somente antes da publicação.
- **Piloto operacional:** número de pedidos internos, critérios para liberar produção automática a clientes reais e procedimentos de rollback.

A decisão AD-001 aprova a **direção de alta automação**, não dispensa validação de segurança, testes, homologação da conta financeira/fiscal ou deliberação sobre os pontos acima.

## 20. Decisão HD-001 — Escopo de hospedagem do Onepage (APROVADA)

**Aprovação:** 2026-11-01, responsável pela Imagine durante o planejamento.

**Incluído na assinatura Onepage:** hospedagem do site, certificado SSL/HTTPS, backup do projeto/site e suporte técnico conforme condições contratuais. **Excluído:** serviço de hospedagem de e-mail profissional, provisionamento de contas postais, webmail e operação de e-mail do domínio do cliente.

**Repercussões:**

- Sites Onepage poderão usar hospedagem estática gerenciada, desde que atendam aos requisitos do produto e dos domínios.
- O domínio permanece do cliente. MX/SPF/DKIM/DMARC e servidores de e-mail externos não podem ser alterados pelo agente de publicação sem autorização e avaliação específicas; preferencialmente, não tocá-los.
- E-mails transacionais usados pelo **portal da Imagine** para avisos de pagamento, briefing e entrega são uma função administrativa distinta e continuam sujeitos à configuração e homologação do SMTP de saída.
- A decisão não restringe, por si só, a possibilidade de o site ter um **formulário de contato** ligado a um endpoint ou serviço externo; isso exige deliberação própria sobre escopo comercial, antispam, privacidade e entrega.
- **Pendente:** provedor de hosting, arquitetura do domínio raiz e `www`, política de backups (frequência, retenção, restauração), mecanismos de deploy e reversão.

Detalhamento comparativo em [HOSPEDAGEM-SITES-ANALISE.md](HOSPEDAGEM-SITES-ANALISE.md).

## 21. Decisão IN-001 — Formulários vinculados ao CRM Imagine (APROVADA COMO DIRETRIZ)

**Aprovação:** 2026-11-01, responsável pelo projeto Imagine. O Onepage e a futura Landing Page terão formulário de captura integrado ao CRM Imagine; o contratante terá acesso à assinatura do **Plano Básico** do CRM, com os leads sendo enviados automaticamente à organização correta.

O site pode permanecer estático; o formulário publicará somente dados em um endpoint de ingestão seguro da Imagine, com enfileiramento durável e integração servidor-servidor à API do CRM. O agente desenvolvedor usará componente homologado e não terá token privado do CRM no site publicado. A integração será vinculada à identidade autorizada da empresa, sem criar organizações duplicadas.

**IN-002 aprovada:** CRM Básico gratuito vinculado ao contrato ativo do site, sem cobrança própria; upgrade para CRM pago contratado e faturado no CRM, independente da mensalidade do site no Portal Imagine. **Pendências:** limites e recursos do Básico, mapeamento para planos CRM existentes, consentimento de dados, ciclo de cancelamento/inadimplência, produto Landing Page, APIs e homologação. Não alterar o preço atual do Onepage nem habilitar nova cobrança sem aprovação. O CRM está planejado como SaaS independente; não implementar o CRM ao programar o portal antes da finalização do planejamento do CRM.

Detalhes: [INTEGRACAO-CRM-IMAGINE.md](INTEGRACAO-CRM-IMAGINE.md).

## 22. Decisão IN-002 — CRM Básico gratuito com site; upgrades cobrados no CRM (APROVADA)

**Aprovação:** 2026-11-01. A mensalidade do Onepage continua no **Portal Imagine** (R$ 79,90 desde D90 da contratação, além da implantação D0/D30/D60). Quem contrata Onepage ou futura Landing Page recebe **benefício de acesso ao CRM Básico com custo adicional R$ 0,00**, condicionado à assinatura do site. Quando contratar um plano CRM mais avançado, o próprio CRM processará a contratação e a mensalidade adicional; ambos os contratos e cobranças permanecem separados.

O vínculo é por organização CRM autorizada e contrato do site, não por e-mail isolado. O cliente pode ter várias organizações no CRM. A oferta gratuita vinculada não implica que todos os usuários do CRM terão plano gratuito público; o trial de 14 dias permanece separado. Os planos CRM Essencial/Profissional/Empresarial já aprovados em conceito não são renomeados por esta decisão.

**Pendente:** pacote de recursos/limites do Básico, correspondência com os planos pagos existentes, datas de elegibilidade/cancelamento, status financeiro de cada produto, retenção de dados e condições de Landing Page. Não implementar cobrança nova nem alterar rotinas do Asaas sem aprovação das condições aplicáveis.

Detalhes no [documento de integração CRM](INTEGRACAO-CRM-IMAGINE.md).


## 23. Decisão IN-003 — CRM Básico mais enxuto e exclusivo (APROVADA)

**Aprovação:** 2026-11-01. O benefício de CRM Básico liberado a clientes de Onepage/Landing Page deve utilizar um **perfil de recursos próprio e mais enxuto que o Essencial pago**; não é o plano Essencial oferecido gratuitamente. Preservar a cortesia do IN-002 e as assinaturas independentes entre site e upgrade do CRM.

**Ainda não aprovados:** quantidade de usuários/funis/leads, recursos efetivos, quota de armazenamento, integração com automações/agentes, regras de retenção, prazo do benefício e gradação dos planos pagos. Uma proposta inicial, sem caráter vinculante, está registrada no [CRM Imagine — Plano Básico cortesia](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/08-plano-basico-cortesia.md).

Os agentes do site usarão componentes homologados do formulário e integrarão leads pela API do CRM autorizada, respeitando as cotas efetivas aprovadas posteriormente e sem perda silenciosa de formulários aceitos.

## 24. Decisão IN-004 — Agenda e Agendamentos no CRM Básico Cortesia (APROVADA)

**Aprovação:** 2026-11-01. O CRM Básico gratuito concedido a contratantes elegíveis de Onepage/Landing Page deverá permitir **Agenda e Agendamentos exclusivamente internos/manuais** associados a leads e contatos, conforme decisão posterior **IN-007**. Página pública e autoagendamento pelo site são exclusividade dos planos pagos do CRM; quotas e detalhes operacionais continuam pendentes. **IN-005 aprovada em 2026-11-01:** até **3 automações comerciais ativas por organização**, escolhidas de biblioteca de **modelos prontos**. O catálogo exato está em revisão; editor genérico e automações avançadas dos planos pagos não foram liberados.

A produção automática de sites deve oferecer formulário seguro de captação de lead, **sem publicar botão/link/iframe de autoagendamento para cliente com somente CRM Básico Cortesia**. Um CTA público de agendamento só pode ser oferecido a uma organização com plano CRM **pago, vigente, elegível e habilitado**, após autorização e verificação efetiva no backend.

Inventário de automações já aprovadas no CRM geral e propostas para o Básico em [documentação CRM Imagine](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/09-revisao-agenda-e-automacoes-basico.md).

## 25. Decisão IN-005 — Biblioteca de automações comerciais do CRM Básico Cortesia (APROVADA)

**Aprovado em 2026-11-01:** até **3 automações comerciais ativas simultaneamente por empresa CRM**, selecionadas de modelos prontos homologados, para clientes elegíveis de Onepage/Landing Page. Instâncias pausadas/rascunhos não consomem slots ativos; a ativação deve conferir limite de forma transacional, com isolamento e histórico.

Modelos sugeridos ainda não aprovados: avisar e criar tarefa ao chegar lead; lembrar contato não atendido; sinalizar oportunidade inativa; lembrete de agendamento; criar tarefa após atendimento. Notificações operacionais da agenda podem ser distintas da quota de automações comerciais, sujeito à definição da matriz.

**Ainda a definir:** catálogo de modelos e parâmetros; integração pública de agendamento e botão no site; usuários/profissionais/serviços, quotas, integrações externas, comunicação transacional e regras de custo. **Não** liberar automaticamente editor completo, WhatsApp Business API nem agentes IA do CRM.

Ver [especificação CRM — Agenda e Automações](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/09-revisao-agenda-e-automacoes-basico.md).

## 26. Decisão IN-006 — Biblioteca segmentada e revisão de quotas após documentação

**Diretriz registrada em 2026-11-01:** documentar primeiro toda a biblioteca de automações do CRM Imagine e sua classificação por tipo/complexidade. Automações básicas devem trazer valor ao CRM Cortesia; modelos de maior poder/complexidade serão reservados a planos pagos. A atribuição individual de cada modelo ainda é proposta.

**Sequência:** inventário completo de templates e operações de sistema → descrição de gatilhos/condições/ações → dependências e custos → elegibilidade de cada plano → **somente ao final revisar e aprovar os números de slots ativos**. Os números anteriores continuam referências, sem alteração automática: Básico 3, Essencial 5, Profissional 20, Empresarial 50.

O Portal Imagine deve consultar direitos efetivos fornecidos pelo CRM; não duplicar biblioteca/quotas em lógica comercial hardcoded do site ou cobrar por automação sem autorização. Fonte: [Catálogo central de automações CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/10-biblioteca-automacoes-e-criterios-de-planos.md).


## 27. Decisão IN-007 — Agenda manual no CRM Básico; autoagendamento exclusivo dos planos pagos (APROVADA)

**Aprovação:** 2026-11-01. O benefício CRM Básico de quem contrata Onepage/Landing Page terá agenda e criação/edição/reagendamento/cancelamento de compromissos **somente por ação manual de usuário autorizado dentro do CRM**. O formulário público do Onepage continua recebendo leads e enviando ao CRM (IN-001), **sem criar automaticamente agendamento**.

**Exclusivo dos planos pagos Essencial, Profissional e Empresarial:** página pública/rota de autoagendamento e botão no Onepage/Landing Page que permitem ao visitante consultar disponibilidade e reservar sem intervenção do operador. Devem exigir plano pago ativo e recurso habilitado, com validação de tenant/permissão/cotas, consentimento, proteção contra abuso e conflito de horários. Não basta esconder o botão no frontend: o backend CRM também bloqueia criação/consulta pública sem entitlement.

No downgrade de plano CRM pago para o Básico, desativar novas reservas públicas, preservar e permitir administração manual das reservas existentes, e coordenar atualização do site sem alterar versão publicada indevidamente. Quantidade de usuários/profissionais/serviços, agendamentos manuais, canais de avisos, integrações Google/Outlook e outros recursos avançados permanecem pendentes.

[Referência do CRM — Agenda e automações](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/09-revisao-agenda-e-automacoes-basico.md).

---

**Status:** decisão AD-001 aprovada; demais controles abaixo são **especificação proposta para discussão**, não decisões comerciais/técnicas adicionais aprovadas. Nenhum código de produção foi alterado.
