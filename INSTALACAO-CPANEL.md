# Imagine — portal de clientes e administração

## Estado da entrega

Aplicação implementada localmente, com integração HTTP ao Asaas e testes automatizados usando um provedor simulado. Cadastro, login e registro de pedido foram verificados no navegador local. **Ainda não instalada no seu servidor e ainda não homologada com sua conta Asaas Sandbox. Nenhuma cobrança real foi gerada.**

Endereço planejado: `https://clientes.imaginecomunicacao.com.br/portal`. Administração: `/admin`, com conta administrativa criada no servidor. A permissão é verificada em cada requisição; acessar a URL não concede privilégios.

## Funcionalidades

- Cliente: criar conta, entrar, alterar senha, registrar pedidos, ler propostas, gerar cobranças individuais, consultar faturas e status, acompanhar etapas e enviar mensagens por pedido.
- Administrador: ver clientes e pedidos, definir escopo/prazo/preço, acompanhar recebimentos, confirmações, pendências e atrasos, exportar CSV, atualizar etapas, responder mensagens e consultar atividades.
- Site Onepage: R$ 990,00, com preço validado pelo servidor.
- Manutenção opcional: R$ 39,90/mês no cartão, sem modificações. Primeira cobrança 60 dias após a aceitação da contratação do site, mesmo se o site ainda não estiver no ar. O cliente autoriza o cartão em um checkout separado do Asaas após a confirmação do pagamento do projeto. A data inicial não é adiada pela autorização posterior. Depois dos 60 dias, será necessária uma nova proposta, sem cobrança retroativa automática.
- Cancelamento da manutenção pelo cliente ou administrador. Se houver falha de comunicação, o pedido é registrado e conciliado novamente. O portal só mostra cancelada após confirmação. Cobranças pagas não são estornadas automaticamente.
- Webhooks autenticados, fila persistente e retentativas. Eventos duplicados não repetem atualizações. O estado financeiro é consultado no Asaas antes de atualizar o pedido.

O financeiro cobre os pedidos deste portal: não representa o saldo bancário ou todo o extrato Asaas. Transferências, contas a pagar, notas fiscais, anexos, MFA e envio automático de e-mail não estão implementados. A recuperação de senha nesta versão é assistida pelo operador do servidor.

## 1. Preparar o WHM/cPanel

É necessário **Node.js 22.16 ou superior**, com `node:sqlite`, disco persistente e Terminal/SSH. Node.js 24 também é compatível. Habilite Application Manager/Passenger ou Setup Node.js App para a conta. Ter WHM/cPanel não garante que esse suporte esteja instalado.

```sh
node --version
node -e "require('node:sqlite'); console.log('SQLite OK')"
```

Se faltar suporte, seu administrador de hospedagem precisa habilitá-lo. Não substitua o runtime global sem avaliar os outros sites.

Referências oficiais: [Application Manager](https://docs.cpanel.net/cpanel/software/application-manager/) e [aplicação Node.js](https://docs.cpanel.net/knowledge-base/web-services/how-to-install-a-node.js-application/).

## 2. Instalar

1. Crie `clientes.imaginecomunicacao.com.br`, configure DNS e HTTPS/AutoSSL.
2. Envie esta pasta para **fora de public_html**, por exemplo `/home/USUARIO/imagine-portal`.
3. No gerenciador de aplicações, use a pasta como Application Root e `app.js` como inicializador. A raiz pública deve ser `imagine-portal/public`, nunca a pasta que contém `.env`, `server/` e banco. O Node serve as páginas e ativos por uma lista restrita de arquivos.
4. Configure as variáveis no painel ou copie `.env.example` para `.env` na raiz privada da aplicação. Não envie chaves no chat nem as coloque nos arquivos JavaScript do navegador.
5. Use **um processo de aplicação** nesta primeira versão. Ele precisa permanecer ativo para consumir a fila. No Passenger, configure operação persistente ou uma tarefa cron que consulte `/health` a cada minuto para reativá-lo quando necessário.

Configuração de exemplo, sem segredos:

```dotenv
NODE_ENV=production
APP_URL=https://clientes.imaginecomunicacao.com.br
HOST=127.0.0.1
PORT=4173
DATA_DIR=/home/USUARIO/imagine-portal-data
ASAAS_ENV=sandbox
ASAAS_API_KEY=PREENCHER_NO_SERVIDOR
ASAAS_WEBHOOK_TOKEN=GERAR_TOKEN_ALEATORIO_PROPRIO
SELLER_LEGAL_NAME=RAZAO_SOCIAL_DA_IMAGINE
SELLER_DOCUMENT=CNPJ_DA_IMAGINE
```

`DATA_DIR` deve ser gravável pela conta, em disco local persistente, fora da raiz pública e com acesso restrito. Não use disco temporário nem compartilhe SQLite entre máquinas. Não há dependências npm externas para instalar.

Execute `npm test` na pasta da aplicação. Para executar diretamente, use `npm start`. No Passenger, o `app.js` também carrega o `.env` privado quando existir. Reinicie pelo painel após alterações. Permita HTTPS de saída ao Asaas.

## 3. Criar administrador

Configure temporariamente `ADMIN_NAME`, `ADMIN_EMAIL` e `ADMIN_PASSWORD` no ambiente privado ou `.env`. Use senha única de pelo menos 12 caracteres e execute:

```sh
npm run admin
```

Remova `ADMIN_PASSWORD` após o comando. Não existe administrador padrão. O cadastro público cria somente clientes. O comando não promove uma conta de cliente já existente.

Para recuperação assistida, confirme a identidade do solicitante em um canal confiável, configure temporariamente `RESET_EMAIL` e `RESET_PASSWORD` e execute `npm run reset-password`. As sessões antigas serão revogadas. Remova a senha do ambiente após o uso.

## 4. Conectar Asaas Sandbox

Use uma conta Sandbox, independente da conta de produção. Cadastre a chave somente no servidor.

Crie um webhook no Asaas com:

- URL `https://clientes.imaginecomunicacao.com.br/api/webhooks/asaas`.
- `authToken` igual a `ASAAS_WEBHOOK_TOKEN`, aleatório, sem espaços, entre 32 e 255 caracteres e diferente da chave da API.
- Versão 3, habilitado e envio sequencial.
- Eventos disponíveis `PAYMENT_*` de criação, atualização, confirmação, recebimento, atraso, exclusão, estorno, recuperação e contestação.
- `SUBSCRIPTION_CREATED`, `SUBSCRIPTION_UPDATED`, `SUBSCRIPTION_INACTIVATED`, `SUBSCRIPTION_DELETED`.
- `CHECKOUT_CREATED`, `CHECKOUT_PAID`, `CHECKOUT_CANCELED`, `CHECKOUT_EXPIRED`.

O token é validado em `asaas-access-token`. O endpoint grava a referência mínima antes de responder HTTP 200. Não persiste CPF/CNPJ nem cartão do webhook. A fila é processada a cada 10 segundos com o processo ativo.

Cada pedido tem um cadastro financeiro Asaas com referência própria. Isso isola assinaturas entre projetos, mas pode criar vários cadastros para o mesmo comprador. O CPF/CNPJ informado no portal é enviado ao Asaas e não é persistido localmente.

Documentação oficial: [Sandbox](https://docs.asaas.com/docs/sandbox), [cobranças](https://docs.asaas.com/reference/criar-nova-cobranca), [assinatura no checkout](https://docs.asaas.com/docs/checkout-com-assinatura-recorrente), [webhooks](https://docs.asaas.com/docs/receive-asaas-events-at-your-webhook-endpoint).

## 5. Configurar ofertas e homologar

Em Catálogo, defina entregas, revisões, exclusões, prazo e condição de início antes de liberar vendas. O Onepage mantém R$ 990,00. Sistemas, aplicativos e IA podem receber propostas por pedido.

O catálogo começa desabilitado porque ainda faltam aprovar o escopo detalhado, prazo e dados do prestador. Clientes podem registrar pedidos, mas não gerar cobranças sem proposta completa. A versão aceita é preservada; propostas aceitas ou em cobrança não podem ser alteradas.

Homologue na sua conta Sandbox:

- Login de cliente e administrador e isolamento entre contas.
- Cobrança individual Onepage de R$ 990,00 e repetição de cliques sem duplicação.
- Confirmação, atraso, estorno e contestação por webhook.
- Manutenção de R$ 39,90, mensal, cartão e primeiro vencimento exato D+60. Confirme que a autorização não antecipa a primeira cobrança.
- Cancelamento antes/depois da autorização e falhas de comunicação.
- Conciliação e CSV financeiro.

Depois, troque `ASAAS_ENV` para `production`, use a chave de produção, cadastre o webhook na conta de produção e reinicie.

Em timeout, uma nova criação fica bloqueada até a conciliação. O administrador só deve liberar outra tentativa após confirmar manualmente no Asaas que não existe cobrança. Checkouts de manutenção inconclusivos/expirados sem recurso recuperável exigem conciliação administrativa antes de reabrir a contratação; esta versão não os recria automaticamente.

## Operação

Inclua banco e configuração privada no backup seguro da hospedagem. Use backup online SQLite ou pare a aplicação antes de copiar banco/WAL; não copie apenas o arquivo principal em uso. Teste a restauração.

Monitore `/health`, fila de atividade do portal e logs do Asaas. Defina política de privacidade, retenção de dados e atendimento a solicitações dos clientes antes da abertura pública. A versão inclui sessões HttpOnly, CSRF, limitação de tentativas, hash scrypt e verificação de dono/perfil no servidor.

Não copie a pasta `work/` desta conversa para o servidor: ela contém apenas tarefas intermediárias e testes locais.
