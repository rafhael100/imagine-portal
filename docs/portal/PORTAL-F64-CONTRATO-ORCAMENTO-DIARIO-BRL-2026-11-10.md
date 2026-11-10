# Portal F64 — prévia estrita de orçamento BRL com centavos e dias exatos

Data: 10/11/2026. Base Portal F63; consumidor do contrato real do Performance F107.

A classe **CredentialBoundBudgetPreview** é um validador de apresentação offline do DTO **perf.portal.credential-bound-budgets.synthetic.v1**. Verifica, sem depender do código Performance, versão e formato exatos do schema, referência scenario-demo-..., moeda BRL, datas canônicas e dias inclusivos (até 366), porcentagens inteiras em 10.000 basis points, centavos inteiros sem float e distribuição cumulativa Google/Meta em cada dia. Toda a saída é rejeitada se qualquer conta for diferente ou se for adicionada coluna inesperada, token, permissão real, cobrança, operação de mídia ou aprovação de anunciante.

Não recebe tenant_id e não supõe que credential_binding_confirmed=true seja autenticação do usuário Portal; canExecuteMedia(), canBill(), hasHumanApproval() e isAuthenticatedPortalSession() retornam false sempre. Execução restrita ao CLI APP_ENV=testing com flag de laboratório.

## Evidência

[GitHub Actions Portal F64 #42838088432 — SUCCESS](https://github.com/rafhael100/imagine-portal/actions/runs/42838088432). O PHP 8.4 executou **60 verificações F64**, incluindo orçamento de R$100,01 de 28/02 a 01/03 de 2028 (29/02 bissexto), reconciliação de centavos 55/45, alterações fraudulentas dos valores/dias/flags e bloqueio de produção. A regressão Portal F63 do painel HTML também passou.

O teste cruzado com JSON produzido em PostgreSQL descartável é parte da etapa Performance F108, que fixa o commit deste Portal; não depende exclusivamente de fixture.

**NO-GO:** não existe página comercial, login de cliente, autorização de anunciante, OAuth Google/Meta, veiculação ou cobrança. Falta segurança AppSec/DBA, Portal IAM/MFA/S2S, LGPD, orçamento autorizado e infraestrutura/homologação. Main e produção inalteradas.
