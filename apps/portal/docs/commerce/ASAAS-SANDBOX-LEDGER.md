# Checkout, parcelas, assinatura e eventos Asaas sandbox

Estado verificado em 14/11/2026: aplicação da VPS em `0de245815ce11caa31b824af3bce4a194ab94414`; fonte do operador em `3fea0b15fdaf9fa0b14f4b0f25d801005bcf1368`. A primeira revisão tem dez workflows aprovados, incluindo navegador/HTTP, PostgreSQL, papéis, imutabilidade financeira e recuperação após reinício. Nenhuma chave ou senha pertence ao repositório.

## Fluxo disponível na homologação privada

- Cadastro sintético individual, seleção da oferta, snapshot aceito e primeira cobrança no Asaas sandbox. Somente a confirmação consultada na API libera briefing. Resultado manual é bloqueado após escolher Asaas.
- Onepage: implantação de R$990 em D0/D30/D60, R$330 por cobrança; Landing Page: R$1290 em três cobranças de R$430. Mensalidade obrigatória de R$79,90 a partir de D90, vinculada ao aceite e independente da publicação.
- A previsão mensal preserva o dia âncora em meses curtos. O worker cria assinatura nativa MONTHLY; ACTIVE significa recorrência ativa, não pagamento recebido. Cada fatura mensal é conciliada separadamente e aparece no acompanhamento financeiro.
- Performance: antecipação de honorários de 20% do orçamento selecionado; mídia paga diretamente ao canal. Esta implementação não executa o acerto mensal do gasto efetivo.
- Cliente e administrador podem ver o financeiro após validação do escopo do pedido; outro cliente recebe 404. Dados de homologação não constituem contrato jurídico, pagamento real ou serviço publicado.

## Webhook e conciliação

Endpoint HTTPS exato: `https://portal-dev.imaginecomunica.com.br/integrations/asaas/sandbox/events`. Somente essa rota de máquina recebe autenticação própria; o restante do Portal mantém sua proteção. O receptor aceita apenas o host do Portal, POST JSON com tamanho limitado e o header `asaas-access-token` validado em tempo constante. O token é independente da API key, fica em arquivo privado 0600 e não é impresso em logs ou argumentos de processos.

O receptor grava somente hash do ID do evento, tipo e identificadores necessários ao roteamento, nunca o payload completo com dados pessoais/financeiros. A role `purchase_rehearsal_hook` não altera pedidos nem aprova pagamentos. HTTP200 só ocorre após a gravação; falha no banco retorna503. Eventos repetidos têm a mesma chave; novos tipos desconhecidos são ignorados sem interromper a fila.

O worker usa a role restrita PSP para rotear eventos, consultar o recurso no endpoint sandbox fixo e verificar cliente, referência, valor e vencimento. Não aceita valor ou situação financeira alegados no webhook como prova. Enquanto verifica uma alteração, o estado financeiro deixa de indicar aprovação antiga. Eventos são reconhecidos somente após conciliação bem-sucedida; novas tentativas têm intervalo crescente, máximo de oito tentativas e teto de quinze minutos entre tentativas. Falhas persistentes requerem revisão operacional. Eventos que chegam durante a conciliação permanecem na fila para a próxima execução.

Referências externas e estágios persistidos protegem contra POST duplicado. Respostas de criação incertas são pesquisadas, nunca repetidas cegamente. Links de fatura só admitem o host sandbox exato por HTTPS. A assinatura pode avançar nextDueDate ao gerar a primeira fatura; nesse caso o worker valida o D90 original na fatura correspondente e o próximo ciclo, sem alterar o contrato.

## Publicação e operação

Aplicar as migrações somente no banco dedicado `portal_purchase_rehearsal`, após backup: customer/offers, `sandbox-payment-schema.sql`, `sandbox-future-schema.sql`, `sandbox-webhook-schema.sql`. Não aplicar em banco de identidade ou produção. A chave API permanece fora do contêiner web; a rede interna Docker permanece isolada.

- `imagine-asaas-sandbox.timer` processa intenções solicitadas e eventos recebidos.
- `imagine-asaas-webhook.service` escuta somente `127.0.0.1:18084`, atrás da rota HTTPS exata do Nginx.
- `configure_sandbox_webhook.py prepare|health|register` prepara configuração privada, verifica autenticação HTTPS e registra o webhook sem expor token. A criação incerta exige conciliação antes de novo POST.
- Backups: `/root/purchase-before-webhook-20261114.sql`, `/root/nginx-before-webhook-20261114.conf`, imagem `imagine-purchase-preview:before-webhook`. Migrações são aditivas; reversão de aplicação não implica apagar dados.
- `smoke_owned_sandbox.py --scenario webhook prepare|verify|verify-paid|briefing` usa conta fictícia e credenciais salvas somente em arquivo privado.
- `confirm_sandbox_smoke.py --product onepage --scenario webhook --provider-only` confirma apenas no simulador oficial, sem aprovação local. `confirm_future_sandbox_smoke.py --product onepage --charge setup-2|setup-3|monthly` faz o mesmo com parcelas e primeira fatura da assinatura.

## Evidências observadas na VPS

1. Onepage, Landing Page e Performance: cobrança sandbox criada, confirmação consultada, briefing salvo por HTTP do proprietário, financeiro recuperado no acompanhamento.
2. Novo pedido Onepage exclusivo para webhook: PAYMENT_CREATED e PAYMENT_RECEIVED gravados e processados; confirmação feita somente no provedor, sem conciliação manual; briefing liberado e salvo automaticamente.
3. Pedido Onepage padrão: parcelas D30/D60 conciliadas como approved/RECEIVED após confirmação somente no provedor. Assinatura continuou ACTIVE, enquanto a fatura de R$79,90 com vencimento D90 foi registrada separadamente como approved/RECEIVED e exibida no financeiro.
4. Receptor e timer permaneceram ativos; health HTTPS aceitou token correto e recusou token inválido. Nenhuma movimentação real de dinheiro.

## Pendências de produção

Não afirmar integração de produção concluída por estes resultados. Permanecem acerto executável de Performance com evidência do gasto real e política para ajustes/créditos; contrato e cadastro/IAM definitivos; fiscal; provisionamento/publicação real; configuração e validação de credenciais, webhooks e cobrança na conta Asaas de produção. Não há tokenização de cartão ou débito automático em cartão nesta homologação. Novos canais/regras aprovados em outros trabalhos não são ativados implicitamente por este worker.
