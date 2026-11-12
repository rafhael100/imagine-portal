# GSC Slice V — Row Level Security (RLS) por tenant no PostgreSQL

O Lote V acrescenta uma defesa adicional **no banco** às quatro tabelas de integração GSC (states PKCE, grants, eventos de auditoria e candidatos a token cifrados). As policies são aplicadas **somente em migração de revisão**, testada no PostgreSQL descartável do GitHub Actions.

## Políticas

A migration `2026_11_12_000005_enable_portal_gsc_tenant_rls.php` ativa `ENABLE ROW LEVEL SECURITY` e `FORCE ROW LEVEL SECURITY` em todas as tabelas. A política padrão exige:

`tenant_ref = current_setting('app.tenant_ref', true)`

nos predicados `USING` e `WITH CHECK`, tanto para leitura/remoção quanto para gravação/atualização. Sem a variável de sessão, uma conexão **não privilegiada** não visualiza registros e não pode criar rows. O tenant deve ser definido pelo backend **dentro da transação**, com `SET LOCAL`, e não ser aceito de parâmetros do navegador.

**A variável `app.tenant_ref` não é uma identidade autenticada nem uma barreira contra SQL injection.** Um papel capaz de executar SQL arbitrário pode alterá-la; segurança real requer conexão com privilégios mínimos, parâmetros SQL seguros e resolução de membership/MFA independente. Administradores/superusers PostgreSQL podem contornar RLS; não usar papéis de dono/superuser em requisições de clientes.

## Testes

O laboratório CI cria um papel `gsc_rls_probe` **NOBYPASSRLS e não proprietário**, sem acesso privilegiado, e dados fictícios de duas empresas em cada uma das quatro tabelas. Confirma:
- políticas ligadas e forçadas;
- ausência de visibilidade sem `app.tenant_ref`;
- visibilidade de apenas uma empresa após `SET LOCAL` dentro de uma transação;
- retorno ao bloqueio padrão após `COMMIT`, evitando vazamento de tenant entre requisições que reutilizam conexão;
- `DELETE` de outra empresa retorna zero e tentativas de `INSERT`/`UPDATE` cruzadas falham.

A migração é revertida ao fim do job. As quatro tabelas são descartáveis no CI; nenhuma policy foi aplicada em produção, CRM ou infraestrutura externa.

## Bloqueios para staging

Ainda faltam o middleware real de sessão Portal, MFA/RBAC e membership autenticados, papéis PostgreSQL dedicados e testados, estratégia de `SET LOCAL`/pooling segura, auditoria/observabilidade e revisão independente DBA/AppSec/LGPD. RLS é defesa em profundidade; **não substitui grants reais do Google nem autoriza exibir dados do cliente**.

**DRAFT/NO-GO:** sem merge dev/main, deploy, alterações no CRM, conexão Google ou custo adicional.
