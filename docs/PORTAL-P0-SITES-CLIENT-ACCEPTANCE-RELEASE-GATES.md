# Imagine Portal P0 — aceite editorial explícito antes de publicar Sites

## Candidata consolidada e auditoria

A base é a [PR #753](https://github.com/rafhael100/imagine-portal/pull/753), que contém checkout comercial Google/Meta, orçamentos assinados por cliente, implantação Asaas fictícia, fila PSP e teste de restore PostgreSQL18. Esta revisão incorpora os **sete arquivos originais** de aceite explícito da [PR #749](https://github.com/rafhael100/imagine-portal/pull/749), commit `10fc419e73236fc8c42acb0eb361114c8e67d03b`, sem sobrescrever arquivos pré-existentes.

O emissor `PortalEd25519ClientAcceptanceIssuer` tem fonte de consentimento atual `default-deny` e somente pode emitir um comprovante quando há, simultaneamente, identidade de cliente com MFA verificada, aceite explícito, registro vigente, mesma empresa/site/release, mesmo hash SHA-256 do artefato e revisão editorial idêntica. Não usa `POST` como fonte do estado de autorização.

O teste da F394 usa a classe/verificador **original do Imagine Sites**, fixado em `1bb80823a112cd935f4498e36ad9e0da853f851f`, para verificar bytes canônicos e assinatura Ed25519, expiração, revogação e testemunha independente. O comprovante não é autorização de publicação sozinho: o Sites deve reconsultar aprovação/revogação e validar direitos de operação.

## Limite da integração

A implementação é uma biblioteca sem endpoint, segredo, identidade ou testemunha operacional registrada. O fluxo de teste de aceite no navegador do Portal é fictício e **não gera automaticamente esse comprovante**; nenhuma aprovação real ou publicação de site é feita. Falta fonte operacional de IAM/MFA, aceite armazenado com integridade e trilha auditável, revogação, assinatura por chaves controladas, vínculo ao artefato gerado/publisher, testes na VPS e conformidade contratual.

A importação por SHA Git não equivale ao merge do histórico de #749. A CI deve passar na HEAD exata do candidato e não somente na PR original. Todas as operações financeiras permanecem em laboratório.

**DRAFT / NO-GO / NO DEPLOY / NO MERGE / NO CHARGE / NO PUBLISH.**
