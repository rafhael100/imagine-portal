# PORTAL-F42 — Contrato de briefing de Onepage e Landing Page (prévia sem PII)

**09/11/2026 · Laravel/PHP puro · sem HTTP, autenticação, armazenamento ou cobrança.**

## Decisão de escopo

F42 cria um contrato estrito de **prévia de briefing** para os dois
serviços comerciais de Sites Imagine já presentes no catálogo do Portal:
`sites_onepage` e `sites_landing_page`. Não altera a definição
comercial, as taxas de implantação, a mensalidade ou a aprovação final
dos materiais. Um briefing preenchido não constitui contratação.

O domínio recusa solicitar `Imagine CRM`, `Performance AI` ou
`Universidade Imagine` como se fossem uma Onepage/Landing Page.
A Universidade permanece benefício elegível, não produto vendido
separadamente. Nenhum fluxo de pagamento foi criado.

## Campos mínimos de laboratório

A entrada é um array **fechado** contendo exatamente:

| Chave | Regra no protótipo |
|---|---|
| `reference` | `DEMO-SITE-REQ-*`, exclusivamente fictícia |
| `organization` | `org-a` ou `org-b`, deve coincidir com a organização selecionada na prévia |
| `product` | `sites_onepage` ou `sites_landing_page` |
| `objective` | 12–500 caracteres Unicode |
| `audience` | 8–180 caracteres Unicode |
| `call_to_action` | 4–100 caracteres Unicode |
| `revision` | inteiro 0–100000 para evoluções futuras, sem persistência |

O módulo rejeita propriedades extras como e-mail, telefone,
CNPJ, endereço, identificação de pessoa, anexo, preço,
cobrança, senha e token. Não exibe nem armazena tais dados.
A validação também rejeita texto malformado em UTF-8,
caracteres invisíveis/de controle ou marcas HTML, limita
tamanho em bytes **antes** de contar caracteres e normaliza
espaçamento interno nas strings ilustrativas.

## Implementação

- `SiteBriefPreview`: valida primeiro o vocabulário fechado e
  o isolamento da empresa fictícia, depois produto, referência,
  revisão e texto. O `OrganizationId` selecionado **NÃO**
  equivale a IAM/autorização; apenas evita misturar fixtures.
- `SiteBriefDraft`: resultado imutável de ilustração com
  produto, objetivo, público, CTA e referência; métodos
  `canSubmitToPortal`, `canPublishSite`,
  `canChargeOrContract` são **sempre false**.
- `SiteBriefPreviewResult`: distinção entre prévia preparada
  e recusa, sem conceder escrita, publicação ou acesso.
- `SiteBriefPreviewTest.php`: 12 testes unitários de
  Onepage/Landing Page, normalização, isolamento por
  organização, campos faltantes/extras, ausência de PII,
  produto não elegível, enum, revisão, caracteres de controle,
  HTML, limites UTF-8, determinismo e irrelevância
  operacional do resultado.

## Próximas atividades para o produto real

Esta implementação apenas prepara o **modelo de dados de
briefing**, não um formulário funcional nem workflow
aprovado de clientes. Uma futura implementação operacional
precisa de uma sessão Portal-only verificada, membership e
owner válidos, escolha do serviço/catálogo ativo, consentimento
e minimização LGPD, política de retenção, revisão/edição e
aprovação documentada do conteúdo gerado por IA, anexos
seguramente armazenados, integração com o motor Imagine Sites
e aprovação financeira/comercial.

Não existe rota Laravel, provider, migração, cron, API,
HTTP/CRM, banco, serviço externo ou credencial nova. O
backend segue PHP/Laravel; Node permanece tooling.
**PR DRAFT, sem merge/deploy.** P0/D02/D03, SEC-R03/Z4,
T3/OPEN, AppSec/DBA, IAM/MFA e infraestrutura homologada
continuam pendentes.
