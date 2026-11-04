# AUT-P10 — Alternativa B aprovada: autoridades financeiras, contratos e benefícios

**Aprovação contextual:** em 04/11/2026, resposta **“ok”** à pergunta direta “Você aprova a AUT-P10 pela alternativa B?”. A decisão ratifica a alternativa B da [proposta AUT-P10](P0-DOC03-AUT-P10-AUTORIDADES-FINANCEIRAS-ASSINATURAS-BENEFICIOS-PROPOSTA.md). É **aprovação normativa do planejamento**, não homologação de APIs, PSP, infraestrutura ou regras técnicas ainda pendentes.

## 1. Decisão ratificada — alternativa B

**Adotar fontes financeiras oficiais e conciliação auditável, com autoridades contratuais e operacionais independentes para Imagine Sites, Portal Imagine e CRM Imagine.**

1. **Fato financeiro:** banco, PSP ou adquirente comprovam o evento efetivo de liquidação, estorno, devolução, disputa ou pagamento; o **sistema financeiro oficial da Imagine** reconcilia esse evento ao contrato, título, competência, cliente e produto corretos. Webhook, HTTP 2xx, conclusão do n8n, pedido de pagamento ou comprovante isolado não autorizam declarar quitação sem confirmação apropriada.
2. **Estados separados:** pedido de cobrança, mandato/autorização do meio, tentativa de débito, processamento, liquidação/recebimento conciliado, resultado incerto, falha, estorno/contestação e regularização são fatos distintos. Um evento fora de ordem ou duplicado exige consulta/reconciliação oficial e **idempotência**, sem cobrança/baixa repetida.
3. **Contrato de site:** o Portal é autoridade da oferta/contrato aceitos, condições de implantação, campanhas, vencimentos e status comercial do site, baseando suas decisões em provas financeiras e contratuais válidas.
4. **Contrato CRM pago:** assinatura paga, upgrade e situação do CRM pertencem ao **próprio CRM** e a seus registros financeiros/contratuais. Suspensão ou cancelamento de site **não suspende nem cancela automaticamente CRM pago independente**.
5. **Cortesia CRM Básico:** benefício calculado por conta CRM e pelo **conjunto completo de sites/contratos elegíveis vinculados**. Uma conta recebe no máximo uma cortesia, mesmo com múltiplos sites; contas distintas podem ter benefícios próprios se sustentadas por vínculos elegíveis. Alterações reavaliam todos os fundamentos antes de bloquear ou restaurar.
6. **Inadimplência do site:** respeitar tolerância aprovada de **7 dias corridos antes de suspensão**, sem ignorar regularização até próximo dia útil bancário quando vencimento/tentativa falhar em dia não útil. Suspensão exige estado oficial reconciliado e regras contratuais; falha temporária de webhook, banco ou conciliação não basta para punir ou bloquear.
7. **CRM cortesia e site suspenso:** se o site suspenso era o **único fundamento** da cortesia, suspender operacionalmente o Básico da conta correta, preservando dados, direitos restritos e eventual assinatura CRM paga. Se outro site elegível permanece ativo, manter o benefício.
8. **Duas transições de 30 dias:** após **cancelamento comum do último site elegível**, CRM Básico cortesia permanece operacional durante 30 dias; se último site havia sido **suspenso por inadimplência e foi formalmente cancelado**, 30 dias de transição com **acesso operacional restrito**, mantendo portabilidade e opção de contratação. O marco é o cancelamento formal, não uma simples tentativa de cobrança ou suspensão.
9. **Fim de cortesia sem assinatura:** não iniciar pagamento recorrente CRM nem converter cortesia em plano pago sem **contratação/aceite expresso**. Preservar exportação, acesso restrito e prazos próprios da conta. A retenção geral de **120 dias após cancelamento formal da própria conta CRM** não começa na mera suspensão ou cancelamento do site.
10. **Política comercial vigente:** implantação/compra com **Pix à vista, 5% de desconto**, ou cartão em **até 3 vezes, sem esse desconto**; não oferecer Pix parcelado. Mensalidades de site com **Pix Automático, 5% de desconto**, ou cartão recorrente sem desconto Pix. Percentuais antigos de 10%/20% e cartão em 4x não prevalecem.
11. **Marcos de cobrança:** primeira mensalidade de site normalmente **90 dias após a contratação**, configurável por serviço/campanha e vinculada à oferta aceita. Vencimentos, calendário de dia não útil, prazo sem encargos e ciclos promocionais são distintos; não cobrar antecipadamente por suposição.
12. **Serviços adicionais:** até R$ 270 à vista; acima desse valor, à vista ou até 3x **somente no cartão** conforme condições aceitas. Liberar execução após aceite eletrônico e **confirmação financeira apropriada** do pagamento integral ou da primeira parcela conforme política de adicionais, nunca por intenção/comprovante não confirmado.
13. **Gates independentes:** pagamento conciliado e contrato elegível habilitam somente os efeitos comerciais autorizados. Produção, publicação, aceite da versão, segurança, privacidade, funcionalidades de formulário e evento **ENTREGUE** continuam sujeitos a AUT-P01–P09, COM-13 e contratos específicos. Site estático sem CRM pode ser publicado nas condições autorizadas, sem captação de leads.
14. **Ações da IA:** agentes podem consultar, diagnosticar e orquestrar operações permitidas, **mas não atestam pagamento por conta própria**, criam contratos/despesas, descontos fora da oferta, suspensão por inferência ou novas cobranças. Intervenções críticas exigem **uma única aprovação humana específica**, quando aplicável, conforme AUT-P09.
15. **Conciliação, auditoria e bloqueio seguro:** preservar referências oficiais de contrato/versão/título/competência, empresa/conta, evento e estado reconciliado, decisão de benefício/suspensão/reativação, contestação, responsáveis e revisão; evitar cruzamento de tenants, ações duplicadas e armazenar somente dados estritamente necessários.

## 2. Alternativas não adotadas

- **A:** tratar webhook, resposta técnica ou conclusão de workflow como prova final universal de quitação.
- **C:** usar um único estado financeiro “cliente regular” para todos os sites, cortesia e assinaturas CRM pagas.

## 3. Limites desta aprovação

**Ainda pendentes e NÃO HOMOLOGADOS:** PSP/bancos/adquirentes, contratos de eventos e APIs, esquemas, estados/enums definitivos, assinaturas técnicas, conciliação transacional, reversões, datas e marcos exatos, feriados, protocolos de autorização Pix, fiscal/NFS-e, preços finais, custos, retenções detalhadas, critérios de teste e mecanismos de implantação. A aprovação desta alternativa **não** autoriza cobrança, emissão de título, contratação de fornecedor, alteração de assinatura ou ação em produção.

## 4. Status documental consolidado

**AUT-P01 a AUT-P10: todas as dez alternativas B aprovadas normativamente.** Isso **encerra as dez deliberações AUT**; **NÃO** fecha automaticamente **P0-DOC03** nem **RF-PEND-01**. **RF-PEND-04 segue NÃO HOMOLOGADA**. **AGI-11-RB01, rollback automático, permanece DESABILITADO**. Programação, testes, homologação, implantação, pagamentos reais e mudanças de infraestrutura continuam não autorizados.

## 5. Referências

- [Proposta histórica AUT-P10](P0-DOC03-AUT-P10-AUTORIDADES-FINANCEIRAS-ASSINATURAS-BENEFICIOS-PROPOSTA.md).
- [Política vigente Pix 5% / cartão 3x](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md).
- [COM-06 — primeira mensalidade](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-06-inicio-mensalidade-configuravel-por-campanha.md).
- [COM-07 — vencimento/dia útil](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-07-vencimentos-recorrentes-configuraveis.md).
- [COM-08 — inadimplência e cortesia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-08-inadimplencia-site-cortesia-crm-configuravel.md).
- [COM-09 — transições da cortesia](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-09-transicao-cortesia-cancelamento-inadimplencia.md).
- [IN-119 e IN-122 — elegibilidade e múltiplos vínculos](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-119-gestao-cortesia-crm-basico.md).
- [AUT-P09 — alçadas de agentes](AUT-P09-B-aprovada-2026-11-04.md).
