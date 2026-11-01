# Cobranças e Focus NFe

Implementação 0.14.1, ainda em homologação local. Não ativada na conta real. Asaas Sandbox, Focus NFe e SMTP precisam ser configurados e homologados no servidor. Nenhuma nota fiscal ou mensagem real foi enviada durante o desenvolvimento.

## Cobranças

No financeiro, **Enviar cobrança** abre destinatário, valor, vencimento, mensagem e histórico. Só permite cobranças pendentes/vencidas e links HTTPS do domínio Asaas. Sem link válido, a prévia continua disponível e o envio fica bloqueado.

- WhatsApp: prepara um link `wa.me`; o operador abre a conversa, confere e envia. Não usa a API Business nem afirma entrega. O registro é **preparado**.
- E-mail: envio pelo servidor via SMTP usando Nodemailer. Após confirmação do operador, usa o destinatário cadastrado, registra tentativas e impede repetição com o mesmo identificador. Aceitação SMTP não comprova entrega/leitura.
- Envios recentes ou inconclusivos por e-mail impedem novas tentativas por cinco minutos. Uma reinicialização transforma tentativas interrompidas em inconclusivas. Confira o provedor antes de reenviar.
- Cadastro fiscal/contacto é separado do briefing e da informação publicada no site. CPF/CNPJ valida formato/dígitos, não titularidade. Sem WhatsApp ou e-mail válidos o canal correspondente permanece indisponível.

## Área de notas fiscais

Menu **Notas fiscais**, `/admin?view=fiscal`. Administrador e Financeiro consultam, preparam, revisam, emitem, conciliam e solicitam cancelamento. Somente o administrador altera regras e configurações. Clientes consultam somente suas próprias notas/documentos e podem atualizar o próprio cadastro fiscal; não acessam configuração, dados fiscais de terceiros ou histórico interno. Atendimento/Produção não acessam o módulo fiscal.

Filtros por competência, situação, ambiente e busca; paginação de 30 notas e CSV completo filtrado. O ambiente corrente é selecionado por padrão para separar testes de produção. Links XML/PDF/consulta aparecem somente quando retornados pelo provedor. Não são gerados PDFs fiscais fictícios. A lista contém apenas documentos originados por esta integração; importação de todo o histórico externo não está implementada.

## Regras fiscais

Localidade inicial: Fortaleza/CE, IBGE 2304400. **Modelo, regime, códigos de serviço, retenções e alíquotas não foram inferidos**. Informe os dados validados pelo contador; confira o cadastro/guia da Focus para selecionar NFS-e municipal (`nfse`) ou Nacional (`nfsen`). Existem perfis separados para site, sistema, aplicativo, agentes de IA e assinatura/hospedagem. Campos adicionais específicos do padrão podem ser fornecidos como JSON; não substituem identidades, valor ou competência calculados pelo sistema.

Regra comercial aprovada pela Imagine em 31/10/2026: uma nota pelo serviço total de implantação (oferta atual: R$ 990), independentemente das três parcelas de R$ 330, e uma nota por competência mensal da assinatura (oferta atual: R$ 79,90, com início após 90 dias). Os padrões de configuração são entrega e competência, respectivamente. A definição dos parâmetros tributários e do marco fiscal depende da validação do contador; automação e aprovação fiscal continuam desativadas. Os valores efetivamente emitidos vêm dos pedidos/cobranças, conforme o limite comercial descrito abaixo. A migração 11 atualiza apenas configurações iniciais nunca editadas, preservando configurações já personalizadas.

Regras configuráveis:

- Manual: prepara internamente e exige revisão/confirmar emissão.
- Implantação/projeto por entrega: usa o total registrado no pedido. Sites disparam na publicação aprovada; demais serviços disparam na mudança para entregue.
- Mensalidade por competência: usa valor e competência da cobrança mensal efetivamente gerada. A automação não emite antes dessa data e não cria períodos fictícios quando não há cobrança.
- Por recebimento: opcional após validação fiscal; usa apenas `RECEIVED`/`RECEIVED_IN_CASH`, nunca `CONFIRMED` ou apenas cobrança criada.

Ativação exige token configurado, emitente e confirmação administrativa da validação fiscal. Não processa retroativamente os pagamentos/entregas já existentes. Preparações manuais não passam a ser emitidas silenciosamente quando a automação está ativa. Eventos novos com falha de preparação ficam em alertas fiscais para revisão/retentativa. Dados ausentes ou rejeições exigem correção e revisão antes de reenviar.

O sistema impede misturar nota total de um pedido com notas de parcelas e impede bases diferentes para a mesma cobrança. Uma nota cancelada não gera automaticamente nova referência. Estorno/inadimplência/cancelamento de cobrança não cancela documento fiscal automaticamente.

**Cobrança atual:** novos pedidos usam três pagamentos de R$ 330/D0/D30/D60 e assinatura incluída de R$ 79,90/D90. Contratos legados conservam seus valores. O fiscal usa valores reais; a homologação Asaas/Focus ainda é necessária antes da ativação em produção.

## Conexão e segredos

Instale dependências (lockfile `pnpm-lock.yaml`): `pnpm install --frozen-lockfile --ignore-scripts`. Alternativamente, o único pacote externo direto é Nodemailer, fixado no `package.json`. Node.js 22.16+ ou 24+.

Variáveis privadas no servidor, conforme `.env.example`:

| Variável | Uso |
|---|---|
| `FOCUS_ENV` | `homologacao` por padrão ou `producao` |
| `FOCUS_API_TOKEN` | Token apropriado à conta/ambiente; somente servidor |
| `FOCUS_WEBHOOK_TOKEN` | Segredo próprio, mínimo 32 caracteres |
| `FOCUS_PRODUCTION_ENABLED` | `true` somente depois da homologação e validação fiscal |
| `SMTP_HOST`, `SMTP_PORT` | Servidor de e-mail, 465 TLS ou 587 STARTTLS |
| `SMTP_USER`, `SMTP_PASSWORD`, `SMTP_FROM` | Autenticação e remetente autorizado |

Cadastrar na Focus o webhook HTTPS `APP_URL/api/webhooks/focus`, configurando `authorization_header` como `x-focus-token` e `authorization` com o segredo privado `FOCUS_WEBHOOK_TOKEN`. A notificação só dispara consulta autenticada à referência conhecida: status e valores do corpo recebido não são aceitos como verdade. Certificado, habilitação do emitente e especificidades municipais devem ser conferidos na conta Focus. A interface não recebe token nem certificado.

Chamadas fiscais usam HTTP Basic com token como usuário e senha vazia, TLS validado e timeout. Não há token em URLs ou arquivos públicos. Produção também exige a liberação no servidor. Referência alfanumérica determinística e única por unidade fiscal/ambiente. Resposta aceita para processamento não é tratada como autorização. Em timeout/reinício, consulta-se a mesma referência antes de qualquer reenvio; não se cria uma referência nova automaticamente. Retorno autenticado é conciliado por consulta periódica/webhook. Execute uma única instância Node com SQLite persistente e backup restrito.

## Validação e pendências de implantação

Testes cobrem municipal/nacional, valores/tomador, duplicidade entre bases, timeout após aceite, processamento assíncrono, isolamento entre clientes/perfis, webhook autenticado, competências futuras, e-mail idempotente e link de cobrança. O pacote SMTP foi instalado e a mensagem MIME foi gerada localmente, sem transmissão.

Faltam testes com credenciais da conta de homologação da Imagine, SMTP real, webhook público, habilitação/certificado municipal e parâmetros fiscais reais. A instalação em produção continua pendente. Consulte os pull requests e releases no GitHub para o histórico de publicação do código. Não colocar banco, arquivos dos clientes, `.env`, tokens ou certificados no repositório.

## Referências oficiais consultadas

- [Focus NFe: emissão municipal](https://doc.focusnfe.com.br/reference/emitir_nfse)
- [Focus NFe: emissão Nacional](https://doc.focusnfe.com.br/reference/emitir_dps_nacional)
- [Focus NFe: Fortaleza](https://focusnfe.com.br/guides/nfse/municipios-integrados/fortaleza-ce/)
- [Focus NFe: ambientes](https://doc.focusnfe.com.br/reference/ambiente)
- [Focus NFe: webhooks](https://doc.focusnfe.com.br/reference/criar_webhook)
- [SEFIN Fortaleza: decreto sobre emissão por prestação do serviço](https://www.sefin.fortaleza.ce.gov.br/anexoCT/25/f5dpqhniz.erg136/pdf/Decreto)
- [Nodemailer: SMTP](https://nodemailer.com/smtp)
