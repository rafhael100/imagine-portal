# Assinatura mensal no portal

Chamadas destacadas nas áreas de visão geral, pedidos, pagamentos e manutenção. Cada site apresenta a ação adequada. Mais de um projeto pendente abre um seletor; o cliente não autoriza todos de uma vez.

O servidor calcula subscription_action: primeira configuração elegível, continuar checkout não expirado, consultar ativo, regularizar mensalidade ou solicitar conferência. Cancelamento em andamento e conciliação têm prioridade para evitar uma segunda autorização. Não se infere falha no cartão somente por atraso.

A tela de condições consulta novamente o pedido. A primeira adesão usa a rota existente de manutenção, após checkbox explícito, e mostra um link para o Asaas. Nenhum dado de cartão passa pelo portal. Consultar a tela não cria cobrança.

Sem gateway liberado, o cliente pode preparar uma mensagem de interesse. Cancelados, inativos, expirados, datas antigas ou cadastro inconclusivo exigem ajuda da Imagine; esta versão não implementa reativação automática. O formulário de ajuda só envia mensagem após o cliente clicar em Enviar.

A regra efetiva permanece legada: R$ 39,90 opcional, primeira cobrança em 60 dias. A oferta nova de R$ 79,90 a partir de 90 dias com implantação em três parcelas precisa de migração e homologação antes da ativação comercial. Valores não são anunciados para uma adesão ainda indisponível sem assinatura cadastrada.

Permissões: clientes consultam somente seus pedidos; atendimento/produção recebem subscription_action nulo. Testes cobrem elegibilidade, repetição, expiração, cancelamento, carência, atraso e bloqueio do gateway. 53 testes aprovados.
