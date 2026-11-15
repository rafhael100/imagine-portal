# F428 — conferência de extratos de mídia (dados sintéticos, NO-BILLING)

## A finalidade desta etapa

A apuração Performance precisa distinguir **valor informado no relatório** de
**gasto efetivamente comprovado em uma conta de anúncios autorizada**. Esta etapa
cria uma função PHP de reconciliação **pura** com dados fictícios, apenas como
teste do contrato de dados e das decisões negativas. Não afirma que a Google ou
Meta já esteja conectada.

## Contrato proposto para os adaptadores

Cada conector autorizado deve fornecer por empresa/pedido/canal:

- Referência da conta de mídia validada por IAM e consentimento;
- Competência em `America/Sao_Paulo`, moeda BRL, identificador único de linha,
  data, gasto normalizado em **centavos inteiros** e prova de relatório completo;
- Identificador da fonte oficial, período, versão, status, timestamp e hash do
  material **obtidos diretamente da plataforma** por servidor, nunca do browser;
- Metadados sobre reembolsos, crédito, diferenças de fusos, impostos e status
  de suspensão: qualquer dado ausente ou ambíguo exige intervenção humana.

A rotina F428 valida a forma canônica **offline**, exige mês encerrado,
proíbe duplicatas e dados fora da competência, restringe contas ao mapa de
titularidade confiável e marca divergências monetárias/ausência de mídia para
revisão. O digest SHA dos dados fictícios só detecta mudanças: **não comprova
autenticidade nem substitui assinatura ou consulta à plataforma**.

## Regras inegociáveis

1. **Nunca faturar sobre estimativa de orçamento contratado.** Usar apenas
   extrato oficial verificado, com acordo e empresa correspondentes.
2. Extrato incompleto, canal suspenso, zero mídia, divergência ou falha de API:
   suspender automatismo financeiro e registrar motivo.
3. Ativação R$490/canal é uma rubrica separada. A gestão mensal para período
   completo segue maior de 20% de mídia verificada ou R$170 por canal; mês
   parcial ainda requer ratificação contratual.
4. C6 e Asaas não são acessados aqui. Nenhum `can_charge`,
   `can_issue_invoice`, `can_write_psp` ou `can_activate_ads` pode ser true.
5. Este artefato não substitui F424/F425/F427, os gates independentes
   AppSec/DBA/LGPD, configuração Leaseweb ou GO operacional.

**Estado:** DRAFT; NO-MERGE / NO-DEPLOY / NO-REAL-DATA. PR vinculada a #802.
