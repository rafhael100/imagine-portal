# Portal SEO F120 — prévia interna da auditoria do build

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua [F118/F119 #857](https://github.com/rafhael100/imagine-portal/pull/857).

## Entrega
A view Blade `resources/views/seo/demo-build-readiness.blade.php` mostra um painel leve, com identidade Imagine `#F9941D`, em português, responsivo e sem JavaScript.

Ela aceita apenas o contrato **já resumido** `portal-seo-f118-static-build-lab-v1`, e somente quando os indicadores de publicação, exibição a clientes e site real verificado são `false`. Qualquer ausência de relatório ou claim de autoridade produz **N/D**, não um zero falso.

Indicadores ilustrativos: total de páginas HTML do build, páginas municipais encontradas/ausentes, divergências de idioma, erros técnicos e vazamento de prévias privadas. Todos são diagnósticos locais, não dados de SEO em produção.

## Bloqueios preservados
- Nenhuma rota HTTP, API, formulário, script, OAuth, botão de publicação ou banco foi criada.
- Não recebe ou apresenta URL, domínio, título de cliente, tenant ID, token, mensagem técnica ou caminho do arquivo.
- Meta `noindex,nofollow,noarchive`; marcação explícita de demonstração interna e ausência de autorização de publicação.
- Nunca exibe resultados numéricos quando recebe campos de privilégio contraditórios, valores inválidos ou relatório ausente.
- Nenhum número de Visitas → Leads → Clientes é fabricado; esse funil depende de Analytics e CRM reais.

## Testes
`php artisan test --filter=SeoBuildReadinessDemoViewTest` cobre visual, XSS, ausência de dados e ausência de rotas.

Para tornar a UI produtiva ainda faltam sessão/MFA do Portal, permissão tenant+serviço, origem assinada/autenticada da auditoria do Engine, trilha de revisão, armazenamento por empresa, homologação e aceite LGPD/AppSec.

**Nenhuma alteração de main, VPS, clientes, DNS ou deploy.**
