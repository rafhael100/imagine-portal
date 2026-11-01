# Imagine — Produção Inteligente de Sites

> **Documento de planejamento v0.1 — RASCUNHO PARA REVISÃO**
>
> Data: 2026-11-01. Base examinada: `imagine-portal` v0.18.2 (`main`).
> Este documento **não autoriza implementação, ativação de agentes, geração de cobranças, deploy ou alteração de produção**.
> Regras já aprovadas do Onepage prevalecem até deliberação explícita em contrário.

## 1. Objetivo e limites

Automatizar a jornada após a contratação do **Site Onepage**: pagamento confirmado → briefing e materiais → complementação → validação → design/conteúdo → geração do site → testes → prévia versionada → aprovação ou duas rodadas de ajustes → publicação em domínio do cliente → verificação → comunicação de entrega → suporte.

A automação deve reutilizar o portal, a administração, a conversa por pedido, a trilha de auditoria e as transições de produção existentes. A IA não ganha autonomia para mudar preços, termos contratuais, criar cobranças, emitir NFS-e, publicar sites sem as aprovações necessárias ou acessar projetos de terceiros.

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
| 3 | informações completas | proposta de validação do briefing | **Imagine aprova o briefing**, respeitando regra atual |
| 4 | briefing aprovado | iniciar produção e calcular prazo | prazo inicial de 7 dias úteis após validação, sem simular feriados |
| 5 | produção iniciada | design/conteúdo e execução Codex em sandbox | artefatos versionados, builds e testes mínimos concluídos |
| 6 | testes aprovados | criar deploy de prévia e registrar versão | URL HTTPS real, acessível ao titular e conferida pelo sistema |
| 7 | prévia pronta | avisar cliente via portal; e-mail se canal configurado | registro de notificação, sem alegar leitura não confirmada |
| 8a | cliente pede alterações | documentar pedido e direcionar nova geração | contar rodadas conforme contrato, máximo de 2 incluídas |
| 8b | cliente aprova | gravar autorização explícita da **versão atual** | titular autenticado e confirmação versionada |
| 9 | versão aprovada | revisão técnica final + gate operacional Imagine (MVP) | domínio/SSL, backup ou rollback e destino validados |
| 10 | publicação autorizada | deploy de produção, DNS quando autorizado e testes de fumaça | URL final HTTPS funcionando, versão/ambiente conferidos |
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
| Aprovar briefing | Não | Recomendar, não aprovar no MVP | Sim |
| Criar artefatos e versões | Não | Sim, em sandbox isolada | Sim |
| Publicar prévia | Ver | Sim, depois de testes e regras | Sim/supervisionar |
| Aprovar versão de site | Sim, somente titular e versão atual | Nunca | Não em nome do cliente |
| Publicar produção | Autoriza versão | Executa só após gates | Confirma gate operacional no MVP |
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
- Cliente fornece domínio. Hospedagem, SSL, backup, suporte e licença seguem o contrato da oferta.
- Publicação somente após aprovação explícita da versão atual pelo titular.
- Integrações Asaas e Focus NFe ainda exigem homologação/configuração real e aprovação fiscal; automação IA nunca antecipa estas ativações.

## 12. MVP proposto e plano de entrega

**Fase A — fundações:** escopos de identidade de serviço, auditoria, filas/jobs persistentes, snapshots, armazenamento seguro, painel de execução/pausa, simulação integral.
**Fase B — atendimento e materiais:** ingestão autorizada, análise, checklist, solicitações de complemento, aprovação humana do briefing.
**Fase C — geração:** biblioteca base de templates, design/planejamento, integração programadora, versionamento por cliente, builds e testes.
**Fase D — prévia e revisões:** publicação de prévia HTTPS, notificações, versionamento, aprovação do titular e duas rodadas.
**Fase E — publicação e entrega:** gate Imagine, DNS/SSL/rollback, verificação final, entrega e suporte.

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

1. **Modo de autonomia do MVP:** recomendado híbrido: IA conduz comunicação/geração/testes/prévia; Imagine valida briefing e autoriza publicação operacional; cliente aprova versão.
2. **Hospedagem dos projetos dos clientes:** definir provedor, ambientes, isolamento, política de DNS, certificados, rollback, custos e backups antes do agente de deploy.
3. **Fonte de código por cliente:** recomendado repositório privado independente ou isolamento equivalente com regras de acesso restritas; não guardar sites de clientes no repositório de código do portal.
4. **Arquivos e quotas:** ampliar limites atuais de 10 MB/arquivo e 50 MB/pedido de modo controlado, após estimativa de armazenamento/segurança.
5. **Notificações:** começar por mensagens internas + e-mail após homologação do SMTP; avaliar WhatsApp em fase posterior.
6. **Prazos e pausas:** detalhar tratamento de feriados, cliente ausente, complementos e revisões sem mudar oferta contratada.
7. **Critérios editoriais:** aprovar biblioteca de templates, checklist e situações que obrigam revisão humana.
8. **Orçamento de IA:** teto por pedido, por rodada, por tentativa e por mês antes de liberar execução.
9. **Retenção e dados:** finalizar política contratual e de privacidade aplicável aos arquivos e ao uso de IA.

---

**Status:** rascunho funcional para debate. Nenhuma decisão pendente acima é considerada aprovada apenas por constar neste documento. Nenhum código de produção foi alterado.
