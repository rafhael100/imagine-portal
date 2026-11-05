# PORTAL-TEC-D04 — Contratação, catálogo, preços, cobranças e contratos (proposta v0.1)

**Data:** 05/11/2026  
**Escopo:** Portal Imagine, oferta e contratos de Onepage/Landing Page e eventual extensão futura a outros serviços.  
**Status:** **PROPOSTA DOCUMENTAL / NÃO RATIFICADA**. Reúne **decisões funcionais já aprovadas anteriormente** com o **recorte técnico inicial proposto**, sem converter novas sugestões em decisões.  
**Cuidado com nomes:** esta **PORTAL-TEC-D04** trata da arquitetura de todo o domínio comercial e financeiro inicial. A histórica **PORTAL-D04**, no repositório CRM, trata de *cobranças recorrentes de sites* e mantém pendências; **não são o mesmo instrumento**.
**Limites:** sem código, testes, cobrança, contratação de PSP, simulação conectada a provedores, produção, merge ou alteração de dados. F1-01 ainda depende de autorização expressa.

## 1. Fontes e precedência

A política mais recente de **5%** substitui números históricos de **20%** (Pix implantação), **10%** (Pix Automático), **cartão em 4×** e **Pix parcelado**. Referências documentadas:
- [Política comercial vigente Pix 5%, cartão 3×](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md).
- [PORTAL-D01 — aceite eletrônico expresso](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D01-aceite-eletronico-versoes-orcamentos.md).
- [PORTAL-D02 — serviços adicionais, aprovação parcial](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D02-pagamento-servicos-adicionais.md).
- [PORTAL-D04 — cobrança recorrente, detalhamento pendente](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-D04-cobranca-recorrente-sites.md).
- [COM-03/05 — campanhas e reajuste](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-05-gestao-administrativa-precos-campanhas-reajuste-anual.md); percentuais históricos desses arquivos cedem à política vigente.
- [COM-06 — primeira mensalidade e contagem de promoções](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-06-inicio-mensalidade-configuravel-por-campanha.md).
- [COM-07 — vencimentos e próximo dia útil](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-07-vencimentos-recorrentes-configuraveis.md).
- [COM-08 e COM-09 — suspensão de site e transição da cortesia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-09-transicao-cortesia-cancelamento-inadimplencia.md).
- [COM-10 — reativação após cancelamento](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-10-proposta-reativacao-site-apos-cancelamento.md).
- [AUT-P10 / fronteiras financeiras Portal × Sites × CRM](../imagine-sites/P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md).

Contratos antigos legítimos devem ser preservados em seu respectivo escopo; o código de checkout legado não substitui o contrato nem a política mais recente.

## 2. Matriz: decidido funcionalmente vs. detalhe em aberto

| Matéria | Regra funcional vigente | Pendência técnica/comercial |
|---|---|---|
| Preços de produtos | Implantação e mensalidade configuráveis por produto e por campanha; ofertas De/Por com limites de prazo/quantidade | Valores definitivos e prova de preço-base; margens/custos; trilhas de publicação |
| Implantação de sites | **Pix à vista: 5%** sobre base elegível; **cartão em até 3× sem desconto Pix**; **não ofertar Pix parcelado** | PSP, custos e conciliação; regra final de cumulatividade com promoção, versão contratual |
| Mensalidade de sites | **Pix Automático: 5%** na mensalidade elegível; **cartão recorrente sem desconto Pix** | PSP e disponibilidade efetiva de Pix Automático, falhas, contingência Pix manual/boleto em estudo |
| Primeira mensalidade | **Padrão atual: 90 dias após contratação**; configurável por produto e campanha; condição efetiva visível no aceite | Contrato/fórmula/fuso para condições específicas |
| Promoção mensal | Por **N mensalidades devidas** contadas da primeira cobrança, ou durante contrato ativo, conforme oferta aceita; reajuste por índice contratual somente após 12 meses e anualmente | Índice, cláusulas e transição exata, revisão jurídica |
| Vencimento | Dia de referência configurável; mês sem dia correspondente usa último dia calendário e volta ao original no mês seguinte | Implementação com calendário/PSP; retentativas; prova de comunicação |
| Dia não útil | Tentar cobrança na data prevista; falhando em dia não útil, regularização até próximo dia útil sem juros/multa, observadas regras bancárias/PSP | Regras bancárias e marco conciliado em todos os canais |
| Inadimplência de site | Referência aprovada de **7 dias corridos de tolerância** antes de suspensão; cortesia CRM Básico dependente do site suspenso pode ser bloqueada | Marco exato após dia útil, regras de notificação, cancelamento formal **não presumir prazo de 30 dias aprovado** |
| Cancelamento e cortesia CRM | Último site elegível: **30 dias de transição**; normal se cancelamento comum, restrita/exportação se cancelamento após suspensão por atraso; CRM pago independente não bloqueia | Eventos técnicos e autorização de transições; sem executar alteração no CRM por UI |
| Reativação após cancelamento | Janela **padrão 60 dias configurável** e **taxa de reativação configurável** por produto/campanha | Valores, evidência de cópia retida e disponibilidade de restauração, termos e direitos |
| Serviços adicionais | Até **R$ 270 à vista**; acima pode **à vista ou até 3× sem juros por cartão**; execução só após aceite + confirmação do valor integral ou da primeira parcela | Descontos Pix para adicionais não presumidos; taxas, estornos, vencimentos e PSP pendentes |
| Aceite | Termo/orçamento exato com versão, preço, periodicidade, escopo, modalidade, representante autorizado e evidência auditável; silêncio não aprova | Formatos jurídicos, retenção, autenticação reforçada em ações sensíveis |
| Entrega | Aceite da versão do site ≠ publicação verificada ≠ entrega; checklist homologado pode permitir entrega automatizada por agente autorizado | Contratos de manifestação de conclusão e agentes/permissões |
| Serviços independentes | Portal gere contratos de Site; CRM pago mantém gestão/cobrança próprias; Performance AI possui contrato e cobrança independente; Universidade segue regra de benefício elegível | Contratos de eventos/vínculos, identidade serviço, segurança e disponibilidade |

## 3. Recorte técnico sugerido por fases (escolha ainda não aprovada)

### D04-FASE-A — motor determinístico de catálogo, preço e contrato (RECOMENDADA PARA F1 inicial, após autorização de código)

- Produtos/versões, preços-base e ofertas por serviço/campanha, elegibilidade e limite real de campanha; sem fixar preços históricos em código.
- **Cálculo puro de proposta** em valores monetários precisos com origem de cada item, descontos, política de preço, prazo e resultado; **não usar IA como autoridade de valor**.
- Snapshot imutável da proposta/versão contratual com preço nominal, parcelas permitidas, desconto, vigência, primeiro vencimento, período promocional e regras aceitas.
- Área do cliente e administrativa somente com contratos fictícios em ambiente isolado; geração de *prévia* de oferta e simulação **sem pagamento real** e sem conceder benefício real.
- Aprovação real de proposta/contrato em produção **não** é liberada pela fase A: faltam autenticação/RBAC, integridade e termos jurídicos, prova e homologação.
- Sem PSP, Pix real, cartão real, boleto, cobrança, emissão fiscal, chamada de ativação CRM ou publicação de site.

### D04-FASE-B — fluxo de contratação e comprovação de aceite (futura, condicionada)

- Aplicação da matriz de contas e titulares aprovada em D03; checar usuário autorizado e escopo; validade do orçamento.
- Estado da contratação distinto de estado financeiro, estado de projeto, aceite de artefato e entrega oficial.
- Evidência auditável de aceites e versões; recusa/ajustes com histórico; mudanças materiais solicitam novo aceite.
- Ordem de serviço do adicional apenas após confirmação externa e segura do pagamento exigido e demais gates de produção.

### D04-FASE-C — cobrança, conciliação, fiscal e benefícios (futura, condicionada)

- Seleção/homologação de PSP e emissor fiscal e suas capacidades reais; Pix Automático e parcelamento conforme meio.
- Tokens opacos e segredos fora do código; não armazenar PAN/CVV, não confiar em webhook isolado, provedor de pagamentos/fiscal como fonte de verdade dos fatos correspondentes.
- Comandos/retornos idempotentes, conciliação, estornos, pagamentos parciais, notificações, expiração, cancelamento; aprovações de operações de risco e auditoria.
- Separação Site×CRM: benefício cortesia apenas por elegibilidade e contrato da conta CRM exata; não afetar assinatura CRM paga, nem gerar login CRM a partir do Portal.
- Integração técnica com CRM/Sites somente quando contratos RV-CT e gates P0 correspondentes estiverem homologados.

**Não há autorização automática de B ou C quando a fase A for aprovada.**

## 4. Modelo lógico da oferta e do ciclo financeiro — proposta

| Objeto conceitual | Estado/fato necessário | Regra de consistência |
|---|---|---|
| Catálogo e versão | Produto, variante, escopo, preço-base, versão aplicável | Campanha posterior não altera contratos já aceitos |
| Campanha | Regra, vigência, limite real, elegibilidade e efeitos sobre os preços | Reserva/consumo de limite requer transação e política antifraude definida |
| Cotação/offer snapshot | Valor inicial, desconto aplicável, preço recorrente, calendário, parcelas e regras | Simulações não têm efeitos financeiros |
| Aceite do contrato | Quem, quando, versão/termos íntegros, poderes, canal | Aceitar ≠ liquidar ≠ aprovar a versão do site |
| Pedido e serviço | Organização, responsável, produto, contrato, estados separados | Contrato não depende de usuário único, nem de sessão CRM |
| Título/cobrança | Competência, vencimento, valor pactuado, provedor, estado observado | Emitido ≠ liquidado; dados bancários devem ser conciliados |
| Reconciliação | Id externo, tentativas/eventos, comprovante oficial e duplicidade | Webhook não comprova sozinho pagamento irreversível |
| Benefício CRM cortesia | Conta CRM exata, serviço elegível, estado, razões da transição | Não afeta plano CRM pago nem permite acesso sem login CRM |
| Auditoria | Identidade, versão, origem/justificativa, ação e resultado | Logs minimizados, retenção e acesso por política |

## 5. Simulações ilustrativas SEM preços reais aprovados

Se um produto ilustrativo tivesse implantação de **R$ 1.200,00** (valor hipotético, não preço aprovado para venda), a escolha de **Pix à vista**, quando elegível, resultaria em **R$ 1.140,00** com desconto de 5%. No **cartão em 3×**, o valor-base hipotético de R$ 1.200,00 seria distribuído em **3 parcelas de R$ 400,00**, sem desconto Pix; custos reais do PSP/contrato ainda não definidos.

Para mensalidade ilustrativa de **R$ 100,00**, o **Pix Automático** elegível reduziria para **R$ 95,00** enquanto aquela regra contratual se aplicasse; cartão recorrente permaneceria em R$ 100,00. Não interpretar isso como anúncio de preço nem homologação bancária.

**Ponto de atenção:** se houver preço promocional de implantação "De/Por", a incidência do desconto Pix **após o preço promocional** existe na documentação histórica, mas a cumulatividade, a elegibilidade, as exceções e a redação contratual precisam ser reconciliadas com a política posterior de 5% antes da codificação definitiva. **Não gerar descontos automáticos além dos aprovados.**

## 6. Decisões e gates restantes para F1-01

| Código | Situação | Liberação necessária |
|---|---|---|
| D04-P01 — recorte A | **PROPOSTO** | Aprovação do responsável de preço/contrato simulado, sem gateway |
| D04-P02 — precedência e cumulatividade de campanhas/desconto | **PENDENTE DE CONCILIAÇÃO** | Regras exatas e versões vinculadas ao aceite |
| D04-P03 — catálogo/serviços iniciais e parâmetros | **PARCIAL** | Priorização por produto/variante e preços comerciais finais apenas quando necessário para contratação real |
| D04-P04 — PSP, Pix Automático, cartão, boleto | **PENDENTE** | Provedor/custos/capacidades, segurança, homologação, contrato e autorizações separadas |
| D04-P05 — financeiro, fiscal, reembolso e transições | **PENDENTE** | Eventos e autoridades, LGPD, jurídico, testes e alçadas |
| D04-P06 — contratação com CRM/Sites/Performance | **PENDENTE DOS CONTRATOS P0** | Garantir ciclos de serviço e contas independentes |
| D04-P07 — implementação financeira real | **NÃO AUTORIZADA** | Sem ativação ou cobrança de qualquer tipo |

A presente D04 não fecha P0-DOC01..14, P0-DOC03/RV-CT, RF-PEND-01/RF-PEND-04, SEG, MFA nem autoriza F1-01. **AGI-11-RB01 desabilitado.**

## 7. Deliberação sugerida, a ser submetida ao responsável

**Pergunta futura de aprovação:** aprovar **somente D04-FASE-A** como escopo direcional da futura primeira implementação do domínio comercial: catálogo de produtos e campanhas, cálculo de propostas, contratos e aceites apenas fictícios/simulados, sem PSP e sem efeitos reais? O ajuste de promoções e regras pendentes será tratado em instrumento específico antes de qualquer cobrança.

**Status final:** proposta documental disponível para avaliação, sem programação, testes, merge, provedor ou pagamento.
