# PORTAL-F1-01-A — Relatório da validação PHP/Laravel e lockfile versionado

**Data:** 05/11/2026  
**Etapa:** fundação Laravel 13 da aplicação Portal Imagine.  
**Estado:** **VALIDAÇÃO AUTOMATIZADA DO SCAFFOLD CONCLUÍDA COM SUCESSO EM PHP 8.3 E 8.4; PR DE CÓDIGO EM DRAFT, NÃO INTEGRADO E NÃO HOMOLOGADO PARA PRODUÇÃO.**

## 1. Autorização e escopo aplicado

O responsável aprovou expressamente a validação controlada da F1-01-A: criar workflow próprio PHP/Laravel, instalar dependências, executar testes, corrigir defeitos dentro do recorte e atualizar o PR #11, preservando `main`, VPS, serviços reais e demais gates.

- Repositório: `rafhael100/imagine-portal`.
- Branch de código: `dev/fast-track-portal-2026-11-05`.
- [PR #11 — código Laravel, draft](https://github.com/rafhael100/imagine-portal/pull/11).
- HEAD verificado após validação: `098cdfd50024ea872861f3ba3a058409e5f976b7`.
- Base `main` intacta: `50b683a499e674641df5749051f3d05f7ac3f627`.
- [Workflow de validação](https://github.com/rafhael100/imagine-portal/blob/dev/fast-track-portal-2026-11-05/.github/workflows/laravel-f1-01-a.yml).
- [Lockfile versionado](https://github.com/rafhael100/imagine-portal/blob/dev/fast-track-portal-2026-11-05/apps/portal/composer.lock).

## 2. Falha inicial, correção e revalidação

1. **Primeira execução:** `composer validate`, `composer update`, verificação de sintaxe PHP, criação de chave descartável e `route:list --path=up` passaram; entretanto, `php artisan test` falhou em PHP 8.3 e 8.4 porque o comando `test` não estava registrado no scaffold sem pacote de desenvolvimento apropriado.
2. **Correção pontual no escopo:** adicionar `nunomaduro/collision ^8.6` às dependências de desenvolvimento em `apps/portal/composer.json`. Não foram incluídos logins, migrations reais, provedores externos ou mudanças no legado.
3. **Segunda execução:** `php artisan test` passou em ambas as versões PHP; **2 testes e 5 asserções por matriz**.
4. **Lockfile:** gerado em runner isolado, revisado e versionado **somente em `apps/portal/composer.lock`** por ação temporária restrita à branch de desenvolvimento; workflow temporário foi **removido** após uso. 75 pacotes em `packages` e 27 em `packages-dev`, contendo `laravel/framework v13.40.0`, `nunomaduro/collision v8.9.5`, `phpunit/phpunit 12.5.43`.
5. **Workflow permanente:** usa somente `contents:read`, `composer install` a partir do lock, `composer validate --strict`, verificação de que o lock não é alterado, lint PHP, chave temporária, teste do health-check `GET /up` e de ausência de rotas de negócio.
6. **Auditoria:** `composer audit --locked` nos dois runners não encontrou alertas de segurança publicados **na data da execução**; resultado pontual, não garantia futura.
7. **Validação final:** [GitHub Actions run #42116943252, concluído com sucesso](https://github.com/rafhael100/imagine-portal/actions/runs/42116943252), PHP 8.3 e PHP 8.4 **success**. A execução de PR [#42116952698](https://github.com/rafhael100/imagine-portal/actions/runs/42116952698) também terminou **success**. O workflow legado Node permanece separado e teve execução de PR **success** no mesmo HEAD.
8. **Escopo Git:** comparação da branch de código com `main` identificou alterações somente em `apps/portal/**` e em `.github/workflows/laravel-f1-01-a.yml`. Nenhuma alteração no servidor Node antigo, no banco SQLite, em produção ou na `main`.

## 3. Matriz de evidências

| Controle | PHP 8.3 | PHP 8.4 | Evidência |
|---|---|---|---|
| PHP/Composer/extensões em runner | **PASSOU** | **PASSOU** | Jobs CI de 05/11 |
| `composer validate --strict` | **PASSOU** | **PASSOU** | Workflow final |
| `composer install` do lock versionado | **PASSOU** | **PASSOU** | Workflow final |
| `git diff --exit-code -- composer.lock` | **PASSOU** | **PASSOU** | Não regenerou o lock |
| `composer audit --locked` | **PASSOU** | **PASSOU** | Sem alertas encontrados na data |
| `php -l` nos arquivos PHP da fundação | **PASSOU** | **PASSOU** | Workflow final |
| `GET /up` e rotas ausentes | **PASSOU** | **PASSOU** | 2 testes / 5 asserções por ambiente |
| Repositório legado preservado | **CONFIRMADO NO DIFF** | N/A | Comparação Git |
| CI Node legado | **PASSOU (separado)** | N/A | Workflow existente, não substitui Laravel |

## 4. Limites desta validação

- **Não foram exercitados:** PostgreSQL real, migrations, usuários, login/MFA, autorização multiempresa/RBAC, conciliação/PSP, CRM, formulários/leads, publicação de site, n8n, Universidade, Performance AI, deploy, backup/restauração, auditoria de produção e testes de carga.
- O sucesso do `GET /up` indica somente que o bootstrap respondeu; não demonstra saúde operacional de banco de dados ou integrações externas.
- Auditoria Composer sem alertas hoje não equivale a auditoria de segurança completa, nem dispensa atualizações periódicas.
- **D02-SEC, D03 físico, D04-P02/B/C, P0-DOC01–14, SEG e gates de infraestrutura continuam abertos.**
- PR #11 e o PR #10 documental permanecem **draft** e **não mesclados**; a autorização de validação **não** autorizou merge nem deploy. `main` e VPS preservadas, sem dados reais alterados.
- **F1-01-B/C e F1-02+ não foram autorizadas** por esta validação.

## 5. Próxima deliberação recomendada

**Revisar o PR #11** e, se adequado, decidir explicitamente sobre o destino de integração controlada **na branch de desenvolvimento, nunca diretamente em `main` por inferência**. Separadamente, detalhar e autorizar a F1-01-B (interfaces de empresas, usuários e permissões somente com identidades fictícias) preservando D02-SEC e isolamento multiempresa.

**Situação final:** fundação Laravel 13 com CI reproduzível e testes iniciais aprovados, pronta para revisão humana; **não é uma aplicação operacional pronta para implantação**.
