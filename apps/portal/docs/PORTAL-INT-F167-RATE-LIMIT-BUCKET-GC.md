# PORTAL-INT-F167 — Limpeza limitada de buckets de rate limit expirados

O Portal Node.js/SQLite registra limites por IP e digest de e-mail em `rate_limits(key,count,reset_at)`. Essa tabela era atualizada quando a mesma chave reaparecia, mas **nunca removia chaves diferentes depois de expirarem**. Entradas novas de login/cadastro, inclusive e-mails não existentes ou IPs de clientes diferentes, podem acumular linhas sem limite ao longo de meses.

## Implementação

`server/app.mjs` passa a executar `DELETE FROM rate_limits WHERE reset_at <= ?` **no máximo uma vez a cada 5 minutos por processo**, dentro da função de rate limit que já controla login e cadastro. A limpeza remove somente janelas vencidas, preserva contadores de janelas ativas e é feita antes de calcular o limite da requisição atual.

A primeira chamada de rate limit depois que o servidor sobe executa a limpeza. A frequência evita um `DELETE` em todo POST. Em caso de erro de armazenamento, a operação falha, em vez de conceder exceção ao limite.

## Testes

A suíte HTTP real do Portal adiciona buckets vencidos de e-mail e cadastro ao SQLite em memória, junto de uma cota ativa. Após um cadastro inválido, exige que as duas entradas vencidas tenham sido removidas e a cota ativa permaneça idêntica. Em seguida, verifica que a cota de 8 tentativas de cadastro continua válida e a nona recebe HTTP 429.

## Escopo e limitações

Essa mudança não altera limiares, hashing dos e-mails, cookies, senhas, permissões, custos ou integração comercial. É uma otimização de segurança e armazenamento **para o servidor Node/SQLite atual**, não para o futuro Laravel/PostgreSQL. Em ambiente com vários processos e SQLite compartilhado, a coleta é redundante no máximo por processo, mas a eliminação por `reset_at` é idempotente. Configurações reais de ingress e logs continuam sujeitas à revisão AppSec independente.

**NO-GO:** PR draft em GitHub. Sem deploy, merge `main/dev`, acesso a clientes ou despesas.
