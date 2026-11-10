# PORTAL-F85 — excluir componentes laboratoriais da imagem Docker

## Por que esta alteração era necessária

A stack de homologação privada F64 declara que serve apenas `GET/HEAD /up`, sem rotas de cliente, porém seu Dockerfile copiava `apps/portal/app` **inteiro**. Nessa pasta também existem classes de laboratório (por exemplo `app/Labs/IdentityF82` com conexão descartável de superuser e `app/Labs/IdentityF81` com MFA sintético). Elas não eram expostas pela rota, mas permaneciam no filesystem e no classmap, ampliando a superfície de código sem necessidade.

## Implementação

O Dockerfile F85 remove `app/Labs` **antes** de executar `composer dump-autoload --classmap-authoritative`. Não copia `apps/portal/labs`, `tests`, `database/migrations`, fixtures, documentos ou arquivos locais `.env`. Os laboratórios ficam apenas no repositório/runner de CI, fora do container executável.

O workflow existente de **Docker F64** recebeu uma regressão que inicia a imagem e confirma simultaneamente:

- `/up = HTTP 200`, todas as demais rotas 404, sem API/login/checkout/cliente.
- `app/Labs`, `labs`, `tests`, `database` e `.env` **ausentes** dentro da imagem.
- Classes sintéticas F61/F75/F81/F82 não são resolvidas pelo Composer autoload do contêiner.
- `127.0.0.1:18080` continua a única publicação de porta no host, sem banco de clientes ou execução de migrations.

Essa proteção reduz a superfície do contêiner privado. **Não significa** que o código operacional restante tenha passado por revisão independente nem autoriza abrir uma rota de autenticação. A lógica verdadeira de login e autorização continua não implementada para clientes, e o witness SEC-R03, IAM/RLS, KMS, AppSec/DBA, TLS e VPS ainda exigem homologação.

PR DRAFT / sem deploy, merge, novos custos ou acesso à VPS.
