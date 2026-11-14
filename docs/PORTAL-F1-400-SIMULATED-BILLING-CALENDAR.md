# F1-400 — calendário de faturamento apenas indicativo no Imagine Portal

**Estado: DRAFT / NO-GO para cobrança, assinatura e produção.**

## O que está implementado

A tela do pedido de homologação mostra um **calendário ilustrativo** gerado exclusivamente pelo snapshot de preço salvo pelo servidor ao criar o pedido. Não lê valores enviados pelo navegador, não inventa preço para pedidos legados e não cria registros financeiros, assinaturas, notificações, jobs ou cobranças Asaas.

- **Onepage:** implantação R$990 em 3 × R$330 (D0/D30/D60); mensalidade R$79,90 prevista a partir de D90.
- **Landing Page:** implantação R$1.290 em 3 × R$430 (D0/D30/D60); mensalidade R$79,90 prevista a partir de D90.
- **Performance:** somente estimativa de honorários (20% da mídia prevista; R$700 de verba publicitária mínima por canal). O valor não herda D90 dos sites. A mídia é paga diretamente aos canais e o acerto de gestão exige gasto real comprovado.
- Apenas as três **primeiras** mensalidades de exemplo aparecem (D90 e duas datas mensais seguintes). Não há mecanismo de geração automática de faturas.
- A data D0 do demonstrativo usa a confirmação da **simulação** salva em `offer_accepted_at`, com fuso `America/Sao_Paulo`. **Essa data NÃO substitui aceite de contrato jurídico**. A futura data efetiva deverá ser calculada a partir de um aceite contratual autenticado, datado e auditável, inclusive para reconhecer o vencimento da assinatura.
- Datas mensais de exemplo preservam o dia do primeiro mês e limitam o dia ao final de fevereiro/meses curtos (31 de janeiro ⇒ 28/29 de fevereiro, sem deslocar para março).
- O snapshot deve ter moeda BRL, gateway Asaas, `real_charge_enabled=false`, produto correto, três parcelas exatas D0/D30/D60 com total conciliado e mensalidade contratual D90. Desvios bloqueiam a prévia. O histórico congelado não é reprecificado pela alteração posterior do catálogo.

## Evidências

`test_commerce.php` verifica valores, datas, ano bissexto, fuso, dados adulterados e bloqueio de cobrança automática; Chromium verifica calendário Onepage, Landing e Performance no navegador.

## O que falta para a operação comercial

Aceite de contrato real/consentimentos, identidade empresarial/MFA, data contratual inequívoca, tributos/NFS-e, agendamento e recorrência Asaas Sandbox, recuperação de falhas e cancelamentos, reconciliação financeira após estorno, recibos idempotentes, plano LGPD, homologação com banco e segredos no Leaseweb e testes de restore/rollback em staging.

**O calendário é informativo, não gera dívida ou autorização para o PSP.** Não realizar deploy ou merge com este recorte isolado.
