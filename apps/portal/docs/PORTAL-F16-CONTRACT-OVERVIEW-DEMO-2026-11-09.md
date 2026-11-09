# PORTAL-F16 — Visão de contratos fictícios, sem assinatura ou pagamento

Data: 09/11/2026. Status: **preview somente, não operacional**.

## Contexto

A direção autorizou desenvolvimento contínuo do Imagine Portal durante a
separação do motor de produção Imagine Sites. Este recorte é empilhado sobre
PORTAL-F15 (#113) e PORTAL-F14 (#112), sem tocar na `main`.

## Entrega

- `DemoContractStage`: enumeração limitada a estados ilustrativos.
  **Não existe estado "assinado", "ativo" ou "pago"**.
- `DemoContractRecord`: registro readonly exclusivamente fictício, com
  referências obrigatórias `DEMO-CTR-*` e `DEMO-SVC-*`.
- Três contratos de exemplo separados por produto: Onepage, Landing Page,
  Performance AI. Nenhum serviço adicional implícito.
- Métodos `grantsServiceAccess()`, `createsPaymentObligation()` e
  `isLegallyBinding()` sempre retornam `false`.
- Tabela Blade existente exibe os registros tipados, sem links, botões,
  formulários, data de assinatura, valores ou comunicação com PSP.
- Testes de validação de IDs, estados desconhecidos, dados imutáveis,
  isolamento da visualização, ausência de queries e ausência de superfícies
  de escrita.

## Fronteiras de responsabilidade

- O Portal deverá manter o catálogo comercial, contratos, cobranças, aceites
  e a visualização do cliente, mas **não executar internamente** a geração,
  edição ou publicação de Sites.
- O Imagine Sites é motor separado, comandado por contratos autenticados e
  idempotentes quando homologados; nenhum comando é enviado por este recorte.
- Performance AI também é serviço independente. O CRM usa autenticação e
  banco próprios. A Universidade é benefício condicionado a serviço elegível
  e não é criada como assinatura avulsa aqui.

## Antes de contratos reais

Precisam de implementação e homologação independentes: identidade Portal,
tenant/RLS, papéis, modelos transacionais de contrato, versão de termos e
aceite inequívoco, assinatura/aceite juridicamente válido quando aplicável,
ofertas/capacidade e preços aprovados, integração PSP segregada, conciliação,
regras de desconto aprovadas, retenção e LGPD, trilha de auditoria, rollback
seguro, testes negativos e revisão jurídica.

O preview não autoriza alteração de `main`, merge automático, publicação,
pagamento, contratação, VPS, dados reais ou mudanças de permissão.

## Evidência de qualidade

Exigir PHP 8.3 e PHP 8.4 no CI Laravel e Node.js 22 e Node.js 24 nos testes
do legado; não declarar aprovação antes de conferir os workflows no SHA final.
