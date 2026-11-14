# Imagine P0 — candidata única Checkout + Asaas + Pós-venda (14/11/2026)

## Reprodutibilidade da integração

A branch atual tem como ancestral **exato** o checkout/pós-venda da [PR #744](https://github.com/rafhael100/imagine-portal/pull/744), `b12d518f86827f31bfd6308735dca8f986f43adc`. Foram incorporados **50 blobs de código, testes, workflows e documentação** do commit `e3eceb6336ae590479de749f7cb8ca29e5a42241` da [PR #743](https://github.com/rafhael100/imagine-portal/pull/743), usando as SHA Git originais para os arquivos, sem sobreposição com os arquivos já presentes na #744.

**Isso é cópia de código pinada por SHA, não merge do histórico Git nem promoção de PR anterior.** Revisão independente de ancestralidade, dependências e segurança continua obrigatória.

## O que estará na mesma CI

- Catálogo versionado Onepage/Landing Page/Performance no checkout sintético do Portal; preços e regras do usuário prevalecem sobre propostas anteriores. Visualização e registro de snapshot do orçamento sem preço retroativo.
- Cadastro, login fictício por cliente, pedido/briefing e acompanhamento de produção/pós-venda em PostgreSQL 18, com permissões por empresa e painel administrativo.
- Interfaces Portal de autorização comercial e emissor Ed25519 com chave fictícia, validação cruzada pela implementação original do Sites fixada no CI.
- Leitor `GET /v3/payments/{id}` do Asaas (com URL oficial, HTTPS, sem proxy/redirect, mas **nenhum token real**); `AsaasVerifiedSettlementSource` compara cobrança com vínculo imutável de tenant, pedido, contrato, revisão, produto, valor e status. Leitor simulado nos testes.
- Inbox de webhook V3 com origem `account.id`, header de autenticação próprio, deduplicação e `FORCE RLS` PostgreSQL18.
- Worker de conciliação separado, lease/ACK com idempotência, recuperação de perda de ACK, recusa de estorno/chargeback e retentativas limitadas. Positivo vira **observação de auditoria**, sem publicar site ou cobrar.
- Testes PHP8.3/8.4 F1-387/389/390/391/392 mais workflows integrais de navegação do checkout. CIs de origem de PRs distintas **não** demonstram essa compatibilidade; validar a SHA desta candidata.

## O que continua deliberadamente sem integração

As rotas do checkout da #744 utilizam armazenamento sintético de pedidos. O gateway de sandbox e o worker Asaas **não estão ligados às rotas reais**, pois não há autorização IAM/tenant de produção, chaves Asaas sandbox no vault, mapeamento SQL imutável de pedidos+parcelas, política contratual para ativação, webhook HTTPS real, migration aprovada e mecanismo de revogação conectado ao Sites. Esses obstáculos não podem ser removidos inventando credenciais ou tratando um pagamento fictício como liquidado.

A #742 constitui outra linha que consolida o inbox na base de clientes/pós-venda; este candidato usa a versão F391 mais recente da #743, mas **não significa que a ancestralidade da #742 tenha sido resolvida**. Não promover ambas sem revisão.

A promoção futura exige: homologação de cliente/tenant no Portal, conta Asaas Sandbox independente, credenciais por ambiente fora do chat, confirmação de Pix Automático `ACTIVE`, ciclo de mensalidade D+90, condições de pagamento parcelado, webhook autenticado, fonte de PSP independente, NFS-e, aprovação de segurança/DBA e backup/rollback na VPS Leaseweb.

**GATE OPERACIONAL: NO-GO. Sem merge em `main`, sem deploy, sem cobranças, sem rotas públicas, sem publicação nem clientes reais.**
