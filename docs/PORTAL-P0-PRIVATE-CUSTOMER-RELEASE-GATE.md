# PORTAL P0 — gate de homologação privada para cadastro e contratação individual

**Status: somente revisão, NO-GO para promoção automática, clientes ou produção.**

## O que já é comprovado em CI

O candidato da PR #716 combina os quatro CTAs reais do Imagine Comercial com cadastro e login **fictícios**, pagamento simulado, briefing durável e painéis cliente/admin no Laravel. PostgreSQL 18 usa `FORCE RLS` com asserção HMAC por cliente ou escopo de administrador emitida na aplicação de teste. A mesma CI valida que o SQL compartilhado, sem a asserção, não pode listar clientes nem pedidos; o segredo verificador está fora dos privilégios do login SQL. Os POSTs de pedidos têm quota de 12 alterações/minuto por cliente autenticado. Os templates de cadastro/checkout usam `no-store`, CSP, `frame-ancestors none`, `noindex` e `no-referrer`.

## Ordem obrigatória antes de substituir a prévia atual na VPS

1. **Congelar a versão atual:** registrar HEAD do worktree, imagem/container IDs, Nginx `nginx -T`, localização real de Basic Auth, host TLS e bindings de Docker. Confirmar backup de configuração e cópia restaurável do **volume isolado de testes**. Nunca usar `docker compose down -v` na VPS.
2. **Revisar identidade:** o produto real continua **NO-GO** pela issue #64 anti-rollback. Cadastro `demo-*` é apenas laboratório: não permitir dados reais, pagamentos, NF, contratos, Sites ou CRM. Revisão AppSec/DBA independente exige segredo fora do runtime público, rotação, anti-replay, delegação MFA, fonte de membership e rollback seguro.
3. **Validar Nginx/TLS:** todas as rotas `/_preview/portal`, `/register`, `/shop`, `/shop/orders/*`, `/client/*`, `/admin/*`, `/logo` e `/_rehearsal/purchase*` devem ficar **atrás da mesma autenticação Basic existente**, antes do Laravel; apenas o gateway privado `127.0.0.1:18083` pode ouvir HTTP local. Não criar porta pública, host alternativo ou bypass por barra final.
4. **Teste externo anônimo de perímetro (somente após o passo 3):** um operador autorizado executa, voluntariamente, `python3 apps/portal/ops/private-purchase/check_public_boundary.py --probe`. O script **não usa credenciais**, exige HTTPS válido e **401 com desafio Basic nas 18 rotas fixas**; qualquer 302/403/404/200 ou TLS inválido representa **NO-GO**. Na GitHub CI usa-se só `--self-test`, que não faz conexões externas.
5. **Teste interno de PostgreSQL:** `customer-schema.sql` no banco `portal_purchase_rehearsal`, papéis restritos sem BYPASSRLS, `FORCE RLS` nas tabelas `customers` e `customer_orders`, funções de lookup e claims em estado revisado. Confirmar que nem acesso direto SELECT aos pedidos nem SELECT do segredo HMAC é permitido ao runtime sem claim válida. A rotina CI `check_customer_schema_ci.sh` serve apenas para banco **descartável** e não é uma migration da VPS.
6. **Exercitar manualmente a jornada privada autorizada:** entrar via Basic, registrar duas contas **fictícias** de mesma empresa, testar Onepage, Landing Page, Google/Meta Performance, resultados simulados e briefing, provar isolamento entre clientes e administração, logout e re-login. Reiniciar **somente** a stack privada e verificar os mesmos pedidos depois. Não reutilizar credenciais pessoais.
7. **Rollback:** se falhar qualquer etapa, não publicar: conservar a prévia anterior, restaurar configuração Nginx comprovada por `nginx -t`, voltar à imagem aprovada e preservar integralmente o banco de ensaio e os logs sanitizados. Registrar o resultado na PR #716 e issue #669 antes de novo procedimento.

## Não confundir com autorização de produção

O login de desenvolvimento e uma assinatura HMAC emitida dentro do runtime sintético **não comprovam identidade real**, consentimento LGPD, membership multiempresa ou revogação resistente a restauração. A questão #64 permanece OPEN. A aprovação da CI não autoriza merge em dev/main, deployment, DNS, compra real ou cadastro público.
