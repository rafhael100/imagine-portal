# F1-425 — envelope assinado para revisão mensal Performance (somente laboratório)

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-INVOICE / NO-MONEY.**

## O que foi implementado

A F424 [#825](https://github.com/rafhael100/imagine-portal/pull/825)
calcula exclusivamente **candidatos informativos** da gestão pós-paga
para meses civis encerrados. Os contratos novos aprovados seguem:

- Ativação: **R$490 por canal** uma única vez, separada da gestão.
- Gestão de mês completo: **20% do investimento efetivo ou R$170 por
  canal**, prevalecendo o maior valor em cada canal ativo.
- Mídia paga diretamente à plataforma.
- Primeiro mês parcial, suspensão e competência sem gasto requerem
  política e revisão adicionais. Sem lançamento financeiro automático.

A F425 adiciona `PerformanceMonthlyCloseReviewEnvelope`, um contrato
HMAC-SHA256 de **revisão, não de execução**. O payload fica vinculado a:

- empresa (`tenant_ref`) e pedido congelado (`order_ref`);
- versão específica do relatório conferido pelo cliente;
- SHA-256 do relatório originalmente revisado;
- SHA-256 do candidato F424 completo (valor, competência, data sugerida,
  política, trava de faturamento), além de valor e mês repetidos para
  inspeção sem expor dados pessoais;
- data UTC de emissão e vencimento em **15 minutos**, domínio
  criptográfico exclusivo da revisão de gestão.

`verify` exige exatamente os mesmos valores de origem e escopo,
confere a janela de validade e compara assinaturas em tempo constante.
Candidatos com autorização de emissão, autorização PSP, campos extras,
mês inválido, valor falsificado ou política diferente são recusados.

## O que este envelope NÃO comprova

Uma assinatura HMAC não é consentimento, autenticação, comprovação
de gasto com mídia, comprovante fiscal, autorização de débito, nem
garantia de uso único. A assinatura pode ser reapresentada enquanto
válida. A prova testa explicitamente essa limitação.

**Antes de qualquer transmissão a CRM, gateway ou faturamento:**
- obter relatório e contrato de banco confiável com RLS, sessão MFA,
  titularidade e revisões congeladas;
- comprovar ativação com recibo único, mídia efetiva pelos extratos
  dos canais, competência fechada e aceitação contratual;
- persistir consumo único + idempotência por empresa/pedido/mês/
  versão e trilhas de auditoria sob transação de banco;
- validar regras de mês parcial, sem gasto, vencimento, contestação,
  créditos, NFS-e e revisão AppSec/DBA/LGPD/financeiro;
- homologar PSP e segurança na infraestrutura sem credenciais no
  repositório, backup/restore, rollback e GO formal.

## Como testar

`php apps/portal/tools/test_performance_monthly_signed_review_f425.php`

A CI executa PHP 8.3 e 8.4, apenas dados sintéticos, sem rede, banco,
serviços terceiros ou arquivos de segredos. Nem `issue` nem `verify`
chamam Asaas/C6 ou autorizam um boleto.

O estado comercial de **R$170 por canal** já foi aprovado e
não constitui pendência; o primeiro mês parcial ainda é pendente.
