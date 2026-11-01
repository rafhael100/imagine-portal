# Central financeira do cliente

A aba Pagamentos reúne valores liquidados, cobranças abertas (incluindo vencidas), atraso e confirmação ainda não liquidada. O total pago é histórico nominal por status, não saldo após estornos. Vencimentos pendentes anteriores à data atual de Brasília são tratados como atrasados.

A prioridade é a cobrança vencida mais antiga ou o próximo vencimento aberto. Links de pagamento são aceitos somente no Asaas e apenas quando já cadastrados. Sem link, o cliente pode abrir a conversa do pedido. Não são gerados pagamentos por esta interface.

Histórico com filtros de projeto, tipo, situação, busca e vencimento, paginação de dez linhas e CSV de todos os resultados filtrados. Detalhes dão acesso ao pedido e às notas do projeto.

Assinaturas exibem a situação, primeiro vencimento, valor configurado e próxima cobrança já gerada. Não inventam datas futuras. Novos pedidos usam R$ 79,90/D90 com recorrência incluída na compra; contratos legados mantêm suas condições. A homologação real continua pendente.

Notas são consultadas pela API fiscal autenticada do próprio cliente, com distinção de homologação e produção e links disponibilizados pelo backend. Pagamento e emissão são processos separados; a implantação pode ter uma nota pelo serviço total e três parcelas.

Validação: 49 testes, incluindo classificação financeira, filtros combinados, isolamento fiscal existente e disponibilidade do script estático. Prévia local com dados fictícios; nenhuma cobrança ou nota real criada.


## IN-115 — Planejamento da aba de Orçamentos e pagamentos de serviços extras (APROVADO em 01/11/2026)

A nova **aba Orçamentos** será independente na navegação do Portal Imagine (visão de administração e visão autorizada de cada cliente), mas **não criará um segundo sistema financeiro**: propostas e seus aceites remetem à fonte canônica de cobranças, parcelas, estornos, notas e liquidação da **Central Financeira**. Para serviços adicionais pequenos, **pagamento integral antes da execução**; para projetos maiores, **parcelamento por orçamento específico aceito**, com entrada/marcos/condições explicitados. A distinção objetivo de pequeno e grande, meios e taxas ainda não foram aprovadas. O cliente deverá validar o orçamento no Portal e **autorizar os pagamentos aplicáveis separadamente**, sem emissão automática por simples aceite; status de pago depende de conciliação comprovada. As cobranças extras de sites pertencem ao Portal, não à assinatura paga do CRM Imagine. O aceite de orçamento não substitui o aceite de publicação da versão exata do site (IN-109). A criação da aba e dos fluxos financeiros depende de planejamento integral e posterior programação e homologação autorizadas.

[Documento da Central de Orçamentos](https://github.com/rafhael100/imagine-portal/blob/docs/producao-inteligente-v01/docs/ORCAMENTOS-SERVICOS-ADICIONAIS.md). **Nenhuma cobrança real ou mudança de produção foi realizada**.


### IN-115 — Complemento aprovado: corte financeiro de R$ 270 para pagamento de serviços adicionais (01/11/2026)

**Decisão comercial expressa:** o **valor total de cada orçamento de serviço adicional aprovado e aceito no Portal Imagine** define a condição de pagamento: **até R$ 270,00, inclusive, pagamento integral à vista**; **acima de R$ 270,00, pagamento à vista ou parcelamento opcional em até 3 (três) vezes**. O ponto de corte é estritamente **R$ 270,00**: exatamente R$ 270,00 **não é parcelável** por esta regra; R$ 270,01 ou mais poderá receber proposta de até três parcelas, sem obrigação de parcelar. A regra aplica-se a **extras de Onepage/Landing Page**, não altera implantação, recorrência, CRM Básico Cortesia ou preços de serviços já aprovados. **A classificação por complexidade/porte do projeto não substitui esse corte de valor para fins de elegibilidade ao parcelamento.**

**Fluxo:** orçamento com escopo, valor total, condição à vista ou quantidade de parcelas (1–3 quando elegível), valores, datas de vencimento e demais condições é preparado/assistido por IA; **responsável Imagine autorizado valida preço e parcelamento**, e o cliente **aceita expressamente a versão exata no Portal**. Para orçamento até R$ 270, aguardar confirmação da quitação integral antes de executar; para orçamento superior a R$ 270, seguir os pagamentos de entrada e marcos expressamente aceitos e conciliados para liberar execução. Aceite não equivale a autorização automática de cobrança ou confirmação de liquidação, nem substitui aprovação documental da versão do site antes de publicar (IN-109). Cobrar somente pelo financeiro homologado do Portal; não duplicar assinaturas nem pagamentos do CRM.

**Pendências não decididas:** juros ou ausência deles, tarifas, eventuais valores mínimos por parcela, condições/meios de pagamento habilitados, vencimentos e entrada para orçamentos parcelados, regras fiscais, reembolsos, inadimplência, estornos, limites de alçada e vigência do orçamento. **Não afirmar parcelamento sem juros nem criar juros, taxas ou datas padrão sem decisão posterior.** Essas condições deverão constar da proposta e dos contratos completos Onepage/Landing Page aceitos no Portal antes do lançamento. Nenhuma programação, cobrança ou mudança em produção foi autorizada por esta aprovação.
