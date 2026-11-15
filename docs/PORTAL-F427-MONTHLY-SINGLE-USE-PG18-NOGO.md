# F427 — revisão pós-paga com consumo único (PostgreSQL18, somente laboratório)

## Por que esta entrega existe

A assinatura F425 protege o conteúdo de uma revisão mensal, mas **não impede replay**. Esta etapa prova, em uma base PostgreSQL18 descartável e isolada da rede, a persistência de uma única transição `issued → consumed` por envelope e por pedido/competência. Ela não libera cobrança.

## Escopo do laboratório

- Banco **obrigatoriamente** chamado `portal_purchase_rehearsal`; scripts recusam os demais bancos. Não executar na VPS, staging ou produção.
- Função `consume_review` exige correspondência exata de MAC, fingerprint, empresa, pedido, versão do relatório, SHA do relatório, SHA do candidato, mês fechado e centavos. Somente o login restrito `perf_monthly_f427_worker` pode chamá-la; esse login não tem SELECT/UPDATE direto na tabela.
- `FORCE ROW LEVEL SECURITY`; viewer limitado à empresa por MAC do contexto controlado; issuer restrito à emissão de mês fechado e TTL de no máximo 15 minutos.
- Índice único parcial impede emissão concorrente de revisões ativas/consumidas para o mesmo pedido/mês. Revisão **revogada** pode ser substituída; revisão **consumida** não.
- Expiração, replay, tentativas de adulterar canal pelo SHA, empresa, valor, período, revisão e estado revogado falham. A CI usa duas conexões concorrentes para provar consumo único.
- Funções retornam `boolean` de controle técnico, **nunca autorização** para `charge`, NFS-e, ativação de campanhas, débito ou escrita no Asaas/C6.
- O MAC persistido é só uma correspondência opaca: a verificação criptográfica do envelope F425, aceite contratual, prova de extrato oficial, identidade/MFA e evidência de data de ativação precisam acontecer **antes** de uma futura chamada integrada.

## Gates ainda abertos

1. Reconciliar extratos reais de mídia e fontes autorizadas por conta; normalizar competência, timezone, moeda, reembolsos e divergências.
2. Ratificar regra contratual do primeiro mês parcial, suspensão e ausência de mídia; sem isso F424 bloqueia fatura parcial.
3. Integrar uma fonte real de identidade/credenciais e um executor transacional não-superusuário, com migração segura própria. SQL F427 é um **padrão de laboratório**, não uma migration.
4. Validar PSP Asaas/C6 com sandbox autenticado, webhooks, idempotência e reconciliação. Nada deste PR acessa PSP.
5. Revisão independente AppSec/DBA/LGPD e teste de staging com backup/restauração e rollback na VPS, seguidos de GO formal.

**Status:** DRAFT, NO-MERGE, NO-DEPLOY, NO-INVOICE, NO-BILLING. Baseado em F425 #827, que por sua vez depende de F424 #825. Sem custos adicionais.
