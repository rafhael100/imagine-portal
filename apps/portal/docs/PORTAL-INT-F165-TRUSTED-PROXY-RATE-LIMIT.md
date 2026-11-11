# PORTAL-INT-F165 — Rate limits de autenticação por IP correto sob proxy confiável

## Correção no Portal Node.js/SQLite operacional

No servidor `server/app.mjs`, as rotas de cadastro e login usavam `req.socket.remoteAddress` para calcular o limite de tentativas por IP. Isso é seguro quanto a cabeçalhos HTTP falsificados, porém numa implantação atrás de proxy reverso local **todos os clientes podem aparecer com o mesmo IP do proxy**, criando bloqueio coletivo indevido depois de apenas oito cadastros ou vinte logins.

O código já possui a função `remoteIp(req)`, usada pela integração de cobrança, que só considera `X-Forwarded-For` se **`APP_TRUST_PROXY=true`** e a conexão TCP vier de loopback (`127.0.0.1`, `::1` ou representação mapeada). Caso contrário, ignora o cabeçalho enviado pelo cliente e usa o IP TCP direto.

F165 passa a usar essa mesma função para `limit('register:'+ip)` e `limit('login:'+ip)`, mantendo o limite independente por hash de e-mail no login e todos os demais controles de autenticação.

## Testes automatizados

A suíte `tests/portal.test.mjs` executa:
- Com `trustProxy=true` e proxy local, 8 tentativas inválidas do IP sintético A e 8 de B são contabilizadas **separadamente**; a nona tentativa de cada um recebe HTTP 429.
- Com `trustProxy=false`, o cliente altera `X-Forwarded-For` em cada requisição sem escapar do mesmo limite por IP TCP; a nona tentativa também recebe HTTP 429.

São testes de HTTP reais contra a aplicação em memória, não apenas asserts sobre strings.

## Requisito crítico de implantação

**Nunca habilitar `APP_TRUST_PROXY=true` em servidor diretamente acessível por clientes, proxy não confiável ou proxy que preserve `X-Forwarded-For` recebido do cliente.** O proxy na mesma máquina deve remover/sobrescrever esse cabeçalho com o IP remoto verificado, e o Node deve continuar acessível somente pelo loopback. Um proxy que apenas acrescente um IP à cadeia pode permitir spoofing do primeiro elemento do `X-Forwarded-For`. Essa configuração requer validação AppSec/infra de ingress antes de produção.

Não modifica tempo dos limites, senhas, preço, API de pagamento, sessão Laravel/PostgreSQL, RLS, produção, `main` ou `dev`. Sem custos, deploy ou credenciais reais.
