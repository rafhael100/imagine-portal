# Imagine Portal — auditoria da jornada Comercial → Portal (F426–F431)
**Corte:** 15/11/2026. **Estado:** candidato Laravel / homologação privada, PRs abertas em DRAFT e **NO-GO para produção**. Este relatório distingue a base Node legada documentada no README e as branches Laravel em `apps/portal/`, sem declarar que estão publicadas na `main`.

## 1. Funcionalidades efetivamente encontradas

| Frente | Fonte GitHub | Implementado em branch de revisão | Não implementado ou não autorizado |
|---|---|---|---|
| Handoff Comercial→Portal | [PR #828](https://github.com/rafhael100/imagine-portal/pull/828), F426 | GET com 3 enums exatos, validação no PHP, recálculo pelo catálogo, seleção guardada em sessão e preservada após login/cadastro de teste | Carrinho real multi-item, pagamento conjunto e liberação pública |
| Resumo de escolha | `CommercialSelectionHandoff.php` e `customer-purchase.blade.php` | Separação do site e Performance, mídia planejada e gestão estimada, explicação de valores e limite financeiro | Não cria pedido por GET; cada item precisa aceite próprio |
| Autenticação de teste | `CustomerPreviewController.php`, `FullPortalPreviewController.php` | Revalidação dos 3 enums **após invalidação/rotação de sessão**; sem preservar sessão antiga bruta | SSO operacional, MFA real e onboarding de cliente produtivo |
| Interface de login | [PR #838](https://github.com/rafhael100/imagine-portal/pull/838) | Novo login amplo com marca, painel explicativo e botão Voltar, sem mudar `POST`, CSRF ou rate-limit; verificação de servidor na VPS dev | QA visual autenticado independente e deploy de produção |
| Catálogo de compra | `ApprovedOfferCatalog.php`, `approved-catalog.json` | Preços vinculados à versão SHA do catálogo no servidor; Google/Meta por canal e mínimo de mídia; `real_charge_enabled=false` | Preço informado por query string, ativação ChatGPT, parcelamento conjunto financeiro |
| Simulação de PSP | PR [#757](https://github.com/rafhael100/imagine-portal/pull/757), linha F423–F425 | Banco e Asaas **sandbox** isolados com teste de reconciliação; ativação de Performance e fatura mensal fictícias | C6, pagamentos reais, NFS-e, mídia oficial e liberação financeira |
| Financeiro mensal complementar | PRs [#832](https://github.com/rafhael100/imagine-portal/pull/832) → [#835](https://github.com/rafhael100/imagine-portal/pull/835) | F427 anti-replay PG18, F428 conciliação de extratos **sintéticos**, F429 proposta de mês parcial e F430 revisão assinada de uso único em laboratório | Extratos oficiais, regra contratual ratificada de mês parcial, emissão real |
| Segurança/consentimento V7 | PRs [#829](https://github.com/rafhael100/imagine-portal/pull/829), [#831](https://github.com/rafhael100/imagine-portal/pull/831), [#836](https://github.com/rafhael100/imagine-portal/pull/836) | Provas SQL/PG18 de sessão, MFA e aceite fictícios, revogação e recibo de apresentação simulados | Não substituem login de produção, UI real de contrato, aceite jurídico ou auditoria independente |

## 2. Protocolo F426 — dados aceitos e validações

Exatamente três chaves de query de **homologação privada**:
- `site`: `none` / `onepage` / `landing-pages`;
- `channels`: vazio, `google_ads`, `meta_ads` ou `google_ads,meta_ads` (ordem canônica);
- `tier`: `minimum` / `recommended`.

Há **22 combinações não vazias** aceitas; a seleção vazia é rejeitada. Campos extras como `price`, `amount_cents`, `customer_id`, `product`, `token`, serviços/canais desconhecidos, duplicados ou strings fora do contrato recebem rejeição (422 no preview HTTP). A rota não cria pedido, cobrança, contrato ou assinatura. Nenhum orçamento financeiro do navegador é aceito: a classe `CommercialSelectionHandoff` chama `ApprovedOfferCatalog::quote()` e usa o catálogo revisado do servidor.

A sessão do Portal é rotacionada no login por segurança. F426 captura e valida os **três enums apenas** antes da rotação e regrava a forma canônica depois, preservando a intenção de compra sem reusar token CSRF, IDs anteriores ou sessão bruta. No cadastro, o mesmo princípio vale apenas para dados sintéticos de homologação. O HTML do checkout privado é `no-store`, `noindex`, `no-referrer`.

## 3. Reconciliação financeira com o pedido de 3x sem juros

| Rubrica | Catálogo Portal V5 em homologação | Apresentação Comercial/D01 mais recente |
|---|---|---|
| Implantação Onepage | R$ 990 em D0/D30/D60, primeira parcela de R$ 330 | Integrada na proposta **3x sem juros** |
| Implantação Landing | R$ 1.290 em D0/D30/D60, primeira parcela de R$ 430 | Integrada na proposta 3x |
| Ativação Google/Meta | **R$ 490/canal, exigível no primeiro pedido Performance** | Somada à implantação e repartida em três parcelas **somente na simulação** |
| Gestão mensal | `max(20% de mídia real, R$ 170/canal)` pós-paga | Valor indicativo sobre mídia planejada, não cobravel automaticamente |
| Mídia | Paga fora do Portal às plataformas | Não entra no parcelamento da Imagine |
| Mensalidade do site | R$ 79,90 a partir do dia 90 do contrato | D+90, não relacionado à data da publicação |

**Exemplo de divergência que deve permanecer explícita até aprovação:**
Onepage + Google Ads + Meta Ads:
- Implantação total **R$ 990** + ativações **R$ 980** = **R$ 1.970**;
- **Nova apresentação comercial ilustrativa:** R$ 656,66 / R$ 656,66 / R$ 656,68 (soma exata, 3x sem juros);
- **Fluxo Portal V5 atual:** o pedido Onepage tem primeira parcela de **R$ 330** e o pedido Performance exige **R$ 980 de ativação**, somando **R$ 1.310 em obrigações iniciais distintas**. Isso **não** é o mesmo plano de 3x, nem checkout combinado;
- O campo de F426 `sum_of_separate_first_payments_cents` é **apenas comparativo** e **não deve ser exibido como a primeira parcela da nova proposta**.

**Gate financeiro pendente e obrigatório:** antes de transformar a oferta de 3x em cobrança, alinhar Jurídico/Financeiro, update versionado de catálogo, escopo exato dos itens, calendário D0/D30/D60 para ativação, meios de pagamento Asaas/C6, custos e limites de parcelamento, conciliação por item, tributação/NFS-e, emissão de faturas, vencimento, desistência, inadimplência, reembolso, recuperação após falha e retenção. Exigir alteração dos testes E2E e revisão independente, sem reescrever ordens legadas. **Não afrouxar** CSRF, RLS, rate limits, constraints `UNIQUE` nem validação de webhooks.

## 4. Gate de disponibilidade de canais

A simulação Comercial D01 passou a mostrar **ChatGPT Ads** com mínimo de planejamento para 30 dias. O Portal F426 **não** aceita `chatgpt_ads`; só Google/Meta são válidos nos parâmetros de compra privada. A D01 pode demonstrar ChatGPT com dados fictícios, mas não há checkout ChatGPT operacional e não se pode aceitar a seleção e silenciosamente descartá-la. Até homologação específica, o Portal deve rejeitar. Site completo e site por assinatura continuam sob proposta separada.

## 5. Ramos paralelos e regressões

- O Comercial tem caminhos diferentes: F327/PR #411 bloqueia escolha combinada no gate antigo; F325-B/PR #409 encaminha a escolha não financeira; F426/PR #828 recebe a combinação e devolve **dois pedidos distintos**, não um aceite transacional único. A D01/PR #412 oferece um simulador privado próprio, não uma transação final do Portal. Nenhum desses caminhos equivale à liberação de checkout em produção.
- A PR #838 parte da branch F426 e **somente** altera o login visual. Não deve ser usada como prova automática de que os valores e gates de #828 foram homologados.
- A suíte F426 em Chromium provou o novo login/cadastro e a seleção por três enums, mas a regressão completa da PR #828 em commits anteriores foi interrompida por fixtures antigos de pagamento sintético. O código de teste foi ajustado para não reusar o mesmo `customer_ref`, preservando as regras de banco. A última HEAD registrada da PR #828 (`b8047fc2ada4c062c26651c159c3852467ff1174`) **não tinha nova evidência de workflow na API consultada**. Não declarar regressão integral verde.
- O acesso à UI de login da PR #838 no ambiente de desenvolvimento foi validado por verificação HTTP/Blade segundo a descrição do PR, mas QA visual autenticado independente continua pendente. A identidade visual deve ser conferida com as cores Imagine aprovadas (laranja `#F9941D`); a presença de uma faixa verde-escura no login deve passar pela revisão editorial.

## 6. Checklist integrado antes de habilitar compra

- [ ] Consolidar versões comerciais F322–F329 e preservar explicitamente todos os canais e serviços selecionados.
- [ ] Validar **uma** rota de continuação pós-cadastro, reentrada e refresh com identidades reais autorizadas e isolamento de tenant.
- [ ] Especificar pedido combinado/aceites por serviço ou carrinho com atomicidade e idempotência (issue [#830](https://github.com/rafhael100/imagine-portal/issues/830)).
- [ ] Aprovar **3x sem juros para ativações** com novo catálogo e calendário; provar centavos exatos, estornos e prestação de contas.
- [ ] Confirmar mídia efetiva com extratos oficiais Google/Meta, competência, revisão cliente, contestação e regra do primeiro mês parcial.
- [ ] Homologar PSP Asaas/C6, webhooks autenticados, NFS-e, conciliação, cancelamento, retry, vazamentos de dados e antifraude.
- [ ] Validar consentimento, sessão/MFA, CSRF, autorização empresa, RLS, rate-limit, auditoria e retenção LGPD com revisor independente.
- [ ] Executar CI/Chromium na HEAD exata integrada, testes de backup/restore/rollback na Leaseweb, revisão visual PT/EN/ES e autorização manual de go-live.

**Status:** documentação de lacunas; **DRAFT / NO-MERGE / NO-DEPLOY / NO-REAL-CHARGE / NO-ADS-WRITE**. As branches de laboratório não são equivalentes a integrações produtivas.
