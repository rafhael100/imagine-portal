# GSC-F130 — Integração de todas as migrations de autorização, propriedade e cofre

## Lacuna corrigida

Até a F129, o CI verificava os contratos de permissão `seo.gsc.connect` (F113) e o esquema de propriedade/cofre (F123–F126) **em cadeias separadas**. Um código que passa em cada teste isolado pode falhar quando as migrations coexistem, por ordem de aplicação, privilégios ou dependências.

A F130 acrescenta a prova integrada em um **único PostgreSQL 18 descartável** com PHP 8.3/8.4:

1. Aplica a fundação de identidade do Portal F66 e o campo de revisão de empresa F76.
2. Aplica a migration de vocabulário de permissão F113: só acrescenta `seo.gsc.connect`, sem criar grant automático.
3. Aplica o cadastro por empresa F123, o trigger de revogação F124 e as referências de cofre F125.
4. Executa os testes originais F125 **antes** do trigger de integridade F126, para não alterar sua expectativa de SQLSTATE; depois instala F126 e valida seus bloqueios.
5. Verifica as quatro tabelas críticas sob `ENABLE/FORCE RLS`, ausência de policies de acesso às tabelas SEO, ausência de campos de tokens, triggers de imutabilidade e isolamento por empresa.
6. Insere um único grant **sintético explícito** `seo.gsc.connect` para membro sintético, confirmando que a constraint rejeita a permissão mais ampla `seo.gsc.manage`.
7. Testa que o rollback F126 falha enquanto restar referência; apaga exclusivamente fixtures artificiais; reverte F126 → F125 → F124 → F123. Confirma então que o rollback da F113 **continua bloqueado** enquanto existir o grant sintético, e só reverte F113 após sua revogação explícita. Reverte F76/F66 e verifica ausência de tabelas e roles de laboratório.

## Limites de produção

Não há nenhum token, KMS, OAuth Google real, autorização HTTP/MFA, concessão operacional, policy RLS para aplicação, criação de usuários reais nem rede externa. **Não considerar o CI um teste de homologação na VPS.**

O esquema continua em PRs draft empilhadas, sem migração aplicada fora do CI. A autorização de instalar migrations na produção exige parecer independente AppSec/DBA, backup, plano LGPD de retenção e recuperação, revisão da política de roles/GRANTs e homologação da infraestrutura Leaseweb. Toda a prova usa dados sintéticos e não gera despesas de fornecedores.
