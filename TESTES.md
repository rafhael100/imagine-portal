# Verificação da primeira versão

Resultado local: **18 testes automatizados passaram**, sem gerar pagamentos reais. Comando: `npm test`.

Cobertura:

1. Cadastro, login, CSRF e isolamento de pedidos entre contas.
2. Preço validado no servidor, proposta imutável após aceitação e repetição sem duplicação.
3. Timeout após criação de cobrança e recuperação por conciliação.
4. Webhooks autenticados, duplicados e fora de ordem; estorno refletido no financeiro.
5. Manutenção opcional, mensal no cartão, com primeiro vencimento D+60 e cancelamento.
6. Permissões administrativas, propostas, mensagens e alteração de senha.
7. Senha protegida, validação de documento, snapshot da proposta e revogação de sessão.
8. Datas D+60 atravessando meses e anos.
9. Adaptador Asaas: Sandbox, cabeçalho de autenticação no servidor e falha sem repetição automática.
10. Duas solicitações concorrentes criando apenas uma cobrança.
11. Recuperação de checkout de manutenção após timeout por webhook.
12. Cancelamento do checkout antes de existir assinatura sem repetir a mutação.

Também conferidos: sintaxe JavaScript, âncoras internas e bloqueio HTTP a `.env`, banco e código do servidor. A imagem da marca é o arquivo original obtido do site atual, sem redesenho.

No navegador local foram verificados cadastro, login, registro de pedido, detalhes, navegação administrativa e catálogo. As capturas contêm apenas dados de teste local, fora do pacote de produção.

Limites: chamadas externas ao Asaas foram simuladas nos testes. Credenciais, comportamento da conta Sandbox, recorrência real, webhooks públicos, compatibilidade do runtime do seu cPanel, DNS e HTTPS ainda precisam de homologação no servidor. Não houve implantação nem movimentação de dinheiro.

## Briefing e materiais (v0.2.0)

Seis testes adicionais cobrem: acesso somente após pagamento e isolamento entre contas; rascunho/envio/complemento/aprovação e conflitos de versão; download privado, remoção e bloqueio após estorno; extensão, assinatura do arquivo, tamanho e CSRF; solicitação de apoio IA e validações; migração idempotente preservando pedidos.
