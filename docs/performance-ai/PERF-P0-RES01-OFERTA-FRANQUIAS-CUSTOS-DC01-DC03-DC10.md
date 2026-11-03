# PERF-P0 — RES-01: Decisões Prioritárias de Oferta, Franquias e Viabilidade

**Projeto:** Imagine Performance AI  
**Data-base:** 03/11/2026  
**Versão:** 0.3 — RES01-D01 e RES01-D02 aprovadas funcionalmente; D03–D16 ainda em análise, sem alterar o escopo das pendências  
**Situação:** **RES-01 EM ANÁLISE; SOMENTE RES01-D01 e RES01-D02 APROVADAS FUNCIONALMENTE em 03/11/2026**, respectivamente Modelo B (assinatura-base por empresa e plano) e Canal Único (Google Ads **ou** Meta Ads, apenas um canal gerido por vez; troca por solicitação autorizada no Portal/chamado com transição controlada, sem novo plano). **RES01-D03–D16 NÃO APROVADAS**; **nenhuma baixa de P0**, preço, cota ou homologação.  
**Eixos:** DC-01 (implantação/preços), DC-02 (contas, canais, marcas e campanhas), DC-03 (IA, criativos, vídeo, landing, Central Mínima, relatórios), DC-10 (custos e escala). Dependências DC-04/05/06/07/08/09 permanecerão abertas.  
**Rastreabilidade P0:** 4 do BL-01 e 4 do BL-04, **8 P0 originais, TODOS ABERTOS**, sem prejuízo dos outros 83 P0, 39 P1 e 24 P2, D-01–D-14, D-15 candidata, 20 CT-PERF candidatos e 15 HT-R01 não homologados.  
**Restrição:** somente planejamento/documentação. Não iniciar programação, testes reais, gastos, campanhas, OAuth, publicação, contratos, cobrança nem contratação de fornecedores.

## 1. O que JÁ foi aprovado versus o que NÃO foi

**Aprovado funcionalmente nos módulos anteriores:**
- O produto é contratável de forma independente no Portal; motor Performance separado, CRM e Sites opcionais.
- **Canal Único, Multicanal e Operação Ampliada** são a arquitetura comercial inicial; cotas e adicionais serão explícitos.
- Mídia paga pelo cliente diretamente aos provedores no modelo-base, separada da assinatura Imagine.
- Não haverá cobrança surpresa, nem N3 automaticamente liberado no pacote mais alto.
- Central Mínima de Leads sem CRM é possibilidade funcional, condicionada à homologação e contratos.

**Ainda NÃO aprovado:** preços, tarifa de implantação, número de contas/campanhas, cotas IA/vídeo/leads/relatórios, taxas/descontos, marca/cliente adicional, **eventual taxa de reconfiguração, prazo mínimo entre trocas de canal e regras financeiras/operacionais específicas da transição**, periodicidades SLA, RPO/RTO, condições jurídicas, licenças e custos reais. **A possibilidade qualitativa de troca de canal foi aprovada em RES01-D02**, mas não seus parâmetros quantitativos nem execução técnica.

## 2. Sequência recomendada de fechamento

1. Confirmar **direitos e unidades de medição** sem números inicialmente; distinguir recurso básico, adicional, exclusão e compromisso condicionado.
2. Propor **quantidades por plano** só com cenários de consumo, pesos IA e tabela de fornecedor/custo unitário.
3. Definir **preços e margem por pacote** depois de custo por mix e serviço, gateway e tributação.
4. Fechar, em decisão distinta, **cobrança, descontos Pix, vencimento e SLA**, sem herdar os termos de CRM/Sites.
5. Abrir critérios próprios de habilitação técnica e LGPD; não confundir fechamento comercial com P0 homologado.

## 3. Matriz inicial de composição de planos — arquitetura funcional preexistente

| Eixo | Canal Único | Multicanal | Operação Ampliada | Estado da quantidade |
| --- | --- | --- | --- | --- |
| Canal principal | Google **ou** Meta | Google **e** Meta | Google/Meta, com múltiplas contas/marcas elegíveis | Definir cotas |
| Contas de anúncios | Base limitada | Base separada por canal | Volume escalável contratualmente | **Pendente** |
| Campanhas geridas | Cota por recurso | Cotas por canal | Maior cota contratual | **Pendente** |
| Gestão IA consultiva | Incluída conforme operação e franquia futura | Incluída conforme operação e franquia futura | Cota ampliada mediante custo | **Pendente** |
| Criativos texto/imagem | Dentro de franquia futura | Dentro de franquia futura | Franquia contratual | **Pendente** |
| Vídeo/áudio IA | Cota distinta ou adicional | Cota distinta ou adicional | Cota contratada | **Decidir inclusão** |
| Landing/Onepage nova | **Não presumir** | **Não presumir** | **Não presumir** | Serviço Sites/adicional expresso |
| CRM Imagine | Opcional e separado | Opcional e separado | Opcional e separado | Não conceder cortesia por Performance |
| Central Mínima de Leads | Elegível sem CRM quando homologada | Elegível sem CRM quando homologada | Elegível sem CRM quando homologada | Retenção/limite ainda P0 |
| Relatórios e exportações | Cota futura | Cota futura | Frequência/limite sob contrato | **Pendente** |
| Suporte humano | Escopo/cobertura a cotar | Escopo/cobertura a cotar | Cobertura ampliada só se custeada | SLA em aberto |
| Mídia Google/Meta | Cliente paga separadamente | Cliente paga separadamente | Cliente paga separadamente | Não inclusa na assinatura |
| Autonomia N1/N2/N3 | Sujeita a política/homologação independente | Idem | Idem | Nenhum nível habilitado |

**Cuidado:** os termos “incluído” na matriz são **candidatos de empacotamento**, não direitos comerciais com preço ou capacidade aprovados. A elegibilidade de API de Google/Meta e o consentimento do cliente são gates adicionais.

## 4. Deliberações RES01-D01 a RES01-D16 — D01/D02 aprovadas; D03–D16 propostas

| ID | Decisão específica | Proposta para debate | O que permanece em aberto | Dependência |
| --- | --- | --- | --- | --- |
| **RES01-D01 — APROVADA FUNCIONALMENTE (Modelo B, 03/11/2026)** | Unidade-base de assinatura | **Uma assinatura-base por empresa contratante e plano Imagine Performance AI; contas publicitárias, marcas e campanhas são capacidades e adicionais sujeitos a franquias futuras.** Empresas isoladas por padrão; Performance, CRM e Sites com contratos independentes. | **CNPJ/validação de pessoa jurídica, periodicidade definitiva, preços, quantidades, custos, adicionais e homologações continuam pendentes.** | DC-01/02, P0-COM-01/02 — **ABERTOS** |
| **RES01-D02 — APROVADA FUNCIONALMENTE (Alternativa B, 03/11/2026)** | Canal Único | Escolher **Google Ads ou Meta Ads** na contratação; **gerir somente um canal por vez**. Troca de canal **mediante solicitação no Portal Imagine ou chamado**, autorização do cliente e transição controlada, **sem exigir contratação de novo plano**. A troca **não pausa automaticamente anúncios externos nem pode provocar perda indevida de leads**. | **Número de contas e campanhas, prazo mínimo de troca, eventual taxa de reconfiguração, preços, custos, permissões/conexões, retenção e homologação operacional** ainda pendentes. | DC-02, PERF-12/P0-COM-02 — **ABERTO** |
| RES01-D03 | Multicanal | Acesso funcional a **Google Ads e Meta Ads**, cada um com contas/campanhas expressamente cotadas; atribuição consolidada sem contar vendas em duplicidade | Contas, campanhas, custo de conectores, versão e elegibilidade pendentes | DC-02, P0-COM-02 |
| RES01-D04 | Operação Ampliada | Pacote para múltiplas marcas/contas e maior volume, **não ilimitado**; dimensão por contrato e quotas homologáveis | Definir se preço-base padronizado mais adicionais ou proposta individual | DC-01/02, P0-COM-01/02 |
| RES01-D05 | Contabilização de campanhas | Medir número de campanhas **geridas/monitoradas no ciclo** por conta/canal, com estados ativo, pausado, arquivado; não cobrar campanha excluída/arquivada automaticamente | Definir tratamento de campanha pausada, troca e duplicatas; números pendentes | DC-02, P0-COM-02 |
| RES01-D06 | IA consultiva e automação | Unidade comercial por **operação categorizada/ponderada**, com limite por empresa e preço adicional explícito; custo real medido em tokens/ferramentas/retries internamente | Definir tabela de pesos/creditagem e modalidades sem prometer execução N3 | DC-03, P0-COM-02/P0-PAINEL-07 |
| RES01-D07 | Criativos texto/imagem/vídeo | Contabilizar peça/variação aprovada e criação solicitada; correções de defeito atribuível à Imagine não são automaticamente um novo adicional. **Vídeo é cota específica ou adicional** por custo/risco. | Definir se haverá franquia de vídeo em planos; custo por duração/resolução/licença pendente | DC-03, P0-COM-02 |
| RES01-D08 | Landing e Sites | Nova landing/hospedagem/alteração significativa **não incluída por inferência**; Serviço Sites ou adicional contratado expressamente; integração de página existente mediante autorização técnica | Definir eventual pacote comercial combinado sem misturar contratos/cortesia | DC-03, P0-COM-01/02 |
| RES01-D09 | Leads e destino mínimo | Performance pode operar sem CRM mediante **Central Mínima de Leads** prevista e homologada; contar recebimentos válidos e armazenamento/entrega de forma distinta; nenhuma perda silenciosa por franquia | Limites, exportação, prazo de retenção, segurança, custo e notificações pendentes | DC-03, PERF-09/P0-LEAD-02 |
| RES01-D10 | Relatórios, indicadores e consulta IA | Diferenciar visualização de indicadores, exportação/agendamento de relatórios e análise IA; cotas e frequência explicitamente definidos; dados não disponíveis constam como N/D | Periodicidade, quota por conta, formato/privacidade, custo e retenção pendentes | DC-03, PERF-10/P0-PAINEL-07 |
| RES01-D11 | Política de adicional/excedente | Avisar uso e limites, ofertar adicional opcional previamente aprovado e restringir uso não essencial proporcionalmente; **sem cobrança surpresa e sem excluir leads/segurança** | Tolerância, limiares e franquias numéricas ainda a decidir | DC-02/03, P0-COM-02 |
| RES01-D12 | Separação entre mídia e serviço | No modelo-base anunciante mantém titularidade e paga Google/Meta; receita recorrente Imagine não incorpora esse gasto | Preço de implantação, mensalidade e eventual honorário variável DC-06 ainda abertos | DC-01, P0-COM-01 |
| RES01-D13 | Parâmetros comerciais de preço | Modelo de cobrança por pacote com adicionais como estrutura de cálculo candidata; **nenhum valor/percentual publicado** até estudo de margem, gateway, impostos e suporte | Fixar valor e política financeira em decisão própria após RES01-D14/15 | DC-01, P0-COM-01/P0-COM-06 |
| RES01-D14 | Matriz unit economics | Cotar IA, vídeo, equipe, suporte, storage, CRM externo, filas, observabilidade, pagamento, segurança e tributos; segregar fixo, variável por cliente e degraus | Fornecedor, tarifa datada, volume, margens e reservas pendentes | DC-10, P0-COM-06 |
| RES01-D15 | Escala 100–700 clientes | Comparar sete volumes 100,200,300,400,500,600,700 clientes em mix por plano/canal, cenários conservador/base/estresse e quotas efetivas; garantir segurança/qualidade antes de margem | Percentuais do mix, carga, preços, quotas, RPO/RTO, suporte e tecnologia pendentes | DC-10, P0-COM-08/P0-MON-04/P0-COM-09 |
| RES01-D16 | SLA e cobertura de suporte | Não comprometer resposta humana contínua sem equipe; separar SLA contratual, SLO interno, RPO/RTO e exigências dos provedores externos | Janelas, valores, horários, contrato e suporte por pacote pendentes | DC-09/10, P0-MON-04/P0-COM-09 |

**Escolhas que devem ser analisadas de modo expresso, sem aprovação por silêncio:** unidade comercial de campanhas (ativas, geridas, pausadas), swap de canal, marca/conta extra, franquia multimodal, limite de leads e retenção, quota de relatório, preço e política de excedente, suporte e margem. A lista acima não é liberação de oferta pública.


### Registro de aprovação específica — RES01-D01 (03/11/2026)

**Ato:** o usuário aprovou **exclusivamente RES01-D01, Modelo B**. Uma **assinatura-base Imagine Performance AI é vinculada à empresa contratante e ao plano escolhido**; contas publicitárias, marcas e campanhas constituem capacidades do pacote ou adicionais, sempre sujeitos a franquias explícitas a decidir futuramente. Isolamento entre empresas e independência contratual de Performance, CRM e Sites são obrigatórios. Esta deliberação não cria assinatura por campanha nem por conta publicitária.

**Não deliberado:** exigência/validação de CNPJ, critérios detalhados de unidades e grupos econômicos, quantidade de contas, marcas, campanhas, periodicidade financeira definitiva, preço, implantação, desconto, custos, quotas, política de troca de canal, contratos técnicos, processamento de dados, acesso a fornecedores e qualquer autorização operacional. **RES01-D02–D16 permanecem candidatas**, os **oito P0 correlatos continuam abertos**; as 91 P0, 39 P1 e 24 P2 gerais e 15 gates HT-R01 não homologados não sofrem alteração.

**Condição operacional:** somente planejamento/documentação; nenhum código, acesso, ativação, anúncio, cobrança ou gasto autorizado.

### Registro de aprovação específica — RES01-D02 (03/11/2026)

**Ato aprovado expressamente:** o cliente do **Canal Único** escolhe **Google Ads ou Meta Ads** no momento da contratação; é gerido **apenas um canal por vez**. A **alternativa B** foi aprovada: o cliente poderá solicitar a troca de canal pelo Portal Imagine ou chamado, com autorização explícita, validação de permissões e **transição controlada**, sem obrigatoriedade de contratar um novo plano. A troca deve preservar o tratamento dos leads já recebidos e em trânsito, conforme políticas futuras aplicáveis, **sem perda indevida**. **Desvincular a gestão Imagine do canal anterior não significa pausar campanhas nem interromper gastos externos**, que dependem das autoridades/provedores e de autorização específica.

**Não aprovado nesta decisão:** número de contas Google/Meta ou campanhas elegíveis, troca simultânea ou uso permanente de dois canais (reservado ao pacote Multicanal, sujeito à sua deliberação), limites de utilização, **taxa eventual de reconfiguração**, prazo mínimo entre trocas, datas do ciclo, responsabilidades financeiras detalhadas, preços, custos, retenção e integração/credenciais. Operações práticas de corte, migração/reconciliação, segurança LGPD e consentimentos continuam P0/homologação técnica.

**Escopo formal:** exclusivamente RES01-D02. D01 permanece aprovada sem mudanças; **RES01-D03–D16 continuam candidatas, não aprovadas**. **Os 8 P0 do dossiê e todos os 91 P0, 39 P1, 24 P2 gerais continuam abertos**, assim como 20 CT candidatos, D-01–D-14, D-15 candidata e 15 gates HT-R01 não homologados. Nenhuma programação, conexão real, anúncio, alteração de orçamento ou contratação autorizada.

## 5. Oito P0 relacionados — fonte e critérios futuros de baixa

| P0 original | Matéria ainda não resolvida | Deliberações que ajudam | Evidência complementar necessária | Estado |
| --- | --- | --- | --- | --- |
| PERF-09/P0-LEAD-02 | Limites, consentimentos, notificações, exportação, retenção e segurança da Central Mínima sem CRM | RES01-D09 | BL-06/BL-05 segurança LGPD, custos e homologação técnica de fila | ABERTO |
| PERF-10/P0-PAINEL-07 | Quotas por plano, custos de modelos/relatórios/exportações, permissões e retenção | RES01-D06, RES01-D10 | BL-04 custo, BL-05 LGPD e BL-06 mensuração | ABERTO |
| PERF-12/P0-COM-01 | Preço, imposto, implantação, pacote, descontos e contrato, separação da verba mídia | RES01-D01–04, RES01-D12–14 | DC-04/05/06 e cotação tributária/financeira | ABERTO |
| PERF-12/P0-COM-02 | Franquias por canal/conta/marca/campanha/IA/vídeo/relatório/lead/armazenamento e excedentes | RES01-D02–11 | Cotações, precificação, limites de APIs e provedor | ABERTO |
| PERF-11/P0-MON-04 | SLA/SLO, RPO/RTO, prioridade de atendimento e custo | RES01-D15/16 | BL-04 e runbooks de PERF-11; equipe | ABERTO |
| PERF-12/P0-COM-06 | Mapa de custos reais e unit economics, fornecedores, IA, suporte, storage, tributos | RES01-D13–15 | Cotações reais, impostos, infraestrutura e margens | ABERTO |
| PERF-12/P0-COM-08 | Escala/quotas de API e piloto 100–700 clientes | RES01-D15 | Quotas Google/Meta efetivas, modelo de filas e risco | ABERTO |
| PERF-12/P0-COM-09 | SLA, atendimento, continuidade, retenção, segurança, contratos | RES01-D15/16 | Suporte por pacote, RPO/RTO e homologação jurídica/segurança | ABERTO |

**Todos os oito permanecem ABERTOS.** Fontes completas e IDs em [Anexo PERF-R02](PERF-R02-ANEXO-TESTES-PENDENCIAS.md) e [BL-08 Anexo](PERF-P0-BL08-ANEXO-91-P0-GATES-DECISOES.md). Mesmo aprovação eventual de uma unidade de contagem **não dá baixa** nos P0 que exigem custo, segurança, LGPD ou homologação.

## 6. Dimensões e fórmulas para futuras simulações financeiras

Definir, por pacote `i`:
- `N_i`: clientes ativos; `P_i`: mensalidade líquida do serviço; `A_i`: adicionais; `F`: fixos; `V_i`: custo variável real por cliente; `E(N)`: degraus de equipe/infra; `T(N)`: taxas/tributos; `R(N)`: provisões/reservas.
- **Receita mensal:** `Σ N_i × P_i + Σ A_i` (não inclui mídia paga diretamente aos provedores).
- **Resultado simplificado:** `Σ N_i × (P_i - V_i) + Σ A_i - F - E(N) - T(N) - R(N)`.
- **Capacidade:** contas, campanhas, jobs IA, minutos de vídeo, leads, relatórios, storage, tickets/horas humanas e incidentes são dimensões distintas; não dimensionar custo usando apenas o número de clientes.
- **Cenários obrigatórios:** N=100/200/300/400/500/600/700, mix por plano e canal e casos conservador, base, estresse. Não inferir de preços de catálogo sem cotação verificada.

**Nenhum valor monetário ou franquia quantitativa é aprovado neste dossiê**; precificação depende de composição contratual, cotação de fornecedores, validação jurídica/tributária e custo real da operação.

## 7. Critérios futuros de revisão documental RES01-V01–V16 — não executados

1. **RES01-V01:** Canal Único Google não recebe acesso Meta sem contratação e conexão próprias.
2. **RES01-V02:** Multicanal não agrega automaticamente duas atribuições para contar duas vendas.
3. **RES01-V03:** Operação Ampliada não equivale a marca ou campanha ilimitada.
4. **RES01-V04:** Cliente troca canal no meio do ciclo sem regra aprovada: alteração permanece pendente.
5. **RES01-V05:** Campanha arquivada não gera consumo presumido sem regra explícita.
6. **RES01-V06:** Uma correção por falha da Imagine não vira cobrança de novo criativo sem cláusula.
7. **RES01-V07:** Vídeo de alta duração gera custo distinto de imagem e precisa cota/adição expressa.
8. **RES01-V08:** Cliente sem CRM tem destino mínimo elegível, com limites e retenção próprios a decidir.
9. **RES01-V09:** Limite de operações IA não descarta lead, não interrompe alertas de segurança nem autoriza compra automática.
10. **RES01-V10:** Relatório de fonte ausente é N/D e sua geração/entrega tem contagem transparente.
11. **RES01-V11:** Desconto de Pix de Sites/CRM não aparece automaticamente no Performance.
12. **RES01-V12:** Gasto Google/Meta pago diretamente pelo cliente não entra na receita Imagine.
13. **RES01-V13:** Cenário 100 clientes com baixa adesão e ticket insuficiente demonstra risco de custo fixo.
14. **RES01-V14:** Cenário 700 clientes em estresse dimensiona suporte, vídeo, filas, quotas e incidentes.
15. **RES01-V15:** Preço público não é aprovado antes de custeio, tributação e gate comercial específico.
16. **RES01-V16:** Aprovação do dossiê de método não baixa os oito P0 nem autoriza programação.

## 8. Próxima deliberação proposta ao usuário

**RES01-D01 (Modelo B) e RES01-D02 (Canal Único, alternativa B para troca controlada) foram aprovadas expressamente em 03/11/2026.** Próxima deliberação é **RES01-D03 — Multicanal**, para definir como Google Ads **e** Meta Ads coexistirão dentro de uma assinatura-base por empresa, com permissões e direitos independentes por canal, sem aprovação antecipada de quantidades, valores, conexões reais ou consumo. Depois, RES01-D04 a D12, uma a uma.

**Somente após deliberações qualitativas adicionais:** oferecer opções de composição quantitativa (conservadora, intermediária, ampliada) com custo de operação explícito, cotação e margens **em análise**; implantação e mensalidade exigem deliberação própria. DC-01–DC-10 continuam sem fechamento integral; a aprovação individual D01 não dá baixa nos oito P0. **Dossiê RES-01 como um todo segue EM ANÁLISE**, não aprovado.

> Resolver o planejamento não equivale a habilitar runtime. Não iniciar código, contas, anúncios, cobranças, assinaturas, acesso ao CRM ou serviços externos por este documento.
