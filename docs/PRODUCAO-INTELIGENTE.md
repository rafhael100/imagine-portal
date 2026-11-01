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
- Pedido de alteração após publicação: encaminhar à área atual de manutenção/suporte. **Alterações de conteúdo nos blocos existentes estão incluídas na mensalidade das novas ofertas Onepage/Landing Page (IN-115)**, nos limites contratuais de volume/uso justo ainda a detalhar; **alterações de layout, novos blocos/seções e novas ou modificadas funcionalidades exigem orçamento e aceite comercial explícito**. Em todos os casos, a publicação requer aprovação documental do cliente para a versão exata (IN-109).

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

No downgrade de plano CRM pago para o Básico, desativar novas reservas públicas, preservar e permitir administração manual das reservas existentes, e coordenar atualização do site sem alterar versão publicada indevidamente. **IN-008 já aprovou 1 profissional com agenda própria por organização Básico** e **IN-009 aprovou até 10 serviços ativos para esse profissional**, distintos do número de usuários CRM. Quantidade de usuários, agendamentos manuais, configurações de horários, canais de avisos, integrações Google/Outlook e outros recursos avançados permanecem pendentes.

[Referência do CRM — Agenda e automações](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/09-revisao-agenda-e-automacoes-basico.md).

## 28. Decisão IN-008 — Um profissional com agenda manual no CRM Básico Cortesia (APROVADA)

**Aprovação:** 2026-11-01. Cada empresa/organização com benefício CRM Básico pode manter **1 profissional ativo com agenda própria**, independentemente de possuir um ou vários Onepages/Landing Pages Imagine. Essa quantidade de profissional agendável **não aprova** uma quota de usuários de login ao CRM.

A agenda é operada **manualmente por usuário autenticado e autorizado** (IN-007); visitante do site não agenda automaticamente e o site não recebe botão/iframe público de reserva no benefício Básico. **IN-009 aprova até 10 serviços ativos por organização**, sem que serviços inativos ocupem vagas; duração, pausas, horários semanais, bloqueios, conflitos, calendário e reservas mensais estão sendo detalhados e ainda **não aprovados** em [CRM — Agenda Manual Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

O limite de profissionais é validado pelo **CRM**, não apenas pelo Portal ou pela aparência do site. O upgrade para plano pago poderá habilitar mais profissionais conforme direitos do plano e autoagendamento público sem perder compromissos anteriores.

## 29. Decisão IN-009 — Até 10 serviços ativos para a agenda única do CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. A organização com benefício CRM Básico Cortesia pode cadastrar e manter **até 10 serviços ativos** para seu **único profissional agendável** (IN-008), com criação/edição dos compromissos **manual e interna** (IN-007). Serviços inativos não ocupam quota ativa, mas seus históricos/agendamentos serão preservados. A ativação de 11º serviço deve ser negada com opção de inativar outro ou contratar upgrade elegível. Não criar quota por site contratado.

**IN-011 aprovada (2026-11-01):** expediente semanal flexível e múltiplos períodos diários, pausas/intervalos, duração por serviço, bloqueios manuais de folgas/férias/feriados e agendamento manual excepcional fora do expediente com aviso e confirmação expressa de usuário autorizado, sempre sem sobreposição. **IN-012 aprovada (2026-11-01):** agendamentos **manuais** no Básico não terão teto comercial rígido por mês, sujeitos a uso justo e controles técnicos contra abuso. Isso não concede página de agendamento público. **Pendentes:** detalhes de justificativas, notificações/lembretes e integrações externas. A automação comercial de até 3 ativas de modelos prontos permanece assunto independente e terá revisão final da biblioteca.

[Detalhamento e propostas de regras do CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 30. Decisão IN-010 — Explicação de limites e upgrade contextual em todo o CRM (APROVADA)

**Aprovação:** 2026-11-01. No CRM Imagine, toda tentativa por cliente de ação fora das funcionalidades incluídas ou que supere quota contratual exige **mensagem clara e sugestão adequada de upgrade** quando uma opção comercial realmente resolver a condição. Exemplo: tentar ativar 11º serviço, 2º profissional, autoagendamento público ou automação acima da quota. Deve informar uso/limite real e oferecer forma de liberar vaga gratuitamente (inativação/pausa), quando possível. Não oferecer upsell por falta de permissão, erro técnico ou de segurança.

**Sem cobrança ou ativação automática:** o upgrade CRM deve ser autorizado expressamente e contratado no próprio CRM, sem mudar a fatura do site no Portal. Quotas e direitos efetivos ficam no CRM; não hardcode de entitlement no Portal ou nos sites, nem manipulação de backend por agente para contornar limites. Textos, formulários e métricas de UX continuam pendentes de aprovação. [Especificação funcional do CRM (IN-010)](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/12-limites-e-upgrade-contextual.md).

## 32. Decisão IN-012 — Agendamentos manuais sem teto comercial mensal no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. Cada empresa beneficiária do CRM Básico Cortesia poderá registrar **quantos agendamentos manuais legítimos necessitar**, sem teto comercial mensal numérico. Continuam válidos o profissional único (IN-008), até 10 serviços ativos (IN-009), expediente flexível (IN-011) e vedação de autoagendamento público no Básico (IN-007). Aplicar **uso justo e proteções técnicas contra abuso** com parâmetros a detalhar; não inventar quota mensal escondida nem bloquear registros humanos legítimos somente por quantidade.

O CRM não poderá apresentar upgrade como solução obrigatória para quantidade de agendamentos manuais, já que não há teto comercial; restrições técnicas ou de segurança devem ser comunicadas com motivo real e suporte, conforme IN-010. **Pendentes** as regras específicas de notificações e lembretes operacionais versus automações comerciais. Documento principal: [Agenda Manual CRM Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 33. Decisão IN-013 — Apenas notificações e lembretes internos da agenda no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. A agenda manual do CRM Básico Cortesia permite **somente notificações e lembretes operacionais internos na interface autenticada do CRM**, sem consumir quota de modelos de automações comerciais ativas. E-mail **automático ao cliente agendado** e WhatsApp Business API oficial para confirmações/lembretes são exclusivos de planos CRM pagos ou adicionais elegíveis, com contrato, canal, custos e consentimentos próprios. Não confundir avisos operacionais do Portal Imagine com os avisos da agenda CRM. **IN-014 (2026-11-01) aprovada:** agenda Básico com dois lembretes internos por padrão, **24h e 1h antes**, que podem ser ajustados ou desativados individualmente pelo profissional, sem ocupar automações comerciais. **IN-015 aprovada:** a central é persistente e guarda lembretes internos mesmo quando a sessão estiver fechada, com estados lido/não lido, para consulta quando o usuário retornar. Como marcar como lido, soneca, retenção e UX ainda serão detalhados.

[Detalhes e próximos parâmetros na agenda CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 34. Decisão IN-015 — Central de avisos da agenda do CRM Básico persistente (APROVADA)

**Aprovação:** 2026-11-01. A central de notificações e lembretes internos do CRM Básico Cortesia deverá preservar avisos no servidor, mantendo estado **lido/não lido** por destinatário autorizado, inclusive lembretes cujo prazo chegou durante a ausência do usuário, disponíveis no retorno ao CRM. Aviso antigo deve mostrar data e situação real, sem ser apresentado como compromisso futuro. Ajustes/reagendamentos invalidam disparos futuros obsoletos, preservando o histórico pertinente. Não há notificações push, e-mails automáticos ao cliente nem WhatsApp oficial incluídos no Básico. **IN-016 aprovada:** abrir detalhes de uma notificação marca-a automaticamente como lida, com opção de marcar novamente como não lida; visualizar lista não modifica status. **Pendentes:** filtros, arquivamento/retenção, ações em lote e demais detalhes de interface.

[Especificação da agenda no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 35. Decisão IN-016 — Marcação automática como lida ao abrir detalhes de aviso (APROVADA)

**Aprovação:** 2026-11-01. Na central interna persistente de notificações do CRM Básico Cortesia, abrir o detalhamento de uma notificação marca apenas ela como **lida**. O usuário autenticado pode marcá-la novamente como **não lida** em ação explícita. Apenas abrir a lista de avisos ou visualizar contagem/resumos não altera leitura. Os estados persistem no CRM por destinatário autorizado, sem excluir aviso nem disparar novamente o lembrete, sem consumir automações comerciais. **IN-017 (2026-11-01) aprovada:** filtros de avisos internos por estado (Todas/Não lidas/Lidas), tipo de evento e período, com ordenação padrão da mais recente para a mais antiga. Retenção, arquivamento, pesquisa textual e ações em lote ainda serão discutidos.

[Detalhes funcionais — CRM Agenda Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 36. Decisão IN-017 — Filtros e ordenação na central de notificações do CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. A central interna persistente de avisos e lembretes do CRM Básico oferecerá filtros combináveis por estado **Todas, Não lidas e Lidas**, por tipo de evento (**agendamento criado, cancelamento, reagendamento, lembrete e conflito**) e por período, com ordenação padrão **mais recentes primeiro** baseada na data real da notificação. Filtrar não altera leitura (IN-016); os resultados são restritos ao tenant e ao usuário autorizado. Nenhum agendamento ou notificação será apagado ao filtrar.

**IN-018 (2026-11-01) APROVADA POSTERIORMENTE:** histórico das notificações internas do CRM Básico retido por **90 dias**, separado do histórico de agendamentos e da auditoria, sem reiniciar a contagem ao ler ou filtrar. **Ainda pendentes:** tratamento de não lidas ao fim dos 90 dias, arquivamento/exclusão técnica, pesquisa textual, marcação em lote ou mensagens externas. [CRM Imagine — documento principal de agenda](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 37. Decisão IN-018 — Retenção de 90 dias na central de notificações internas do CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O CRM Básico Cortesia manterá o histórico de suas **notificações e lembretes internos por 90 dias**, contando do momento em que o aviso foi gerado/registrado, inclusive se o usuário não o leu. Marcar como lido/não lido (IN-016), filtrar (IN-017) ou encerrar sessão não reinicia o prazo.

**Separação obrigatória:** essa janela de consulta da central **não reduz nem exclui** agendamentos/atendimentos, contatos/leads, alterações da agenda ou trilhas de auditoria. Também não altera a retenção de avisos do **Portal Imagine**, que são processos próprios. O CRM respeita obrigações legais e permissões por organização.

**IN-019 (2026-11-01) aprovada:** o usuário CRM pode arquivar e restaurar notificações lidas ou não lidas enquanto ainda estiverem dentro dos 90 dias, preservando leitura. Todas deixam a central aos 90 dias, independentemente do estado, sem eliminar agendamentos ou auditoria. **Pendentes:** descarte físico/backup e detalhes de UX. Referência: [Agenda Manual CRM Básico](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 38. Decisão IN-019 — Arquivamento e expiração de notificações do CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. A central de avisos internos da agenda do CRM Básico Cortesia permite ao usuário autorizado **arquivar manualmente avisos lidos e não lidos** e **restaurá-los antes de 90 dias** contados da criação. Arquivar retira o aviso da caixa principal sem modificar leitura ou reiniciar o prazo; a consulta às arquivadas mantém permissões por usuário/empresa. **Todas** as notificações, lidas, não lidas ou arquivadas, deixam de estar disponíveis na central aos **90 dias** (IN-018). A expiração não exclui nem modifica agendamentos, leads, contatos ou trilhas de auditoria, que têm retenções próprias.

**Não decidido:** limpeza física e tratamento de backup, ações em lote, detalhes de filtros/aba arquivadas e pesquisa textual. Essas decisões não alteram a retenção da comunicação operacional própria do Portal Imagine. [CRM — especificação da agenda](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 39. Decisão IN-020 — 1 usuário autenticado por empresa no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O CRM Básico Cortesia inclui **até 1 usuário autenticado por organização/empresa**, independente da quota de **1 profissional com agenda própria** (IN-008). O login pode pertencer ao profissional ou a outro responsável, conforme perfis e permissões pendentes. O número de sites/landing pages contratados não multiplica a quota de usuários do CRM. O CRM aplica controle de quota por empresa no backend; ao tentar segundo usuário, informar **1/1**, mostrar opções comerciais elegíveis sem contratação/cobrança automática (IN-010). Sem compartilhar credenciais entre pessoas.

**IN-021 aprovada em 2026-11-01:** o único login do Básico terá perfil **Administrador da Empresa**, **MFA obrigatório**, autorizado a administrar somente os recursos incluídos no plano e sem poderes de superadministrador da plataforma. **Pendentes:** ações específicas de cada módulo, substituição de acesso e fluxo de convites/revogação. Esta decisão não muda direitos de contas do próprio Portal Imagine, nem os planos pagos CRM. [Documento de agenda e usuários do CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 40. Decisão IN-021 — Administrador da Empresa como único perfil do Básico (APROVADA)

**Aprovação:** 2026-11-01. O único usuário autenticado por empresa no CRM Básico Cortesia (IN-020) terá perfil **Administrador da Empresa**, com administração dos **módulos e operações efetivamente incluídos no benefício**, respeitando **MFA obrigatório para administradores**, separação de tenants, controles de autorização, auditoria e direitos contratados. Não é superadministrador do SaaS, não acessa outras empresas e não desbloqueia recursos pagos ou quotas além do Básico. O Portal Imagine não cria login administrativo adicional nem substitui autenticação/MFA do CRM.

**Pendente:** matriz de ações por módulo (empresa/segurança, leads/contatos, funis, agenda, automações comerciais, relatórios e cobrança), titularidade, convite, troca/recuperação da conta. Nada foi programado. Documento de referência: [CRM Imagine — agenda e usuários](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/11-agenda-manual-basico-cortesia.md).

## 41. Decisão IN-022 — Permissões Administração da Empresa e Segurança para o CRM Básico (APROVADA)

**Aprovação em 2026-11-01:** o único Administrador da Empresa do CRM Básico Cortesia poderá consultar/atualizar dados cadastrais comuns da própria empresa; alterar CNPJ/identificador fiscal apenas mediante **validação específica**; alterar senha/e-mail e configurações de MFA somente após verificação de identidade (**MFA obrigatório permanece**); consultar sessões e encerrar as de outros dispositivos; consultar plano e direitos contratados; conhecer opções de upgrade e contratar plano pago CRM apenas com aceite financeiro expresso. Não poderá habilitar segundo usuário no Básico, acessar outras organizações nem administrar a plataforma Imagine como superadministrador. Segurança, auditoria, permissões, proteção contra tomada de conta e segregação entre Portal e CRM são mandatórias. O fluxo exato de validação fiscal e de recuperação de acesso segue pendente.

**IN-023 aprovada:** as permissões básicas de Leads e Contatos incluem visualizar, cadastrar/editar manualmente, pesquisar, registrar observações, alertar possíveis duplicidades sem mesclagem automática e arquivar preservando histórico. Limites, importação, exportação, exclusão definitiva e retenção seguem pendentes. O formulário Onepage já gera leads no CRM, mas não se deve presumir inclusão de importação em massa, API pública, lead scoring avançado ou outras funções premium no Básico. [Especificação por módulo](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 42. Decisão IN-023 — Operações manuais de Leads e Contatos no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O único Administrador da Empresa (IN-020/IN-021) poderá, na organização CRM Básico Cortesia, **visualizar, cadastrar/editar manualmente e pesquisar leads e contatos**, registrar **observações e histórico manual de atendimento**, receber alerta de **possíveis duplicidades sem mesclagem automática** e **arquivar preservando histórico, dados e vínculos**. Os formulários dos sites Imagine continuam criando leads na organização correta (IN-001), não reservas; os envios confirmados devem ser persistentes e não desaparecer silenciosamente. Autorização multiempresa, privacidade e registro de alterações permanecem obrigatórios.

**Decisões posteriores consolidadas:** IN-024 definiu **quantidade de leads/contatos sem teto comercial rígido** e uso justo; IN-025 incluiu **importação CSV/XLSX com mapeamento, prévia, validação e alerta de duplicidades, sem mesclagem automática**; IN-026 incluiu **exportação CSV/XLSX completa ou filtrada**; IN-027 incluiu **exclusão definitiva controlada de elegíveis**; IN-028 incluiu **mesclagem manual controlada com revisão campo a campo, escolha do principal, confirmação e preservação de vínculos e auditoria**. **Continuam fora da aprovação:** API pública/bots de importação, mesclagem automática, scoring premium e campos personalizados pagos. **Pendentes:** parâmetros técnicos, armazenamento, retenção, reversão e fluxos LGPD. Direitos legais não dependem de upgrade. [Matriz de permissões — IN-023](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 43. Decisão IN-024 — Leads e contatos sem teto comercial rígido no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O CRM Básico Cortesia não possui **limite comercial rígido de quantidade de leads/contatos**, sejam os **captados nos formulários Onepage/Landing Page Imagine**, sejam os **cadastrados manualmente** pelo Administrador da Empresa. Nenhum limite mensal/total oculto, nem upgrade devido somente a número de contatos legítimos. **Uso justo, proteção antispam, rate limiting técnico proporcional, validação da origem e antiabuso** são exigíveis, com parâmetros pendentes; não tratar controle de segurança como restrição comercial.

**Integridade:** leads confirmados como recebidos exigem persistência durável e não podem ser silenciosamente perdidos em falha de CRM/filas/quotas não aprovadas; identificar claramente eventos não aceitos e tratamento de falhas. **Separação de escopo:** a IN-024 não concede importação em massa, API pública, bots nem armazenamento irrestrito de anexos. **Armazenamento e retenção continuam a definir separadamente**, como também exportação e exclusão definitiva. A regra não muda preços, contratos do site ou direitos de planos pagos. [Matriz IN-024 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 44. Decisão IN-025 — Importação básica CSV/XLSX de leads e contatos no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O Administrador da Empresa do CRM Básico Cortesia tem direito de **importar leads e contatos via CSV e XLSX**, incluindo **mapeamento de campos, prévia, validação e alertas de possíveis cadastros duplicados, sem mesclagem automática**. Os registros devem respeitar organização/tenant, autenticação, autorização, auditoria e segurança. Não presumir atualização, mesclagem ou exclusão automática de cadastros existentes nem confirmação de linhas inválidas. O cliente só efetiva após prévia e confirmação dentro do CRM; não habilita upload automático por agente Portal, API pública ou robôs externos.

**IN-024 preservada:** sem teto comercial rígido para quantidade total de leads/contatos, respeitando uso justo. **Pendentes:** tamanho/quantidade de linhas por arquivo, tratamentos de duplicidade/erros, resultado da importação, armazenamento dos uploads, regras de exportação comercial e exclusão definitiva. A importação por arquivo é diferente da captura pública de leads dos formulários Imagine e não altera o contrato do site. [Matriz IN-025](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 45. Decisão IN-026 — Exportação CSV/XLSX de leads e contatos do CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. No CRM Básico Cortesia, o único **Administrador da Empresa** pode exportar **todos os leads e contatos autorizados da sua empresa ou somente os registros filtrados**, em **CSV e XLSX**. A operação exige verificação de permissões e isolamento por organização, downloads/arquivos protegidos, auditoria e controles técnicos proporcionais de processamento. O Portal Imagine não ganha acesso a esses dados simplesmente por administrar a assinatura do site; o cliente acessa a exportação dentro do próprio CRM.

**Não concedido pela IN-026:** relatórios gerenciais avançados, API pública, exportação de outros módulos, exportações programáticas externas ou uso de dados por pessoal não autorizado. **Pendente:** colunas e campos sensíveis exportáveis, critérios de filtros aplicados, tamanho/lotes, link seguro e prazo de expiração, armazenamento temporário, políticas de retenção e detalhes do processamento assíncrono. Direitos LGPD de acesso e portabilidade devem ser respeitados sem exigir upgrade indevido. **IN-027 aprovou posteriormente exclusão definitiva controlada com salvaguardas; mesclagem manual de duplicados foi aprovada posteriormente em IN-028**, separada do arquivamento comum (IN-023). [Matriz IN-026](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 46. Decisão IN-027 — Exclusão definitiva controlada de leads e contatos no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O Administrador da Empresa pode **excluir definitivamente leads/contatos elegíveis, após confirmação explícita**, no CRM Básico Cortesia. Se o registro possuir **agendamentos, oportunidades, históricos ou outras dependências, ou obrigações de preservação legal**, a ação exige **fluxo protegido**, sem exclusão em cascata ou perda indevida de dados/vínculos. Aplicar LGPD, minimização, segurança, auditoria e isolamento multiempresa. O arquivamento da IN-023 continua operação distinta, que conserva o histórico; o fluxo de exclusão não apaga trilhas de auditoria ou registros que legalmente precisem ser preservados.

**Pendentes:** critérios de elegibilidade, tratamento de registros relacionados (bloqueio/anonimização/desvinculação), retenção, backups e reversibilidade, fluxo técnico e experiência do administrador. **IN-028 aprovou posteriormente a mesclagem manual completa e controlada no Básico**, com escolha do principal, revisão campo a campo e confirmação; a automática permanece vedada. O Portal Imagine não assume autorização para apagar registros privados do CRM em nome dos clientes apenas por integrar o site; cancelamento do Onepage não equivale a solicitação de eliminação de leads/contatos. **Próxima decisão após IN-028:** armazenamento e retenção de dados estruturados, anexos e arquivos temporários. [Matriz IN-027 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 47. Decisão IN-028 — Mesclagem manual controlada de leads e contatos duplicados no CRM Básico (APROVADA)

**Aprovação:** 2026-11-01. O único Administrador da Empresa do **CRM Básico Cortesia** poderá efetuar **mesclagem manual completa de leads e contatos duplicados**, escolhendo registro principal, comparando os cadastros, **revisando e resolvendo conflitos campo a campo**, apresentando **prévia** e exigindo **confirmação explícita** antes de executar. É obrigatório preservar vínculos de agendamentos e oportunidades, origens do site/formulários, histórico de atendimento, observações, trilha de auditoria e integridade multiempresa, obedecendo à proteção de dados. A operação **nunca será automática**, inclusive por detecção de duplicados, importação de arquivos ou preenchimento de formulário. O Portal Imagine **não adquire autorização para mesclar registros CRM** ao conectar um site. Não confundir mesclagem com arquivamento IN-023 ou exclusão definitiva controlada IN-027.

**PENDENTES:** tratamento da referência histórica do cadastro secundário, consentimentos divergentes, eventual desfazer/reversão, validação de conflitos, concorrência e transação, retenção, armazenamento e UX técnico. **Próximo ponto:** política de armazenamento dos registros estruturados, anexos e arquivos temporários no Básico, depois retenção e recuperação. [Matriz IN-028 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 48. Decisão IN-029 — Dados estruturados do CRM Básico fora da franquia comercial de arquivos (APROVADA)

**Aprovação:** 2026-11-01. Os **dados estruturados de leads, contatos e históricos** do CRM Básico Cortesia **não consomem franquia comercial fixa de armazenamento de anexos/arquivos**, em harmonia com a IN-024 (número de leads e contatos sem teto comercial rígido, uso justo e segurança). Não bloquear o recebimento de leads legítimos pelo formulário Onepage porque um eventual espaço de anexos tenha sido esgotado; leads confirmados devem ser persistidos e não podem desaparecer silenciosamente. Isso **não promete capacidade computacional infinita** nem revoga controles de proteção contra abuso.

**Atualizações posteriores:** IN-030 permitiu no Básico o mesmo tipo de anexos dos pagos; **IN-031 aprovou franquias por empresa de Básico 1 GB, Essencial 5 GB, Profissional 20 GB e Empresarial 50 GB, exclusivamente para arquivos/anexos**, deixando **dados estruturados fora da franquia nos quatro planos**. As antigas franquias pagas de 1/5/10 GB foram substituídas. O limite geral de 5 MB/arquivo permanece. **Pendentes:** pacotes extras, arquivos temporários, expiração, retenção e recuperação. Contratos do Portal seguem independentes. [Matriz IN-029 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 49. Decisão IN-030 — Mesmo catálogo de tipos de anexos nos quatro planos CRM (APROVADA)

**Aprovação:** 2026-11-01. O **CRM Básico Cortesia** permite anexar a leads e contatos os **mesmos tipos de imagens, PDFs e documentos comuns** dos planos pagos Essencial, Profissional e Empresarial, com **catálogo comum e seguro de formatos/MIME, checagem de arquivo, armazenamento privado, controle de acesso por empresa, histórico e auditoria**. A diferenciação comercial será feita por **franquia total de armazenamento**, e não por proibir tipos de arquivos no Básico. **Permanece o limite geral de upload previamente aprovado de 5 MB/arquivo em todos os planos**, até eventual revisão separada; esta decisão não o altera.

**Atualização IN-031:** franquias de arquivos/anexos **Básico 1 GB; Essencial 5 GB; Profissional 20 GB; Empresarial 50 GB** aprovadas e anteriores substituídas. **Dados estruturados não consomem a franquia em nenhum dos quatro planos.** Anexos não são armazenamento infinito; downloads temporários, retenção e backups têm regras próprias pendentes. O Portal Imagine não obtém autorização implícita para ler documentos anexados ao CRM. [Matriz IN-030 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 50. Decisão IN-031 — Franquias de arquivos/anexos por plano e exclusão dos dados estruturados (APROVADA)

**Aprovação em 2026-11-01:** após revisar e selecionar o cenário Amplo, o responsável confirmou que as franquias comerciais de armazenamento devem contabilizar **somente arquivos/anexos em todos os planos**. Capacidades **por empresa**: **Básico Cortesia 1 GB; Essencial 5 GB; Profissional 20 GB; Empresarial 50 GB**. **Dados estruturados** (leads, contatos, textos, históricos, agendamentos, oportunidades) **ficam fora da franquia nos quatro planos**, em continuidade/ampliação da IN-029. Os limites pagos anteriores 1/5/10 GB foram **substituídos**. A franquia é compartilhada e mede arquivos/versões realmente guardados após otimização; não implica armazenamento físico infinito.

**Preservadas:** mesma lista segura de tipos de anexos (IN-030); limite técnico global **5 MB por arquivo**, anteriormente aprovado e não alterado; isolamento multiempresa, auditoria e segurança; ausência de quota comercial rígida de leads/contatos do Básico (IN-024). Arquivos já aceitos devem permanecer acessíveis conforme autorização; franquia de anexos esgotada não suspende captação legítima de leads sem anexos. **IN-032 aprovada posteriormente:** mostrar usado/disponível, avisos internos em 80%/95% e bloqueio somente de novos uploads de anexos a 100%, preservando arquivos existentes e dados estruturados. **PENDENTES:** detalhes técnicos e de UX dos avisos, adicionais/preços, backups, importação/exportação temporária, retenção, recuperação e condições de upgrade/downgrade. O Portal Imagine não passa a acessar arquivos internos do CRM nem modifica os contratos do Onepage. [Matriz IN-031 no CRM](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

## 51. Decisão IN-032 — Utilização e alertas da franquia de arquivos/anexos (APROVADA)

**Aprovação:** 2026-11-01. Para **Básico Cortesia, Essencial, Profissional e Empresarial**, disponibilizar ao administrador da empresa um indicador de **armazenamento de anexos usado e disponível**, com **avisos internos no CRM em 80% e 95% da franquia**. Ao alcançar **100% da capacidade**, **bloquear apenas novos uploads de anexos**, conservando arquivos já recebidos e acessíveis e **sem bloquear o recebimento de leads dos formulários Imagine nem cadastro e manutenção de contatos, agendamentos, oportunidades ou outros dados estruturados**. Arquivos que ultrapassariam o saldo disponível não podem ser aceitos e depois descartados. Quotas IN-031 são **1/5/20/50 GB** para os quatro planos e contam só arquivos/anexos, não registros estruturados.

**Restrições e pendências:** avisos desta decisão são **internos no CRM**, não e-mail, WhatsApp ou push ao cliente; não presumir contagem como automações comerciais nem retenção de 90 dias da central da agenda. Isolamento por empresa, segurança, auditoria e ausência de upgrade/cobrança automática (IN-010). **Pendentes:** apresentação detalhada dos avisos, reavisos, oferta/contratação de armazenamento adicional sem alterar plano, preços, arquivos temporários e retenção/recuperação. O Portal Imagine não ganha acesso aos anexos nem altera contrato do Onepage. [Matriz IN-032](https://github.com/rafhael100/imagine-crm/blob/docs/integracao-site-crm-basico/docs/13-matriz-permissoes-basico-cortesia.md).

---

**Status:** decisão AD-001 aprovada; demais controles abaixo são **especificação proposta para discussão**, não decisões comerciais/técnicas adicionais aprovadas. Nenhum código de produção foi alterado.


### IN-115 — Complemento aprovado: alterações de conteúdo incluídas; mudanças estruturais sob orçamento (01/11/2026)

**Decisão comercial aprovada para novas ofertas Onepage e Landing Page:** a assinatura mensal **inclui solicitações de alteração e atualização do conteúdo do site já existente**, sem cobrança avulsa por simples alteração de conteúdo dentro da estrutura contratada. Exemplos indicativos: atualizar informações de contato e horário, substituir ou corrigir textos, trocar imagens e atualizar informações de produtos, serviços ou ofertas **nos espaços/blocos já existentes**, quando tecnicamente compatíveis com a estrutura original e respeitadas veracidade, direitos de uso e segurança. **Não foi aprovada uma quota numérica ou promessa de volume ilimitado de alterações:** frequência, volume por solicitação, regras de uso justo e prazo de atendimento ainda exigem detalhamento antes do lançamento.

**Alterações que exigem orçamento e aprovação comercial específicos:** redesenhar ou mudar o **layout**, acrescentar **novos blocos, seções, páginas ou estruturas**, acrescentar/modificar **funcionalidades**, novos formulários/integrações ou recursos não contratados, e mudanças que demandem reorganização estrutural relevante. O cliente deve receber escopo, valor e condições antes de contratar; não criar cobrança, upgrade ou trabalho adicional pago por interpretação automática da IA ou por simples solicitação. Quando uma solicitação misturar alteração de conteúdo incluída com mudança estrutural, separar itens abrangidos e itens sujeitos a orçamento, sem bloquear indevidamente os ajustes incluídos.

**Relação com suporte e revisões de implantação:** hospedagem do site, SSL, backups e suporte técnico permanecem incluídos conforme condições contratuais, mas suporte não equivale a redesign ou desenvolvimento de novos recursos. As **duas rodadas de revisão durante a criação inicial** do Onepage e da Landing Page permanecem distintas das alterações de conteúdo posteriores à entrega; não debitar ou misturar cotas entre etapas. Aplicar as novas condições ao planejamento das ofertas futuras; contratos legados mantêm os termos efetivamente aceitos, sem ampliação ou redução unilateral.

**Fluxo e autorização de publicação:** todas as solicitações, incluídas ou orçadas, entram na Central de Evolução/Manutenção (IN-107), são triadas por agentes com controles de contrato, segurança e autonomia (IN-098/110), preparadas em versão/prévia privada e **somente podem ser publicadas após aprovação prévia, expressa e documentada do cliente/representante autorizado para a versão exata** (IN-109). O fato de uma alteração ser gratuita ou tecnicamente simples **nunca autoriza publicação automática sem aceite**. Preservar histórico, direitos autorais, LGPD, backups, isolamento de tenants e acesso restrito ao CRM.

**Pendências remanescentes:** limites proporcionais e política de uso justo; volume/tamanho por pedido; prazos/janelas e prioridades de atendimento; critérios técnicos para classificar troca de imagem/texto versus mudança estrutural; preços/regras de orçamento de blocos, layouts e funcionalidades; exceções e conflitos; homologação/contratos. **Nenhum código, preço adicional ou canal de atendimento foi ativado por esta decisão.**


### IN-115 — Complemento: uso justo sem teto mensal fixo e contrato eletrônico completo (01/11/2026)

**Aprovado para as novas ofertas:** atualizações de conteúdo dentro dos blocos já existentes permanecem incluídas na assinatura, **sem limite mensal fixo no número de solicitações**, sujeitas à **política objetiva e transparente de uso justo a detalhar antes do lançamento**. A ausência de teto não é promessa de execução instantânea, entrega irrestrita ou redesenho do site; não se admite limite oculto nem cobrança automática por pedidos legítimos abrangidos. Alterações de layout, novos blocos/seções ou novas funcionalidades continuam **sob orçamento e aceite comercial explícito**; a alteração incluída jamais dispensa aprovação prévia e documentada do cliente para a versão exata antes de publicar (IN-109).

**Cláusulas contratuais futuras obrigatórias:** elaborar e revisar juridicamente contratos completos de **Onepage e Landing Page**, reunindo preços, recorrência, implantação, revisões, prazos, serviços, limites e exclusões, uso justo e atendimento, orçamentos extras, domínio/e-mail, hospedagem/backup, IA/imagens/direitos, CRM Básico, LGPD, propriedade/licença, cancelamento/inadimplência e responsabilidades. O cliente deverá **ler/acessar e aceitar expressamente a versão integral do contrato aplicável no ato da contratação**, com registro verificável de identidade, data/hora, produto e versão contratual. Esse aceite é separado do aceite de orçamento extra e do aceite para publicar cada versão. Políticas concretas de uso justo, texto jurídico e fluxo técnico ainda aguardam aprovação.

[Planejamento das cláusulas e dos aceites eletrônicos](https://github.com/rafhael100/imagine-portal/blob/docs/producao-inteligente-v01/docs/CONTRATOS-SITES-PLANEJAMENTO.md). Somente documentação; nenhuma programação, cobrança ou publicação.


### IN-115 — Complemento aprovado: Portal Imagine como canal oficial exclusivo para solicitações e aprovações de alterações (01/11/2026)

**Decisão:** para os serviços **Onepage e Landing Page**, a solicitação, o recebimento de materiais, a análise e classificação de escopo, o orçamento de mudanças estruturais, o aceite comercial de eventuais extras, o acompanhamento de status, os pedidos de ajustes, a disponibilização de prévias e a **aprovação documental de cada versão antes de publicar** devem ocorrer **oficialmente dentro do Portal Imagine**, em sessão autenticada e com permissões por empresa/site/pedido. Reutilizar os mecanismos já previstos para suporte, manutenção e a Central de Evolução (IN-097/099/107/110/111), em vez de criar centrais, protocolos e registros conflitantes ou depender de conversas externas não auditáveis.

**Jornada documental pretendida, sem implementação neste momento:** cliente autenticado escolhe site e tipo de solicitação, fornece descrição e materiais pertinentes; sistema registra protocolo/identificador, autor, momento, versão e anexos, classifica se é conteúdo simples já incluído (prazo-base de **até 3 dias úteis após materiais completos**), conteúdo mais volumoso ainda incluído (prazo informado) ou mudança estrutural/funcionalidade sob orçamento. A Imagine/agentes tratam somente com direitos e dados autorizados, fazem testes, apresentam prévia privada, solicitam aceite de orçamento quando cabível e registram o **aceite prévio, expresso, documentado e vinculado à versão exata** antes de liberar qualquer publicação (IN-109). Status e histórico permanecem consultáveis no Portal e não podem ser simulados.

**Notificações por e-mail:** podem ser **avisos acessórios** de que houve atualização, resposta, orçamento ou prévia disponível, **somente quando canal, destinatário, fundamento legal, segurança e preferências estiverem autorizados/configurados**. O conteúdo sensível deve permanecer protegido no Portal; mensagens devem priorizar link autenticado seguro, sem links que aprovem automaticamente. **Resposta por e-mail, WhatsApp, telefone ou outro canal externo não substitui solicitação/aceite oficial dentro do Portal.** Nenhum disparo real, canal adicional ou serviço de envio é contratado/ativado por esta decisão. Não confundir com os canais autorizados para marketing ou atendimento de outros produtos.

**Controle e contratos:** clientes terão no Portal registro de estados, responsável quando aplicável, materiais recebidos, previsão real de prazo, orçamento explícito, status, versões, aprovações e publicação comprovada. A jornada oficial, limitação dos canais e as regras de notificação deverão aparecer nas futuras cláusulas Onepage e Landing Page aceitas no checkout, com linguagem transparente, acessibilidade, proteção de dados, direitos de titularidade e canais de contingência para indisponibilidade do Portal a serem definidos antes do lançamento. Preservar contratos antigos e a distinção entre aceites de contratação, de orçamento e de publicação de versão. Sem autorização formal, nenhuma alteração ou custo será executado em nome do cliente.

**Pendências para refinamento:** UX e formulários, papéis/representantes, tipos e tamanhos de anexos, controle de autorização, eventos e estados, persistência/auditoria, retenção/LGPD, políticas de notificações, regras de contingência quando o Portal estiver indisponível, janelas e prioridade do atendimento, prevenção de abusos e comunicação de solicitações incompletas. O status de planejamento não equivale à implementação.

**Status:** aprovado **somente o modelo funcional e comercial do canal oficial Portal Imagine**. Nenhuma programação, mudança de produção, disparo de e-mail, contratação, publicação, aprovação real de site ou ativação de serviços.
