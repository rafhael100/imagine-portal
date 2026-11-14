# Asaas e oferta comercial recuperada do Node

Decisão do titular em 14/11/2026: usar **Asaas** como gateway e recuperar os preços da primeira versão Node. Este registro orienta a migração Comercial → Portal; não comprova integração Laravel concluída nem habilita cobranças.

## Fonte fixada

Repositório original: `rafhael100/imagine-portal`, commit [50b683a499e674641df5749051f3d05f7ac3f627](https://github.com/rafhael100/imagine-portal/tree/50b683a499e674641df5749051f3d05f7ac3f627).

- [Oferta Onepage](https://github.com/rafhael100/imagine-portal/blob/50b683a499e674641df5749051f3d05f7ac3f627/docs/OFERTA-ONEPAGE.md).
- [Assinatura do cliente](https://github.com/rafhael100/imagine-portal/blob/50b683a499e674641df5749051f3d05f7ac3f627/docs/ASSINATURA-CLIENTE.md).
- `server/store.mjs`, `server/app.mjs` e `server/recurring-billing.mjs`: preço em centavos e modelo padrão `subscription-v2`.

## Condições recuperadas para novas contratações

| Item | Condição |
| --- | --- |
| Onepage: implantação | R$ 990,00 |
| Cobranças de implantação | R$ 330,00 na contratação, D+30 e D+60 |
| Natureza das cobranças | Três transações programadas separadamente no cartão; não prometer parcelamento bancário |
| Assinatura obrigatória | R$ 79,90/mês |
| Primeiro vencimento mensal | D+90 da contratação, independentemente da publicação; fuso de Brasília |
| Produção | Sete dias úteis após validação dos materiais |
| Ajustes previstos | Duas rodadas conforme contrato |
| Assinatura inclui | Licença de uso, hospedagem, SSL, backup e suporte técnico; alterações à parte |
| Domínio | Fornecido pelo cliente |

A oferta original apresenta R$ 2.490 como valor de referência promocional. A cobrança recuperada é R$ 990; a migração não cria validade promocional, urgência ou desconto adicional. Descontos Pix/Pix Automático não foram confirmados nestes arquivos do modelo atual e não devem ser aplicados automaticamente com base em memória de planejamento.

Valores de R$ 39,90 e início em 60 dias pertencem ao modelo legado; contratos anteriores conservam os respectivos valores e regras. Não substituir contratos existentes pelo modelo novo.

Landing Page e Performance não possuem preço aprovado identificável nas fontes revisadas. Manter contratação direta desses serviços bloqueada até localizar a oferta correspondente; ausência de preço não significa gratuidade. Sistemas, aplicativos e agentes IA têm preço nulo no catálogo inicial e dependem de proposta.

## Responsabilidades e migração

O Portal é autoridade para catálogo financeiro, aceite contratual versionado, titularidade do pedido, cliente Asaas, cobranças, assinatura, conciliação e recibos. O Comercial consome a oferta publicada pelo Portal; não mantém tabela financeira independente.

O Node já contém adaptador Asaas (`server/asaas.mjs`) e cobrança recorrente. Isso não significa que a implementação Laravel de homologação já utilize esse adaptador. O arquivo `approved-commercial-catalog.json` nesta pasta é referência versionada da migração, sem ligação automática ao checkout.

Migrar primeiro em sandbox: chave por ambiente, segredo de webhook separado da chave API, validação do evento consultando o recurso no Asaas, deduplicação e conciliação antes de repetir operações de resultado incerto. Preservar vínculo titular/pedido, valor, moeda e referência externa no servidor; navegador nunca define o preço. Homologar pagamento inicial, D+30/D+60, assinatura D+90, falha, reenvio de webhook, estorno e cancelamento antes de habilitar cobrança real.

Segredos devem permanecer em configuração privada da VPS. A decisão de fornecedor e preços não fornece credenciais nem ativa pagamentos, fiscal ou provisionamento. Documentação oficial: https://docs.asaas.com/docs/sandbox e https://docs.asaas.com/docs/faq-de-webhooks.
