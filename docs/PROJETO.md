> **Atualização comercial aprovada:** implantação promocional de R$ 2.490 por R$ 990, em R$ 330 nos dias 0, 30 e 60; assinatura obrigatória de R$ 79,90/mês a partir do dia 90, independentemente da publicação. Prazo: sete dias úteis após validar os materiais. Duas rodadas no contrato. A regra anterior de manutenção opcional de R$ 39,90/D+60 permanece apenas no código financeiro legado e NÃO deve ser ativada para a nova oferta. Checkout direto e aceite ainda são prévia; integração financeira e contrato definitivo pendentes.

# Projeto e regras comerciais

## Identidade

Marca original da Imagine Comunicação Digital, preservada sem redesenho. Paleta laranja `#F9941D`, branco e preto. Atuação em todo o Brasil, Estados Unidos e Portugal. Contato do rodapé por WhatsApp `+55 (85) 99663-2689`, sem endereço.

## Serviços

Desenvolvimento de sites, sistemas, aplicativos e agentes de IA. O Site Onepage custa R$ 990,00. Os demais serviços recebem propostas com escopo, valor e prazo. Prazo do Onepage: 7 dias úteis após o recebimento do briefing e dos materiais completos, com duas rodadas de ajustes. O cliente fornece o domínio para publicação. Textos e imagens podem ser enviados pelo cliente; quando faltar conteúdo, a Imagine pode apoiá-lo com IA. O escopo detalhado das seções ainda precisa de aprovação antes de liberar a oferta.

## Manutenção

- Opcional, R$ 39,90 por mês, com recorrência no cartão.
- Inclui hospedagem do site e não inclui modificações.
- Primeira cobrança 60 dias após a aceitação da contratação do site, independentemente de ele já estar no ar.
- Cancelamento a pedido do cliente a qualquer momento.
- Autorização do cartão em checkout separado, após confirmação do pagamento do projeto. A data inicial de D+60 permanece fixa; autorizações fora do período exigem nova proposta.

## Arquitetura atual

Servidor Node.js com HTTP nativo, SQLite em disco persistente e uma instância da aplicação. Senhas com scrypt, sessões HttpOnly, CSRF e verificação de dono/perfil no servidor. As chaves Asaas ficam no ambiente do servidor, e os dados de cartão são informados diretamente no Asaas.

Webhooks são autenticados e persistidos antes da resposta HTTP 200. A fila executa conciliação com retentativas. O financeiro cobre as cobranças dos pedidos do portal, sem representar todo o extrato ou saldo da conta Asaas.

## Estado e próximas entregas

O servidor atual identificado na conversa utiliza CentOS 7 e cPanel 110; não atende ao runtime desta versão pela oferta oficial do cPanel. A escolha de um novo servidor foi adiada pelo usuário. A aplicação continua local e sem conexão à conta Asaas real.

O código e a documentação estão no repositório privado `rafhael100/imagine-portal`, com prévia `v0.1.0` e testes automáticos em Node.js 22 e 24. A atualização do GitHub ainda não implanta o servidor automaticamente.

Próximo passo ativo: fechar o escopo comercial do Onepage, descrito em [OFERTA-ONEPAGE.md](OFERTA-ONEPAGE.md). Depois, homologar a conta Sandbox, finalizar a configuração das ofertas e instalar em um servidor compatível. Recuperação de senha por e-mail, MFA e emissão fiscal são possíveis evoluções, ainda não implementadas.

## Briefing e materiais do Onepage

Após a confirmação do pagamento, o cliente pode salvar o briefing do pedido, enviar logomarca e manual de identidade visual e informar apresentação da empresa, serviços, site atual, domínio e dados de contato. Os arquivos ficam privados por pedido e são acessíveis somente ao cliente pagante e aos administradores.

Estados: rascunho, enviado para análise, complemento solicitado e aprovado. A edição fica bloqueada durante análise e após aprovação; uma solicitação de complemento reabre o envio. O apoio com IA é uma solicitação para a equipe, sem geração automática de conteúdo nesta versão.

Limites iniciais: logo em PNG/JPG/WebP/PDF e manual em PDF; 10 MB por arquivo, 10 arquivos e 50 MB por pedido. Arquivos são baixados como anexos, sem prévia executável no portal.
