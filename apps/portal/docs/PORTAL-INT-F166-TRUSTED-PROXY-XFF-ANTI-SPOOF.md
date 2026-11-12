# PORTAL-INT-F166 — Impedir spoofing do primeiro IP de X-Forwarded-For

## Falha encontrada no hardening do proxy reverso

A F165 unificou o cálculo de IP para cadastro, login e cobranças por meio de `remoteIp(req)`, que confia em `X-Forwarded-For` **somente se `APP_TRUST_PROXY=true` e a conexão TCP vem de loopback**. Contudo, a implementação original lia o **primeiro** IP da cadeia. Se um cliente externo incluísse um `X-Forwarded-For` falso e o proxy local **acrescentasse** o endereço TCP verdadeiro ao final da cadeia, o primeiro elemento seria controlado pelo atacante.

Isso pode permitir contornar rate limits por IP em sistemas com proxy que faz append (não substituição) do cabeçalho. O limite independente por e-mail ajuda, mas não elimina a falha por IP.

## Correção

`remoteIp(req)` agora considera **o último endereço** da cadeia `X-Forwarded-For` em vez do primeiro — somente após verificar `APP_TRUST_PROXY`, peer TCP local e sintaxe do IP com `node:net.isIP()`. Endereço inválido ou cabeçalho ausente causa fallback para o endereço TCP; sem `APP_TRUST_PROXY`, qualquer header fornecido pelo cliente é ignorado.

A mudança protege também a captura de IP no checkout que já reutilizava essa função. Não modifica valores de cobrança, autorização, prazos de sessão ou endpoints.

## Provas

Novo teste HTTP real em `tests/portal.test.mjs` envia oito tentativas inválidas de cadastro com **oito primeiros IPs falsos diferentes**, mas sempre o mesmo último endereço que representa o peer verificado pelo proxy. O servidor deve consumir uma única cota de 8 tentativas e bloquear a nona com HTTP 429, enquanto um segundo último IP continua com cota independente. A F165 já exercita as variantes sem proxy confiável e com dois clientes distintos.

## Configuração obrigatória antes de deploy

A prova pressupõe **um único proxy local confiável**, que sobrescreve `X-Forwarded-For` com o IP real ou acrescenta ao fim da cadeia o endereço TCP efetivamente observado. Com múltiplos proxies, CDN ou ingress complexos, a cadeia precisa ser analisada contra a topologia; o último IP poderá ser outro proxy e causar bloqueios compartilhados. O Node deve continuar acessível apenas por loopback e `APP_TRUST_PROXY=false` até homologação de configuração. **Não** basta ativar a variável de ambiente.

**NO-GO:** revisão independente de infraestrutura/AppSec e homologação necessárias. Mudanças somente em branch draft GitHub, sem produção, `main/dev`, credenciais reais ou despesas.
