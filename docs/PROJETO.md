# Projeto e regras comerciais

## Identidade

Marca original da Imagine Comunicação Digital, preservada sem redesenho. Paleta laranja `#F9941D`, branco e preto. Atuação em todo o Brasil, Estados Unidos e Portugal. Contato do rodapé por WhatsApp `+55 (85) 99663-2689`, sem endereço.

## Serviços

Desenvolvimento de sites, sistemas, aplicativos e agentes de IA. O Site Onepage custa R$ 990,00. Os demais serviços recebem propostas com escopo, valor e prazo. As condições detalhadas precisam ser cadastradas e aprovadas antes da venda.

## Manutenção

- Opcional, R$ 39,90 por mês, com recorrência no cartão.
- Não inclui modificações.
- Primeira cobrança 60 dias após a aceitação da contratação do site, independentemente de ele já estar no ar.
- Cancelamento a pedido do cliente a qualquer momento.
- Autorização do cartão em checkout separado, após confirmação do pagamento do projeto. A data inicial de D+60 permanece fixa; autorizações fora do período exigem nova proposta.

## Arquitetura atual

Servidor Node.js com HTTP nativo, SQLite em disco persistente e uma instância da aplicação. Senhas com scrypt, sessões HttpOnly, CSRF e verificação de dono/perfil no servidor. As chaves Asaas ficam no ambiente do servidor, e os dados de cartão são informados diretamente no Asaas.

Webhooks são autenticados e persistidos antes da resposta HTTP 200. A fila executa conciliação com retentativas. O financeiro cobre as cobranças dos pedidos do portal, sem representar todo o extrato ou saldo da conta Asaas.

## Estado e próximas entregas

O servidor atual identificado na conversa utiliza CentOS 7 e cPanel 110; não atende ao runtime desta versão pela oferta oficial do cPanel. A escolha de um novo servidor foi adiada pelo usuário. A aplicação continua local e sem conexão à conta Asaas real.

Próximos passos: conectar o repositório privado, homologar a conta Sandbox, finalizar condições das ofertas e instalar em um servidor compatível. Recuperação de senha por e-mail, MFA, anexos e emissão fiscal são possíveis evoluções, ainda não implementadas.
