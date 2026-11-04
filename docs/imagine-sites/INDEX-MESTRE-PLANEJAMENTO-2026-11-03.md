# IMAGINE SITES — Índice mestre de planejamento e prontidão documental

**Situação:** ETAPA 1 — SANEAMENTO/SINCRONIZAÇÃO DOCUMENTAL EM ANDAMENTO.  
**Natureza:** catálogo de rastreabilidade, **não** versão final homologada nem autorização de programação.  
**Data de referência das aprovações:** 03/11/2026; edição documental posterior autorizada pelo responsável.  
**Escopo:** sites Onepage e Landing Page, administração pelo Portal Imagine, integração CRM Imagine, agentes de produção/suporte, publicações, captação, faturamento e infraestruturas correspondentes.

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
