# Imagine Portal P0 — Google/Meta + checkout + Asaas + restauração SQL assinada

## Linha candidata principal

A base é a [PR #751](https://github.com/rafhael100/imagine-portal/pull/751), que já consolidou canais comerciais `google_ads/meta_ads`, orçamento não preenchido antes do aceite do cliente, catálogo v4, checkout e painel cliente/admin, isolamento HMAC com `FORCE RLS` e Asaas F390/F391/F392 em dados fictícios. Os testes dessa HEAD foram registrados como **13/13 SUCCESS** antes da adição desta entrega.

Esta candidata importa por SHA Git os scripts de backup/restore e preflight do ensaio da [PR #752](https://github.com/rafhael100/imagine-portal/pull/752), **sem substituir os componentes de checkout/canal de #751**. A PR #752 foi montada sobre #748 e é mantida somente como fonte rastreável até que a integração nesta linha mais abrangente conclua sua CI.

## Verificações adicionadas

- Antes de iniciar a aplicação, executar preflight isolado de política de propriedade assinada no PostgreSQL18 descartável, garantindo que a role SQL não enumere identidades ou pedidos e não possa ler segredo HMAC.
- Executar `preflight_private_runtime.py --self-test` offline, verificando recusa de Compose com acesso público, root, porta aberta ou `APP_ENV` inadequado, sem acessar chaves, DNS ou VPS.
- Depois de Chromium realizar compras, login, orçamento Google/Meta, pós-venda e reinício, criar um banco **novo** no mesmo PostgreSQL descartável, transmitir backup personalizado `pg_dump -Fc` e restaurar com `pg_restore --exit-on-error`.
- Exigir contagens iguais de clientes, pedidos, briefings e eventos; `FORCE RLS` nas três tabelas e política de eventos com verificador assinado. Abrir a base restaurada **como role SQL limitada de verdade** e exigir zero identidades, pedidos e eventos sem MAC válido.
- Destruir a base de recuperação ao final; nenhuma conexão a Asaas real ou Leaseweb nem alteração no repositório `main`.

## Riscos e bloqueios

O ensaio usa dados fictícios no mesmo host do runner. Não prova backup físico, criptografia offsite, restauração na Leaseweb, monitoração, integridade de mídia ou RTO/RPO. Um workflow bem-sucedido não libera o fluxo de cobrança, Pix Automático, NF, contratos, IAM ou Sites publicação.

Há outras PRs para aceite editorial Ed25519 e origem da conta Asaas V3; sua inclusão no release depende de revisão específica e testes na HEAD exata. Não supor que todos os ancestrais tenham sido mesclados.

**Estado obrigatório: DRAFT / NO-GO / sem merge, deploy, pagamento ou publicação.**
