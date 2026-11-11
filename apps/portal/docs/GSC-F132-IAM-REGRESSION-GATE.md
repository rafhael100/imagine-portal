# GSC-F132 — Gate de regressão IAM + RLS no PostgreSQL 18

## Lacuna de segurança

A F130 já validava em conjunto as migrations reais de identidade, permissões SEO, propriedades e referências do futuro cofre. No entanto, o workflow era acionado apenas por alterações nas migrations e nos laboratórios PostgreSQL. Uma alteração futura no código de autorização do Portal ou nos componentes Google Search Console poderia ignorar toda essa prova de banco, mesmo mexendo no processo que dependerá dessas tabelas.

## Implementação

O workflow existente `.github/workflows/portal-gsc-f130-full-schema-pg18.yml` agora também executa quando mudarem os diretórios críticos:

- `app/Domain/SeoReview` — propriedades, estado/URL/callback OAuth e dados SEO.
- `app/Domain/Authorization` e `app/Domain/PortalAccess/Authority` — permissão, sessão, membership e propriedade.
- `app/Providers/AppServiceProvider.php` — bindings reais de autoridade.
- `config/google_search_console.php` e os testes associados de segurança GSC/Portal.

Durante a prova PostgreSQL 18 × PHP 8.3/8.4, após instalar as migrations F66, F76, F113 e F123–F125, o workflow também roda explicitamente os testes de integração F131, as regras de configuração default-deny do Google OAuth e os bindings deny-all reais do Laravel. Em seguida verifica F126, os grants `seo.gsc.connect`, as policies FORCE RLS fechadas e a reversão segura do esquema, como a F130 já fazia.

## Garantias e limites

Uma alteração em autenticação não deve passar sem novamente testar tanto a camada PHP quanto o banco. Os testes continuam usando banco, usuário, sessão e propriedades **sintéticos** em runner descartável, nunca dados do Google ou da Leaseweb.

Esse gate evita regressões óbvias, mas não substitui uma análise formal de sessão/MFA, uso de token, concorrência, RLS, consentimento, LGPD ou resposta autenticada do Google. Não habilita serviço real, rotas OAuth ou grants. PR draft, sem `main`, credenciais, custo de fornecedor ou deploy. Antes de produção, é obrigatório parecer independente AppSec/DBA e homologação VPS.
