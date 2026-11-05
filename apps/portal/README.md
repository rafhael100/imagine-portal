# Novo Portal Imagine — fundacao F1-01-A

Este diretório contém uma **fundação Laravel 13 isolada e ainda não homologada**. É o primeiro recorte de código do novo Portal Imagine, preparado em branch de desenvolvimento exclusiva e submetido a revisão; **não é um portal pronto para uso**.

## Decisões preservadas

- **Laravel + PostgreSQL**: aplicação e base do Portal independentes do Imagine CRM.
- **Logins separados**: não há SSO, usuário real, credenciais ou sessões compartilhadas com o CRM.
- **Legado Node/SQLite**: o projeto antigo era protótipo e seus dados são de teste; reaproveitar principalmente ideias e fluxos de negócio. Não foram movidos nem apagados arquivos da raiz.
- **Comercial**: regras de catálogo e ofertas D04-FASE-A aprovadas como direção, mas nenhuma rota de compra, contrato real, cálculo final de preço, PSP ou cobrança foi implementada nesta fundação.
- **Ausente por projeto**: tenancy real, RBAC/MFA, tabelas de usuários/contratos, migrações de dados, API de CRM, site, leads, n8n, fiscal, entrega e produção.
- **Health-check**: somente a rota de framework `GET /up` está registrada; ela comprova bootstrap, **não** a saúde de banco ou dos outros sistemas.
- **Sem deploy, CI Laravel homologado ou acesso a VPS**.

## Origem do código base

Arquivos de inicialização e configurações do esqueleto oficial `laravel/laravel` branch `13.x`, referência GitHub `def7ce14811161f3c689a71ca8abc1369da44bd6`, adaptados com as condições acima. O esqueleto upstream utiliza licença MIT; consultar `https://github.com/laravel/laravel` e sua licença. O código novo da Imagine permanece privado/proprietário.

## Instalação e testes locais — apenas em ambiente isolado autorizado

Necessários PHP **8.3+**, Composer 2 e extensões exigidas pelo Laravel 13 (incluindo cURL, DOM, Mbstring, PDO e XML). Para futura conexão de banco, também `pdo_pgsql` e banco **exclusivo do Portal**, não o banco do CRM.

```bash
cd apps/portal
composer validate --strict
composer install --no-interaction
cp .env.example .env
php artisan key:generate
php artisan route:list
php artisan test
```

A execução dos comandos acima não foi autorizada na VPS. `composer install` só deve baixar dependências em ambiente permitido; não rodar `php artisan migrate`, comandos de provisionamento, checkout ou integração. `.env.example` contém **placeholders**, não acesso verdadeiro a PostgreSQL. Não comitar `.env`, arquivos financeiros, segredos, token, `vendor/` ou dados pessoais.

O `composer.lock` **não foi gerado neste scaffold** (Composer/rede indisponíveis no ambiente de elaboração). Instalações futuras precisarão resolver e revisar versões e registrar lockfile antes de homologação; sem ele não declarar builds reproduzíveis. A execução de testes completos do framework depende dessas bibliotecas e extensões. O workflow Node na raiz não valida automaticamente esta aplicação.

## Verificações e escopo de revisão

- Verificar o diff contra a `main`: somente `apps/portal/` pode ser modificado.
- Validar JSON do Composer, XML do PHPUnit, sintaxe PHP de cada arquivo e restrições de `.env.example`.
- Com dependências instaladas localmente, testar `GET /up => 200` e ausência de rotas de cliente, login, checkout e leads.
- Confirmar que as pastas `server/`, `public/`, testes Node e banco SQLite antigos estão intactos.
- Revisão humana do SHA, dos arquivos, da ausência de dados/segredos e do motivo dos testes não executados.
- **Não realizar merge automático, deploy ou abrir F1-01-B/C e F1-02+ por inferência.**

Planejamento está na branch documental `docs/portal-f1-00-2026-11-05`, PR #10, arquivo:
`docs/portal/PORTAL-F1-01-FUNDACAO-TECNICA-ESCOPO-CONTROLADO-PREPARACAO-v0.1-2026-11-05.md`.
