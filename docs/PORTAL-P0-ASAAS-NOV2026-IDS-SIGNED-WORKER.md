# Imagine Portal P0 — Asaas: compatibilidade de IDs de cobrança (novembro de 2026)

## Mudança oficial verificada

O Asaas comunicou em **5/11/2026** a alteração do formato de identificadores públicos de cobrança. A partir de **9/11/2026**, o Sandbox poderá retornar IDs com underscore adicional, como `pay_1_a1B2c3D4e5F6g7`, em coexistência com o formato antigo `pay_a1B2c3D4e5F6g7H8`. Em produção, a adoção gradual está prevista para **23–27/11/2026**. O identificador é **opaco**, não deve ser dividido por posição fixa ou convertido para um número.

Fonte oficial: https://docs.asaas.com/changelog/altera%C3%A7%C3%A3o-no-formato-dos-identificadores-de-cobran%C3%A7as

## Pontos corrigidos

- `AsaasReadOnlyPaymentClient`, `AsaasTrustedPaymentBinding` e `AsaasWebhookPgInbox` agora permitem underscore adicional no identificador sem permitir barras, parâmetros de URL, espaços, quebras de linha ou caracteres fora de um alfabeto seguro.
- A constraint do inbox PostgreSQL18 descartável acompanha a mesma regra; a inserção de um webhook permanece **somente** `pending_reconciliation`, nunca evidência de pagamento.
- `AsaasSandboxGateway` valida o ID opaco no GET da cobrança e no corpo recebido, sem inferir dados comerciais pelo formato. As formas antigas e novas são mantidas nos testes com transporte simulado.
- O `asaas_sandbox_worker.py` usa a mesma regra, com valor da cobrança e referências obrigatoriamente conferidos com o snapshot de pedido. Desativa proxies de ambiente no cliente Python do PSP para não encaminhar `access_token` a um intermediário configurado pela variável `HTTPS_PROXY`. Nenhuma URL arbitária ou redirect recebe credencial.
- A revisão incorpora o isolamento SQL entre `purchase_rehearsal_a` e `purchase_rehearsal_psp` recuperado da PR #762, sobre o candidato com provas Portal→Sites F395/F396 da PR #763. Os arquivos foram selecionados por SHA Git de origem e não alteram produção.

## Regressões

A CI verifica a HEAD exata com PHP8.3/8.4 e PostgreSQL18 real descartável: `F390` confronta o ID novo com contrato e resposta GET oficial simulada; `F391` armazena webhook de cobrança com o novo ID em transação RLS; `test_commerce.php` valida GET via transport mock, recusando `/`, `%2F` e query string; teste Python comprova os dois formatos, recusa IDs manipulados e ausência de proxy ambiental. A CI do navegador testa a primeira cobrança sintética vinculada a pedido, negando acesso a clientes de outra empresa.

## Restrições para liberação

Esta alteração **não** provisiona segredo, não cria nova cobrança externa, não altera a homologação existente da VPS, não dá poderes de produção ao PSP worker, não habilita entrega ou publicação e não promove as PRs base. A atividade Asaas Sandbox real relatada na PR #757 permanece uma evidência separada, que exigirá nova conciliação com esta versão antes de ser considerada release.

**DRAFT / NO-GO / NO MERGE / NO DEPLOY / NO CHARGE / NO PUBLISH** até confirmar CI na HEAD desta PR, migração e backup em staging privado, IAM/MFA, webhook real, contrato/parcelamento D0/D30/D60, Pix Automático D90 e testemunhas independentes do Sites.
