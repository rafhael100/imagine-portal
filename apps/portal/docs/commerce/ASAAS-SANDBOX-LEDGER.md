# Pedido → cobrança Asaas sandbox → confirmação → briefing

Estado em 14/11/2026: chave sandbox fornecida pelo titular, instalada somente na configuração privada da VPS (modo 0600). GET autenticado da API retornou HTTP 200. Nenhum segredo faz parte desta alteração. A base anterior b12d518f foi publicada na homologação com backup e 110 verificações aprovadas na VPS.

## Escopo desta integração

- Cliente autenticado de teste solicita Asaas no próprio pedido, com CSRF, revisão e confirmação explícita de teste.
- Servidor extrai o valor do snapshot; usuário não envia preço, cliente Asaas nem status financeiro. Primeiro pagamento Onepage 330, Landing Page 430, Performance honorários antecipados estimados de 20%; mídia continua fora da cobrança Imagine.
- Uma intenção por pedido; limite global de 50 para evitar carga de teste indevida. Fonte financeira passa a asaas_sandbox e a alteração manual de resultado é recusada.
- O worker no host mantém a chave fora do contêiner web e preserva a rede interna Docker. Pede ao Asaas um pagador fictício com documento de exemplo da documentação oficial (https://docs.asaas.com/reference/criar-novo-cliente), SEM e-mail/telefone e com notificationDisabled=true. Nunca transmite o nome/empresa digitado no cadastro demo.
- Referência externa determinística por pedido, busca antes de criar e estágio persistido antes de cada POST. Resultado incerto permanece para conciliação, sem repetir POST quando a busca não localiza recurso. Referência/cliente/valor divergentes são recusados.
- Link de fatura só admite HTTPS no host exato sandbox.asaas.com, caminho /i/, sem credenciais, porta, query ou fragmento.
- A confirmação oficial RECEIVED ou CONFIRMED de cartão pode liberar briefing. CONFIRMED de PIX não é tratado como recebido. Estorno/contestação permanece registrado e bloqueia novos inícios de produção/briefing. Aprovação comercial não publica site real.

## Operação privada

Aplicar sandbox-payment-schema.sql após customer-offer-schema.sql, exclusivamente no banco portal_purchase_rehearsal, com backup. A role NOLOGIN purchase_rehearsal_psp não pode alterar o valor financeiro/snapshot nem ler hash de senha. O operador do host usa SET ROLE via psql do contêiner. Aplicação não recebe a chave API.

Executar no host, a partir de ops/private-purchase: python3 asaas_sandbox_worker.py --once. O comando usa lock exclusivo e processa até dez intenções solicitadas. Não executa polling permanente da lista Asaas: o cliente pede atualização no Portal e o operador reconcilia. Chave fora do formato sandbox, APP_ENV=production e configuração diferente de sandbox são recusados.

A execução periódica do worker e entrega por webhook ainda precisam de configuração operacional própria; não há endpoint público novo e nenhuma liberação de Basic Auth/network isolation. Não criar webhook apontando para rota inexistente ou alterar regras de acesso como efeito colateral.

## Limites explícitos

Esta etapa integra somente a primeira cobrança sandbox ao pedido de teste. Não implementa ainda D30/D60, assinatura D90, acerto financeiro executável Performance, contrato jurídico definitivo, recuperação de senha/IAM definitiva, fiscal ou provisionamento real. A UI conserva o rótulo de homologação. Não afirmar venda de produção concluída por sucesso de sandbox.

Testes locais: 110 verificações PHP, lint dos arquivos alterados e oito cenários unitários Python com subcasos de falha, duplicação, URL e divergência financeira. CI PostgreSQL/Chromium exercita intenção persistida → broker com transporte injetado → status confirmado → briefing, nega preço forjado e bloqueia aprovação manual; não utiliza chave ou dados reais.
