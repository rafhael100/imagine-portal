# PORTAL-F41 — Teste de isolamento das gravações e relays sintéticos

**09/11/2026 · Laravel/PHP · sem cliente, rota ou entrega externa.**

## Por que existe

F38–F40 acrescentaram código útil para testar transações,
idempotência, outbox e fake relay. Essas classes vivem em
`App\Labs` e **não estão homologadas para usuários reais**.
O principal risco de regressão nesta fase é importá-las sem
querer em providers, jobs, rotas, comandos ou migrações do
Laravel, transformando testes em operações com dados reais.

O F27 já verificava que a rota `/up` era a única
registrada e não havia referência genérica a `App\Labs`
em alguns entrypoints. F41 amplia o escopo de vigilância
a objetos de escrita, outbox e relay.

## Testes

`PortalSyntheticWriteSurfaceIsolationTest.php` no PHPUnit
**Feature** do Laravel (não depende de PostgreSQL):

- Percorre recursivamente arquivos PHP/JSON em `routes`,
  `bootstrap`, `config`, `app/Providers`,
  `app/Http`, `app/Console`, `app/Jobs`,
  `app/Listeners`, `app/Observers` e
  `database/{migrations,seeders}`, além de
  `public/index.php` e `composer.json`.
  Falha se esses pontos de entrada passarem a referenciar
  as classes F38–F40, schemas SQL de laboratório ou flags
  de habilitação fictícias.
- Confirma que caminhos HTTP/JSON de pedidos, alteração de
  estados, outbox, notifications, webhooks e `/labs/f38..f40`
  respondem **404**, tanto GET como POST com payload de
  ator/empresa forjado.
- Verifica que resultados positivos e negativos dos
  laboratórios **nunca** autorizam HTTP, contratos, cobrança
  ou entrega externa.
- Confere que o sink F40 rejeita referências externas/paths,
  mantém somente referências DEMO em memória e simula
  falha única/repetição sem rede.

## Limitação fundamental

Esta varredura é uma **proteção negativa de regressão para
os arquivos atualmente conhecidos**. Não garante que uma
futura rota dinâmica, reflexão, serviço externo, biblioteca
carregada por autoload, configuração fora desses diretórios
ou refatoração não possa expor código inseguro.

A instalação operacional ainda exige emissor oficial do Portal,
MFA, tenancy, ownership, RLS e migrations aprovadas, segurança
de registros, outbox com transporte e ack reais, LGPD, auditoria,
revisão AppSec/DBA e independência contra restauração SEC-R03/Z4.

**PR DRAFT, sem merge/deploy, alterações em VPS/Cloudflare,
integração com clientes ou contratação de serviços.**
P0/D02/D03/T3/OPEN e demais gates seguem abertos.
