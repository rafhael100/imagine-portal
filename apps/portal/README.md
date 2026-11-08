# Novo Portal Imagine — fundacao F1-01-A

Este diretório contém uma **fundação Laravel 13 isolada e ainda não homologada**. É o primeiro recorte de código do novo Portal Imagine, preparado em branch de desenvolvimento exclusiva e submetido a revisão; **não é um portal pronto para uso**.

## Decisões preservadas

- **Laravel + PostgreSQL**: aplicação e base do Portal independentes do Imagine CRM.
- **Logins separados**: não há SSO, usuário real, credenciais ou sessões compartilhadas com o CRM.
- **Legado Node/SQLite**: o projeto antigo era protótipo e seus dados são de teste; reaproveitar principalmente ideias e fluxos de negócio. Não foram movidos nem apagados arquivos da raiz.
- **Comercial**: regras de catálogo e ofertas D04-FASE-A aprovadas como direção, mas nenhuma rota de compra, contrato real, cálculo final de preço, PSP ou cobrança foi implementada nesta fundação.
- **Ausente por projeto**: tenancy real, RBAC/MFA, tabelas de usuários/contratos, migrações de dados, API de CRM, site, leads, n8n, fiscal, entrega e produção.
- **Health-check**: somente a rota de framework `GET /up` está registrada; ela comprova bootstrap, **não** a saúde de banco ou dos outros sistemas.
- **Sem deploy, homologação operacional ou acesso à VPS**. A fundação e os testes sintéticos tiveram validação automatizada no GitHub Actions; isso não significa autorização para uso real.

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

**Atualização posterior à preparação inicial:** o `composer.lock` está versionado nesta branch e deve ser preservado. Instalar dependências com `composer install` conforme o lockfile, sem executar `composer update` por conveniência. [CI Laravel PHP 8.3/8.4 da F1-01-B-A](https://github.com/rafhael100/imagine-portal/actions/runs/42167989718) concluiu com sucesso (18 testes, 36 asserções por ambiente) no commit de referência anterior a esta correção; executar novamente o CI para validar o novo HEAD. O workflow Node na raiz é independente e não substitui os testes Laravel.

## Verificações e escopo de revisão

- Verificar o diff contra a `main`: somente `apps/portal/` pode ser modificado.
- Validar JSON do Composer, XML do PHPUnit, sintaxe PHP de cada arquivo e restrições de `.env.example`.
- Com dependências instaladas localmente, testar `GET /up => 200` e ausência de rotas de cliente, login, checkout e leads.
- Confirmar que as pastas `server/`, `public/`, testes Node e banco SQLite antigos estão intactos.
- Revisão humana do SHA, dos arquivos, da ausência de dados/segredos e dos resultados de CI (ou motivo de eventual teste não executado).
- **Não realizar merge automático nem deploy; somente F1-01-B-A está autorizada nesta branch filha.** B-B, B-C e F1-02+ exigem autorização específica.

Planejamento está na branch documental `docs/portal-f1-00-2026-11-05`, PR #10, arquivo:
`docs/portal/PORTAL-F1-01-FUNDACAO-TECNICA-ESCOPO-CONTROLADO-PREPARACAO-v0.1-2026-11-05.md`.

## Etapa F1-01-B-A — somente domínio e testes fictícios

A branch filha `dev/portal-f1-01-b-domain-2026-11-05` adiciona objetos imutáveis de identidade própria do Portal, organização, membership, papéis e permissões. O avaliador puro exige **ator, organização ativa, empresa proprietária do recurso, permissão conhecida explícita e vínculo ativo**, negando quando faltar qualquer condição. Papéis não são direitos implícitos.

A suíte PHPUnit agora inclui `tests/Unit` e `tests/Feature`; a validação CI PHP 8.3/8.4 usa o lockfile existente. **Não há HTTP de autorização, cadastro/login, banco, migrações, SSO, armazenamento ou acesso a dados reais**. O avaliador não autentica ninguém e só poderá receber vínculos de uma futura fonte servidora confiável, sujeita a aprovação D02-SEC/P0.

Escopo e autorização: `docs/portal/PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md` na branch documental. Nenhum merge do PR draft, deploy ou etapa B-B/B-C autorizados.

## Segurança de origem — recorte documental F1-01-B-A

O avaliador `OrganizationAuthorizationService` recebe `AuthorizationContext` e `OrganizationMembership` **já construídos**: não autentica a pessoa, não confirma a origem do vínculo, não comprova titularidade do recurso e não verifica expiração ou revogação na fonte oficial. Uma `OrganizationMembership` instanciada em memória **não constitui comprovante de autorização**. Os valores devem futuramente vir de autenticador **exclusivo do Portal** e repositórios oficiais validados no servidor, com mecanismos de revogação, controles de concorrência e segregação ainda dependentes dos gates D02-SEC, D03 físico e P0-DOC06.

`PortalUserId` é uma **string opaca**, não um certificado ou namespace criptograficamente autenticado: IDs de outros serviços com o mesmo texto não podem ser diferenciados pelo avaliador puro. O Portal e o CRM não compartilham identidade humana, sessão ou credenciais. Não transmitir membership, permissões nem `AuthorizationDecision` de clientes externos como prova de autorização; a camada de execução futura deve autenticar, validar e calcular a decisão em um contexto servidor confiável. Nenhuma dessas integrações foi implementada nesta B-A.
