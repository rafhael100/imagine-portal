# P0 — contexto Comercial por canal no checkout fictício

Esta PR complementa o checkout sintético da PR #744. O Comercial pode encaminhar `product=performance&channel=google_ads` ou `channel=meta_ads`; para Onepage/Landing Page somente `product`. São valores permitidos, não dados de preço, crédito, cliente ou pagamento.

- `CustomerPreviewHandoff` valida produto/canal e cria destino local, nunca aceita URLs externas.
- `CustomerPreviewController::shop()` rejeita query inválida em HTTP 422, armazena somente o contexto permitido e exibe a origem no formulário.
- Cadastro e login recuperam a intenção permitida antes de a sessão ser regenerada. Usuários anônimos continuam sem acesso ao checkout. Administradores não recebem a intenção de compra.
- O campo de investimento financeiro permanece **vazio**; usuário precisa informar explicitamente os valores para cada canal. O backend do Portal calcula e valida o mínimo de R$ 700 por canal e a estimativa de gestão de 20%; mídia é paga diretamente à plataforma.
- Novos testes do Chromium cobrem cliente autenticado, login, registro, recusa de canal inválido e ausência de orçamento predefinido.

## Limites

Isto não ativa Asaas, recorrência, nota fiscal, autorização de serviço ou publicação. Não há tracking entre domínios, cobrança nem vínculo com cliente real. O contexto é exclusivo da prévia privada e pode ser ignorado ou alterado pelo próprio cliente antes do envio; a escolha financeira válida é somente a que for confirmada no POST protegido do Portal.

**Estado:** DRAFT / NO-GO / NO-MERGE / NO-DEPLOY. Continuar revisão de IAM, PSP, ledger, assinatura contratual e homologação privada.
