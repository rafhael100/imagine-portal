# F1-423 — ativação de Performance em Asaas sandbox com proprietário verificado

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-PRODUCTION / NO-REAL-MONEY.**

## Regra financeira preservada

Este recorte avança a linha integrada do Portal da F422 para **apenas
uma intenção sintética de ativação**, recalculada no servidor, destinada
ao Asaas **sandbox** em uma base PostgreSQL18 descartável.

- **Ativação**: R$490 por canal selecionado. Meta (Instagram+Facebook)
  conta como um só; Google Ads conta como outro.
- **Mídia paga diretamente às plataformas**: mínimo de orçamento
  Meta R$700/mês e Google R$1.000/mês; não se transforma em valor
  de cobrança na Imagine.
- **Gestão mensal**: 20% do gasto de mídia efetivamente comprovado,
  mínimo de R$170 por canal **em mês completo**, apuração posterior.
  Nenhum lançamento mensal é permitido nesta PR. Mês inicial parcial,
  sem gasto, cancelamento e vencimento ainda exigem análise contratual.
- ChatGPT Ads segue explicitamente **não elegível** no checkout.
- Um contrato de antecipação antigo não recebe ativação retroativa
  nem vira contrato V5.

## Fronteiras de segurança mantidas

1. `SandboxCheckoutIntent::request` continua sob transação
   `CustomerPreviewStore::withVerifiedScope`, HMAC individual,
   autorização por cliente e bloqueio da linha do pedido. A interface
   **não pode especificar centavos, PSP ou versão de tarifa**.
2. Somente Onepage (como antes) e Performance **V5 versionada**
   podem criar um `sandbox_payment_intent`. Landing Page, Performance
   legada, despesas de mídia, gestão mensal e C6 são recusados.
3. Para Performance V5, o catálogo, o snapshot imutável e o total
   `activation_total_cents` precisam coincidir com o cálculo puro
   `PerformancePostpaidPolicy` e `ActivationPostpaidPreview`;
   invalidade de valor, canal, taxa, política ou elegibilidade falha
   antes do INSERT.
4. `sandbox-payment-schema.sql` mantém `ENABLE/FORCE RLS`,
   função HMAC no banco, login da aplicação restrito e papel PSP
   separado. A política do app só enxerga intenções cuja oferta e
   valor correspondem ao próprio cliente autenticado.
5. O trabalhador legado de sandbox permanece *unchanged*: chaves
   de referência/idempotência por pedido, pagador fictício, sem
   e-mail/telefone e sem notificações; confirmação apenas pela
   reconciliação com o provedor injetado, nunca por status de webhook.
6. A UI só permite botão de sandbox para Performance V5 e Onepage;
   nunca publica autorização HTTP para produção. O checkout em
   `main` ou VPS não é alterado.

## Teste de aceitação

`test_sandbox_performance_activation.py` usa Chromium, Laravel
e PostgreSQL18 descartáveis e o provedor **injetado**, sem conexão
com Asaas externo. Demonstra seleção Google R$1.000 + Meta R$700,
resumo R$980 de ativação + gestão estimada R$370, tentativa de
falsificar centavos no POST recusada, um único pedido de R$980
no ledger, duas chamadas simuladas ao fornecedor sem duplicação,
bloqueio do briefing enquanto pendente e liberação após
confirmação confiável. A suíte ainda executa Onepage, propriedade
do pedido, tentativas cruzadas entre clientes, relatórios e
reabertura após reiniciar o banco.

Os resultados serão aceitos somente na **HEAD exata**, com
todos os testes CI aplicáveis aprovados.

## Para chegar a ambiente real de homologação

- Aprovar tipologia e isolamento da VPS Leaseweb, HTTPS, OAuth,
  segredos bancários, backups e rollback; demonstrar que não
  existem rotas de produção apontando para sandboxes.
- Homologar criação, expiração, notificações desativadas, consulta
  assinada ao Asaas sandbox e conciliação por identidade/empresa.
- Habilitar separadamente contratos/aceite, NFS-e, provas de mídia
  e gestão mensal, com contestação e revisão financeira.
- Revisar regras RLS, scripts e logs por AppSec/DBA/LGPD.
- Autorizar deploy e cobranças explicitamente após todos os gates.

**CI verde, simulação de sandbox e assinatura HMAC de revisão NÃO
autorizam pagamento real, ativação de anúncios ou publicação do site.**
