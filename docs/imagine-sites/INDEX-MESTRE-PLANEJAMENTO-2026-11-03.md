# IMAGINE SITES — Índice mestre de planejamento e prontidão documental

**Situação:** ETAPA 1 — SANEAMENTO/SINCRONIZAÇÃO DOCUMENTAL EM ANDAMENTO.  
**Natureza:** catálogo de rastreabilidade, **não** versão final homologada nem autorização de programação.  
**Data de referência das aprovações:** 03/11/2026; edição documental posterior autorizada pelo responsável.  
**Escopo:** sites Onepage e Landing Page, administração pelo Portal Imagine, integração CRM Imagine, agentes de produção/suporte, publicações, captação, faturamento e infraestruturas correspondentes.

**CONSOLIDAÇÃO P0-DOC03 / RV01 (04/11/2026):** [relatório de revisão e matriz de autoridades](P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md). **AUT-P01 a AUT-P10 alternativas B aprovadas normativamente**, com 14 achados de revisão e oito pacotes RV-CT propostos para especificação. **P0-DOC03 e RF-PEND-01 permanecem ABERTOS, RF-PEND-04 não homologada, AGI-11-RB01 DESABILITADO.** Referências mais antigas a "AUT-Pxx pendente" nas linhas cronológicas deste índice são históricas; a ratificação posterior prevalece. RV01 não constitui nova aprovação técnica ou início de desenvolvimento.

## 1. Autoridade e regra de utilização deste índice

1. O registro de uma decisão neste índice **não substitui o conteúdo integral** de sua aprovação e não comprova que o requisito foi verificado em arquivo canônico.
2. Quando existir versão posterior **expressamente ratificada** do mesmo assunto, a decisão posterior prevalece nesse assunto; preservar íntegra a decisão histórica e o momento de sua substituição.
3. Não confundir **aprovado funcionalmente**, **documentado no GitHub**, **conferido integralmente**, **implementado**, **testado** e **homologado**.
4. Sem indicação expressa de aprovação, registrar **proposta**, **pendência** ou **não localizado**.
5. O documento [RF-PEND-01 — Matriz de conferência parcial](RF-PEND-01-matriz-conferencia-parcial-2026-11-03.md) mantém a relação de fontes e diferenças. **RF-PEND-01 permanece aberta**.
6. A série AGI-RF01-R07 foi registrada somente no nível dos **48 objetos/títulos recuperados**; sua redação integral precisa ser conferida. Não atestar que a documentação canônica completa existe.
7. Algumas fontes estão no repositório do CRM ou em branches históricas. Não copiar documentos cegamente nem presumir equivalência de versões.
8. O repositório Portal contém código e documentação histórica de um portal existente; seus arquivos legados **não são automaticamente** a arquitetura final aprovada para novos sites Imagine.

## 2. Repositórios identificados

| Repositório | Uso nesta consolidação | Status |
|---|---|---|
| [rafhael100/imagine-portal](https://github.com/rafhael100/imagine-portal) | Índice mestre, documentação de Portal/Sites, AGI-RF01 e matriz transversal | Repositório acessado; cobertura parcial |
| [rafhael100/imagine-crm](https://github.com/rafhael100/imagine-crm) | Documentação e contratos de CRM, captação IN-124–149, PORTAL-COM e integrações | Repositório acessado; cobertura parcial |
| `imagine-crm:docs/integracao-site-crm-basico` | Histórico de decisões, incluindo `docs/decisoes.md` | Localizado; reconciliação integral pendente |
| `imagine-crm:docs/especificacao-inicial-2026-10-31` | Outra branch histórica mencionada em revisão de prontidão do CRM | Presença descrita em fonte; conferência atual incompleta |

Não se identificou, nas árvores principais consultadas, um repositório específico separado para Imagine Sites. Isto **não** prova ausência de outras fontes.

## 3. Documentos prioritários recentemente sincronizados

| Documento | Assunto | Situação |
|---|---|---|
| [AGI-RF01-R07 — 48 objetos documentais](AGI-RF01-R07-requisitos-aprovados-2026-11-03.md) | Requisitos documentais aprovados e rastreio das decisões R07 | Objetos registrados; corpo integral a conferir |
| [RF-PEND-01 — conferência parcial](RF-PEND-01-matriz-conferencia-parcial-2026-11-03.md) | Divergências e fontes GitHub | Parcial, não encerrado |
| [IN127-R01 a R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md) | Seis decisões normativas expressamente ratificadas | Registro integral consolidado; sem homologação |
| [P0 — Fechamento documental](P0-FECHAMENTO-DOCUMENTAL-01.md) | Critérios e pendências impeditivas de iniciar desenvolvimento | Instrumento de acompanhamento, não aceite final |

## 3.1. Recuperação documental adicional — séries AGI

Foi localizado o documento histórico de **Produção Inteligente v0.2** na branch `docs/producao-inteligente-v01` do Portal. A decisão **AD-001** está declarada aprovada naquele documento, mas ele próprio é rascunho e **não contém o texto integral das séries posteriores AGI-RF01-R01..R06 e AGI-P09..P18**. As aprovações de blocos identificadas em registros de conversa não foram convertidas em textos individuais por inferência.

- [RF-PEND-01 — Recuperação de fontes históricas e lacunas AGI](RF-PEND-01-RECUPERACAO-AGI-FONTES-2026-11-03.md)
- [RF-PEND-01 — Manifesto de evidências e lacunas AGI](RF-PEND-01-MANIFESTO-EVIDENCIAS-AGI-2026-11-03.md) — identifica por série o que tem aprovação referida e o que ainda carece de texto original.
- [Produção Inteligente v0.2 (fonte histórica)](https://github.com/rafhael100/imagine-portal/blob/docs/producao-inteligente-v01/docs/PRODUCAO-INTELIGENTE.md)

**Status:** recuperação documental PARCIAL; RF-PEND-01 e P0-DOC02 continuam abertos.

## 3.2. P0-DOC03 — Autoridades entre sistemas

Confronto inicial, com fontes e hashes, dos responsáveis por contrato, vínculo CRM, T0, resultado de entrega, aceite, publicação, estado de pagamento e aprovações críticas. Atribuições lógicas conhecidas **não equivalem a contratos executáveis nem aprovação da solução**.

- [P0-DOC03 — Matriz de autoridades e 10 lacunas concretas](P0-DOC03-AUTORIDADES-PORTAL-SITES-CRM-MATRIZ-2026-11-03.md)

**Status:** especificação parcial; **P0-DOC03 permanece ABERTO**.

**AUT-P01 — RATIFICADA alternativa B:** [registro de aprovação](P0-DOC03-AUT-P01-RATIFICACAO-ALTERNATIVA-B-2026-11-03.md), com versão oficial do vínculo Site × CRM, validação em ambas as APIs, barreira de revogação e falha fechada. [AUT-P02 — proposta pendente](P0-DOC03-AUT-P02-MATRIZ-ELEGIBILIDADE-CAPTACAO-CORTESIA-SUSPENSAO-PROPOSTA.md) trata separadamente captação, cortesia, assinatura paga e suspensão. **P0-DOC03 permanece aberto**.

**AUT-P02 — alternativa B APROVADA em 04/11/2026:** [registro normativo](AUT-P02-aprovacao-alternativa-B-2026-11-04.md). A proposta original continua disponível como histórico. **AUT-P03 a AUT-P10 ainda não foram aprovadas**; P0-DOC03 permanece aberto.

**AUT-P03 — proposta de troca controlada:** [documento para deliberação](P0-DOC03-AUT-P03-TROCA-CONTROLADA-VINCULO-CRM-PROPOSTA.md). A alternativa B é recomendada, mas **não ratificada**. AUT-P04 a AUT-P10 pendentes.

**AUT-P03 — RATIFICADA alternativa B em 04/11/2026:** [documento oficial](P0-DOC03-AUT-P03-RATIFICACAO-ALTERNATIVA-B-2026-11-04.md). Troca por chamado da equipe Imagine com bloqueio seguro de ambas as APIs, ativação de nova revisão e confirmação do destino, sem redirecionar leads antigos nem migrar dados automaticamente. AUT-P01 e AUT-P02 B preservadas; **AUT-P04 a AUT-P10 pendentes**. A proposta AUT-P03 listada anteriormente permanece como histórico de pré-aprovação. **P0-DOC03 continua aberto.**

**AUT-P04 — proposta histórica (antes da aprovação B):** [identidade do artefato, aceite expresso e gate de publicação](P0-DOC03-AUT-P04-IDENTIDADE-VERSAO-ACEITE-ARTEFATO-PROPOSTA.md). A alternativa B foi **aprovada posteriormente**; consultar o registro normativo AUT-P04 abaixo. Preservadas AUT-P01/02/03 B; AUT-P05 a AUT-P10 pendentes. **P0-DOC03 continua aberto.**

**AUT-P04 — APROVADA alternativa B (04/11/2026):** [registro normativo](AUT-P04-aprovacao-alternativa-B-2026-11-04.md). Manifesto imutável, aceite expresso e prova de correspondência com o artefato publicado; a minuta anterior fica como histórico. AUT-P01/02/03 B preservadas; **AUT-P05 a AUT-P10 PENDENTES**. P0-DOC03 permanece aberto.

**AUT-P05 — proposta histórica (anterior à aprovação B):** [confirmação oficial de entrega ao CRM e reconciliação](P0-DOC03-AUT-P05-CONFIRMACAO-OFICIAL-LEADS-CRM-RECONCILIACAO-PROPOSTA.md). Recomendada a alternativa B: comando idempotente, resultado autoritativo CRM, consulta de estados inconclusivos e limite IN127 de 432h. **Aprovada posteriormente conforme registro normativo AUT-P05 abaixo**. AUT-P01 a AUT-P04 B preservadas; AUT-P06 a AUT-P10 pendentes; P0-DOC03 segue aberto.

**AUT-P05 — APROVADA alternativa B (04/11/2026):** [ratificação normativa](AUT-P05-aprovacao-alternativa-B-2026-11-04.md). Comando idempotente, confirmação de persistência pelo CRM, consulta de resultado inconclusivo antes de retentativa e limite contínuo de 432h. A proposta prévia é histórico. **AUT-P06 a AUT-P10 permanecem pendentes**; P0-DOC03, RF-PEND-01 e RF-PEND-04 ainda não estão encerrados.

**AUT-P06 — B aprovada (04/11/2026):** [registro normativo](AUT-P06-B-aprovada-2026-11-04.md). T0/destino originais, autorização específica do backlog e limite 432h. AUT-P07–P10 pendentes; P0-DOC03 aberto.

**AUT-P07 — PROPOSTA PENDENTE:** [prova de descarte nas duas APIs, filas e backups](P0-DOC03-AUT-P07-COMPROVACAO-DESCARTE-APIS-FILAS-BACKUPS-PROPOSTA.md); alternativa B recomendada, ainda **não ratificada**. AUT-P01–P06 B já aprovadas; AUT-P08–P10 pendentes. Não altera IN127-R01 a R06; P0-DOC03 permanece aberto.

**AUT-P07 — B APROVADA (04/11/2026):** [registro oficial](AUT-P07-B-aprovada-2026-11-04.md). Inventário das cópias, descarte comprovado nas estruturas ativas/temporárias e controle da restauração de backups, sem ampliar prazos IN127. A proposta anterior fica como histórico. **AUT-P08 a AUT-P10 pendentes**, P0-DOC03 aberto.

**AUT-P08 — proposta histórica (anterior à aprovação B):** [estados de publicação, falha, recuperação e entrega](P0-DOC03-AUT-P08-ESTADOS-PUBLICACAO-RECUPERACAO-ENTREGA-PROPOSTA.md). Recomendada alternativa B: estados distintos por autoridade, provas da versão efetivamente publicada e entrega oficial idempotente COM-13/REL-01. AUT-P01–P07 B aprovadas; **AUT-P09–P10 pendentes**; P0-DOC03 aberto.

**AUT-P08 — ALTERNATIVA B APROVADA (04/11/2026):** [decisão normativa](AUT-P08-B-aprovada-2026-11-04.md). Estados independentes e comprovados de versão/aceite, publicação, disponibilidade, incidente/recuperação e entrega oficial idempotente. O evento ENTREGUE inicia Dia 0 REL-01; falhas posteriores não apagam histórico. A proposta original é histórica. **AUT-P09 e AUT-P10 PENDENTES**, P0-DOC03 ABERTO.

**AUT-P09 — proposta histórica (antes da aprovação B):** [matriz de risco, ferramentas, autonomia e aprovação humana](P0-DOC03-AUT-P09-MATRIZ-AUTONOMIA-AGENTES-APROVACAO-HUMANA-PROPOSTA.md). Recomendada alternativa B, ainda **não ratificada**: baixo/moderado seguro por playbook homologado, alto/crítico com **uma única aprovação humana**, sem duplo aceite e sem autoelevação. AUT-P01–P08 B aprovadas; **AUT-P10 pendente**, P0-DOC03 aberto.

**AUT-P09 — ALTERNATIVA B APROVADA (04/11/2026):** [decisão normativa](AUT-P09-B-aprovada-2026-11-04.md). Autonomia por agente/operação/ambiente/risco, execução segura de baixo/moderado risco por playbook homologado e **uma única aprovação humana específica** para intervenção crítica. Proposta histórica preservada. **AUT-P10 PENDENTE; P0-DOC03 ABERTO.**

**AUT-P10 — proposta histórica anterior à aprovação B:** [autoridades financeiras, assinaturas e benefício CRM Básico](P0-DOC03-AUT-P10-AUTORIDADES-FINANCEIRAS-ASSINATURAS-BENEFICIOS-PROPOSTA.md). Alternativa B recomendada, **não ratificada**: confirmação de liquidação e conciliação oficial, estados contratuais próprios de Portal/Sites e CRM, elegibilidade da cortesia por todos os vínculos válidos, sem cobrança ou suspensão por inferência. **AUT-P01 a AUT-P09 B aprovadas; P0-DOC03 aberto.**

**AUT-P10 — ALTERNATIVA B APROVADA (04/11/2026):** [decisão normativa](AUT-P10-B-aprovada-2026-11-04.md). Fontes de liquidação/estorno conciliadas por financeiro oficial, contratos independentes de Portal/Sites e CRM, cortesia por elegibilidade de todos os vínculos, sem cobrança ou suspensão por inferência. **AUT-P01 a AUT-P10: todas B aprovadas normativamente; P0-DOC03 e RF-PEND-01 permanecem abertos, RF-PEND-04 sem homologação, AGI-11-RB01 desabilitado.**

**RV-CT01 — alternativa B APROVADA como diretriz documental (04/11/2026):** [registro normativo](P0-DOC03-RV-CT01-B-APROVADA-2026-11-04.md). Contratos técnicos, P0-DOC03 e RF-PEND-01 ainda abertos; RV-CT02 a RV-CT08 pendentes. A [minuta anterior](P0-DOC03-RV-CT01-IDENTIDADE-VINCULO-AUTORIZACAO-SITE-CRM-PROPOSTA-2026-11-04.md) é histórica.

**RV-CT02 — alternativa B APROVADA como diretriz documental (04/11/2026):** [ratificação](P0-DOC03-RV-CT02-B-APROVADA-2026-11-04.md). Recepção durável independente nas duas APIs, identidade estável, idempotência, confirmação do CRM e reconciliação, respeitando 432h. **Contrato técnico ainda pendente; RV-CT03 a RV-CT08 pendentes; P0-DOC03 aberto.**

## 4. Mapa dos blocos de planejamento

| Bloco | Fontes conhecidas | Estado de cobertura | Próxima atividade |
|---|---|---|---|
| Produtos Onepage/Landing Page | Portal `docs/OFERTA-ONEPAGE.md`, `docs/JORNADA-ONEPAGE.md`; CRM `PORTAL-01` | Regras aprovadas coexistem com legado | Confrontar ofertas/contratos e vigência |
| Portal: conta/contratação/financeiro | `docs/PROJETO.md`; CRM `PORTAL-D01/D02/D04`, `PORTAL-COM-02..15` | Histórico parcialmente divergente | Reconciliar preços, aceite e cobrança |
| Integração Sites × CRM | CRM `IN-118..129`, `IN-147..149`; registro IN127-R01..R06 | Seis decisões finais registradas; contratos não homologados | Rastrear limites, estados e autoridades |
| Produção e publicação por agentes | CRM `PORTAL-COM-13`; aprovações AGI-xx; IN-095..114 históricos | Textos AGI canônicos incompletos | Localizar corpo integral de AGI-P09..P18 e AGI-RF01-R01..R06 |
| Infraestrutura e Cloudflare | `PORTAL-CF-01`, `PORTAL-01`; decisões IN-096..114 | Arquitetura lógica e estudos, sem homologação | Fechar topologia inicial, contratos, custos e escalabilidade |
| Domínios/DNS/SSL | IN-100 e PORTAL-CF-01, quando localizáveis | Cliente controla DNS; Cloudflare preferida | Conferir contrato e modelo por domínio |
| Backups/restauração | IN-101 e fontes AGI/CRM | Diretrizes aprovadas; RPO/RTO/retencão em aberto | Especificar fronteiras, restauração e custos |
| Privacidade e documentos legais | CRM `PORTAL-COM-14/15`, `IN-125` | Diretrizes registradas e exceções | Revisar aceite de versão distinto de política e consentimento |
| SEO/integrações Google | CRM `PORTAL-GOO-01`, `PORTAL-MON-01`, `PORTAL-SEO-01` | Três integrações Google; GA4 sem importação de métricas | Verificar dependências iniciais e APIs permitidas |
| Contratos técnicos, segurança, suporte e SLA | AGI/CLD/P0/INT e CRM `IN-168` | Aprovações dispersas; lacunas P0 | Matriz contratual/autoridade e critérios de aceite |
| Universidades e Performance AI | Projetos independentes, documentação própria | Fora deste gate inicial Sites, salvo dependências cruzadas explícitas | Não misturar backlog nem autorizar desenvolvimento |

## 5. Decisões normativas vigentes com referência clara

- **IN-127 R01–R06:** T0 na recepção durável; 72h+360h=432h; descarte da carga pessoal pendente; auditoria mínima até 90×24h; reconciliação controlada; publicação estática sem CRM com formulários bloqueados sem destino. Ver registro específico acima.
- **Formulários:** captação backend só com destino CRM vinculado e autorizado; sem armazenamento implícito no Portal.
- **Aceite e publicação:** aprovação expressa da versão do site; ausência de resposta não autoriza aceite. Política de privacidade padrão de sites simples tem regra documental distinta, com privacidade e revisão adequadas.
- **Sites novos:** objetivo arquitetural Cloudflare Workers Static Assets + GitHub + serviços separados para APIs, agentes e n8n; **dimensionamento e homologação pendentes**. Workers for Platforms é hipótese futura, não produção aprovada.
- **DNS:** responsabilidade do registrador/cliente; preferir Cloudflare DNS quando o cliente desejar; não presumir migração ou controle autoritativo pela Imagine.
- **Comercial posterior:** implantação Pix à vista 5% ou cartão até 3× sem desconto; mensalidade Pix Automático 5% ou cartão recorrente sem desconto Pix; sem Pix parcelado. Valores finais por produto continuam sob revisão.
- **GA4:** integração voluntária sem ingestão, armazenamento ou exibição de relatórios GA4 no Portal; cliente consulta o Google Analytics diretamente.
- **Projetos:** não compartilhar indevidamente contratos/custos do CRM e Sites; benefício Básico apenas sob política própria e situação elegível.
- **Rollback AGI-11-RB01:** **DESABILITADO**; vedada ativação sem autorização específica.

## 6. Situações que não podem ser confundidas

| Situação | Significado |
|---|---|
| `RATIFICADO` | Aprovado expressamente na conversa como decisão normativa |
| `REGISTRADO` | Texto ou referência escrita no GitHub, sem inferir conteúdo ausente |
| `CONFERIDO` | Confrontado com fonte integral e versão identificada |
| `PENDENTE_P0` | Falta decisão ou contrato crítico para autorizar a fase correspondente |
| `ESTUDO` | Alternativa/custo proposta, não escolhida nem homologada |
| `HOMOLOGADO` | Exige evidência técnica posterior; não aplicável aos itens atuais |
| `DESENVOLVIMENTO_AUTORIZADO` | Exige aceite final do pacote e autorização expressa; ainda não concedida |

## 7. Portões para encerrar esta etapa

1. Localizar e conferir os originais de todas as aprovações relevantes à primeira entrega.
2. Inventariar branches e fontes, com hashes/commits e divergências.
3. Consolidar regras comerciais e documentos históricos **sem apagar a origem**.
4. Identificar e resolver P0 de autoridade, identidade, integração, publicação, dados, segurança e topologia.
5. Formalizar escopo inicial, backlog, critérios de aceite e critérios de testes futuros.
6. Obter revisão e aprovação expressas do pacote documental final pelo responsável.

**Nenhum dos seis portões pode ser marcado concluído somente porque este índice foi criado.**

**Proibições:** não escrever código, configurar workflows ou infraestrutura, contratar, realizar testes, homologar provedores, fazer deploy ou habilitar AGI-11-RB01.
