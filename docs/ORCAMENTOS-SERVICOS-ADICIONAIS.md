# Imagine — Central de Orçamentos de Serviços Adicionais (IN-115)

> **Planejamento aprovado em 01/11/2026, exclusivamente documental.** Este documento descreve a futura **aba exclusiva “Orçamentos”** do Portal Imagine, o aceite comercial e as cobranças de serviços adicionais dos produtos Onepage e Landing Page. Nenhuma funcionalidade foi implementada, preço de adicional foi tabelado, cobrança emitida ou contrato alterado.

## 1. Decisões comerciais aprovadas

- **Alterações de conteúdo nos blocos existentes:** incluídas na mensalidade, **sem teto mensal fixo de solicitações**, sujeitas a política objetiva e transparente de uso justo a detalhar. Alterações simples devem ser preparadas para revisão em **até 3 dias úteis depois de recebidos os materiais necessários**; solicitações de conteúdo mais complexas terão prazo justificado. Mesmo sem cobrança, toda atualização pública requer aprovação prévia, expressa e documentada do cliente para a **versão exata** (IN-109).
- **Alterações sujeitas a orçamento:** mudança de layout, redesign, blocos ou seções adicionais, páginas/estruturas novas, funcionalidades e integrações não previstas no contrato original.
- **Orçamento aprovado em duas etapas:** agentes de IA podem preparar análise/estimativa; **somente responsável comercial autorizado da Imagine valida preço e condições**. Em seguida, **cliente autorizado aceita explicitamente a proposta no Portal Imagine**, com registro da versão aprovada. Silêncio, e-mail externo e atuação autônoma do agente não equivalem a aceite.
- **Pagamento de extras somente pelo Portal Imagine:** para trabalhos classificados como **pequenos**, a proposta comercial é de **pagamento integral antecipado antes da execução**; para trabalhos **maiores**, pode haver parcelamento definido individualmente no orçamento, com valores, vencimentos, marcos de execução e consequências expressamente aceitos pelo cliente. **Não há valor-limite aprovado que distinga pequeno de grande**, nem taxa de juros, quantidade padrão de parcelas ou opção de pagamento específica aprovada para extras. A Imagine determinará a classificação e condições conforme regras comerciais futuras, sem arbitrariedade e com transparência.
- **Cobrança autorizada, não automática no aceite:** orçamento validado + aceite eletrônico do cliente não significam pagamento confirmado nem autorizam cobrança não prevista. Gerar pagamento pelo fluxo financeiro do Portal **somente após autorização explícita da compra/condição de pagamento e confirmação da viabilidade técnica/fiscal aplicável**, com conciliação do provedor homologado. Para execução de serviço pequeno, aguardar quitação integral confirmada; para maior, a condição de entrada e marcos de trabalho deverão estar descritos e atendidos no orçamento. Não iniciar trabalho cobrável antes dos gates comerciais e financeiros aprovados.
- **Integração com financeiro existente:** permitir acompanhamento da cobrança na área de **Pagamentos/Financeiro** já prevista no Portal e referências ao pedido, orçamento, autorização, liquidação e nota fiscal quando pertinente. **Não** criar um segundo livro-razão ou duplicar cobranças, nem replicar cobrança de plano CRM pago. O Asaas e a emissão fiscal Focus NFe exigem homologação/configuração e revisão tributária para novas modalidades; não presumir que notas fiscais de extras terão o mesmo regime da implantação recorrente do Onepage.

## 2. Aba exclusiva de “Orçamentos” no Portal Imagine

**Requisito aprovado:** disponibilizar uma **aba exclusiva “Orçamentos”** na área de administração Imagine para **gerenciar todos os orçamentos de serviços adicionais**, vinculados a empresa, cliente, site, produto, solicitação e contrato. No **Portal autenticado do cliente**, disponibilizar sua própria área/aba “Orçamentos” (ou subseção igualmente destacada e acessível), mostrando **apenas orçamentos e informações de sua organização**, com leitura, aceite, recusa e acompanhamento do pagamento. Duas interfaces/visões da **mesma fonte canônica** de dados, sem dois cadastros divergentes.

### 2.1. Visão administrativa Imagine

- Listagem pesquisável e filtrável por cliente, empresa, Onepage/Landing Page, site, protocolo, data, período, responsável, etapa, valor e situação de aceite e pagamento.
- Criar/originar orçamento a partir de solicitação autenticada no Portal; registro independente apenas se vinculado a uma solicitação e cliente autorizados, evitando lançamento solto sem trilha.
- Visualizar e editar minutas antes de aprovação, escopo e itens discriminados, propostas de agentes, custos/estimativas internos restritos, anexos privados, versões, observações e pendências; separar itens já incluídos na mensalidade.
- Encaminhar à **validação comercial humana Imagine**; identificar quem revisou/aprovou preço, descontos, condições e data/hora; restringir por papel/alçada e impedir agente de IA de autorizar valor/desconto.
- Publicar **proposta formal ao cliente** com número/versão, prazo de validade e condições claras; permitir nova versão controlada sem alterar silenciosamente a versão ofertada ou já aceita.
- Acompanhar retorno e aceite/recusa, condição financeira, cobrança conciliada, emissão fiscal quando aplicável, execução/entrega e pendências; gerar indicadores de funil de orçamentos, conversão, valores emitidos, aprovados, em aberto e efetivamente recebidos, **sem confundir previsão com receita**.
- Registrar trilha de auditoria por evento (autor, momento, versão, alteração de campos críticos, permissão e origem). A IA poderá sinalizar atrasos/pendências, não disparar despesas ou alterar status financeiro sem confirmação.

### 2.2. Visão do cliente

- Lista própria de propostas com site, finalidade, número, valor total, condições, prazo de validade e situação real.
- Acesso protegido à **versão integral da proposta**, escopo e entregáveis, itens incluídos/excluídos, prazo estimado, condições de parcelamento quando houver e eventuais anexos, sem exposição de custos internos Imagine.
- Solicitar esclarecimentos, **aceitar ou recusar expressamente** orçamento vigente por interface autenticada, com data/hora, identidade, versão/hash e evidência preservada. Propostas expiradas, substituídas ou alteradas exigem revisão e novo aceite.
- Após o aceite, visualizar e **autorizar pagamento pelo Portal**, com cobrança/links de pagamento efetivamente gerados e validados no sistema financeiro autorizado, faturas e recibos/notas segundo suas regras; não afirmar “pago” apenas porque o cliente aceitou ou iniciou o checkout.
- Acompanhar status real de pagamento e execução, materiais e prévias, e realizar **aprovação separada da versão exata do site** antes da publicação.

## 3. Estados e guardrails

Estados **sugeridos para refinamento** (não representam tabelas ou funcionalidades já implementadas): **solicitado → em análise → minuta de orçamento → aguardando validação Imagine → disponível ao cliente → aceito / recusado / expirado / substituído → aguardando pagamento / pagamento parcial / pago / pagamento falhou → autorizado a executar → em execução → prévia → aguardando aprovação de versão → publicado / concluído / cancelado**. Status de orçamento, aceite contratual, pagamento e produção devem ser eixos distintos e consistentes, para impedir que um único campo misture diferentes fatos.

Obrigatório distinguir:
1. **Contrato geral do site:** aceito expressamente na contratação da assinatura.
2. **Orçamento de serviço adicional:** validado por humano Imagine e aceito pelo cliente na versão/valor exatos.
3. **Autorização e liquidação de pagamento:** por meio do Portal, conforme condições expressas; nenhuma cobrança automática por mera existência de orçamento.
4. **Aprovação da publicação:** aceite prévio do cliente para o artefato/versão exatos, posterior à produção e QA, não suprido por contrato nem orçamento.
5. **Mudanças de escopo:** requerem reorçamento e novo aceite quando afetarem preço, prazo ou condições relevantes.

Controle de tenancy/organização, autenticação/MFA quando exigida, perfis de Administrador e Financeiro com alçadas distintas, segredo de pagamentos, auditoria protegida, idempotência e reconciliação, proteção de anexos, LGPD, retenção de evidências, trilha de contestação e impedimento de cobrança/publicação não autorizada são requisitos de segurança.

## 4. Integrações sem criar segundo sistema financeiro

- **Pedido/manutenção/central de evolução:** única origem e histórico da solicitação; proposta vinculada ao mesmo registro.
- **Financeiro do Portal Imagine:** fontes de verdade para cobranças, parcelas, estados pagos, estornos, assinatura e recibos/fiscal, usando identificadores seguros, sem duplicar lançamentos.
- **Agentes de IA/n8n:** triagem, rascunho, organização dos materiais, alertas e comunicação, sem alçadas para preço definitivo, descontos, pagamentos, contratos, notas fiscais ou publicação por conta própria.
- **CRM Básico Cortesia:** não constitui receita da Imagine CRM nem assinatura adicional; extras de **sites** são contratados e cobrados pelo Portal, separados de planos e adicionais do CRM.
- **Comunicações:** notificações por e-mail somente quando autorizadas e seguras, com link autenticado para a aba de Orçamentos; nenhum aceite ou pagamento por mera resposta ao e-mail.

## 5. Contratos e pendências

Incluir cláusulas do fluxo de orçamento, validação Imagine, aceite, parcelamento quando admitido, condições de entrada, início/pausas da execução, prazos, cancelamento, estorno, impostos, direitos de contestação e prova do aceite em **contratos completos de Onepage e Landing Page**, a serem redigidos, revisados juridicamente e submetidos à aprovação final antes da comercialização. A aba exclusiva não implica autorização de operação financeira sem homologação.

**Ainda pendentes de decisão:** critério para classificar serviço pequeno/grande; meios de pagamento disponíveis para extras e regras de parcelamento, eventual entrada mínima e prazos; prazo de validade de orçamento e SLA de preparação/validação; preços de serviços extras, desconto/alçadas e margem; política de alteração/cancelamento/reembolso e emissão fiscal; matrizes de permissões e design definitivo da aba, indicadores, retenção, UX e tratamento de contingências de rede/pagamento. **Não inventar taxas, limites ou valores.**

**Status:** planejamento e documentação aprovados; **nenhuma programação, nova aba criada na interface real, contratação, emissão de cobrança ou nota, ativação de agentes, deploy ou publicação**.
