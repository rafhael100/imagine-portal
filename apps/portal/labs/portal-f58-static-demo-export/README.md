# PORTAL-F58 — prévia estática navegável do Portal (somente offline)

## Objetivo
Permitir que o responsável visualize as **sete telas Blade demonstrativas** que já existem no Laravel, sem habilitar autenticação, criar APIs/rotas ou conectar serviços. Não alterar as telas de origem, que seguem read-only.

## Como usar em ambiente de desenvolvimento autorizado

Somente em `APP_ENV=local` ou `APP_ENV=testing`, com dependências instaladas na futura VPS de homologação ou estação local:

```bash
cd apps/portal
php artisan portal:demo-export
```

O comando escreve oito HTMLs estáticos em `storage/app/portal-demo-preview/`: `index.html` e as sete seções (overview, services, available, contracts, modules, journey, requests). O índice e uma barra estática permitem navegar entre as telas em um navegador, sem JavaScript, formulários, dados pessoais, serviços externos ou execução de PHP. HTML inclui CSS inline já existente nos componentes Blade e avisos de demonstração. Todos os arquivos ficam com modo 0600 e a pasta com 0700; por segurança **não serão sobrescritos**. Remova essa pasta manualmente após a revisão se desejar regenerar com alterações. Nunca exponha o diretório em `public/` ou copie os arquivos para hospedagem pública.

## Limites
- Disponível apenas em ambiente local/testing, não em `production` ou `staging`.
- Nenhuma nova rota HTTP, login, sessão ou aprovação; a página HTML não confere acesso a dados.
- Nenhum cliente, CRM, Sites Engine, pagamento, webhook, VPS, Cloudflare, banco ou automação são conectados.
- A exportação é revisão visual, não autenticação, controle LGPD, aceite de contrato ou homologação AppSec.
- A UI de origem continua sem navegação funcional; **a navegação da prévia existe apenas no HTML exportado**, não na aplicação operacional.

Quatro testes de Feature verificam oito arquivos, links locais, ausência de formulários/scripts/URLs externas, ausência de consultas ao banco, não abertura de rotas, recusa de sobrescrita e bloqueio em ambiente de produção. A suíte global Laravel deve passar nos PHP 8.3 e 8.4.

**DRAFT / sem merge/deploy, sem gastos novos.**
