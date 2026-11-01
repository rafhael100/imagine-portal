# Imagine Comunicação Digital

Site e primeira versão do portal com autenticação, pedidos, propostas, mensagens, financeiro e integração Asaas por API.

Para instalar no WHM/cPanel, siga INSTALACAO-CPANEL.md. Requer Node.js 22.16+ e disco persistente. O portal precisa do servidor Node e do banco; não funciona somente abrindo o HTML.

- npm start: inicia localmente em http://127.0.0.1:4173.
- /portal: acesso do cliente.
- /admin: acesso administrativo com conta criada no servidor.
- npm test: verifica fluxos com provedor simulado, sem cobranças reais.

Novos pedidos Onepage: três pagamentos de R$ 330 nos dias 0, 30 e 60; assinatura mensal incluída de R$ 79,90 com primeira mensalidade após 90 dias, independentemente da publicação. O cliente autoriza a recorrência na compra e atualiza o cartão pelo portal, sem liberação da Imagine. Contratos antigos preservam suas condições. Consulte docs/ASSINATURA-CLIENTE.md.

O financeiro cobre os pedidos deste portal. A integração precisa ser configurada e homologada na sua conta Sandbox antes de receber pagamentos reais. A instalação no servidor, o DNS e o certificado ainda não foram realizados.

Marca original preservada. Paleta laranja #F9941D, branco e preto. Atuação em todo o Brasil, nos Estados Unidos e em Portugal. Rodapé com contato pelo WhatsApp.
