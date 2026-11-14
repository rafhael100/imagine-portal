# P0 — barreira de propriedade de pedido por cliente sob login SQL compartilhado (laboratório)

## Problema que esta etapa enfrenta

A prévia privada da PR #704 permite que contas fictícias distintas, dentro da mesma empresa, criem pedidos e consultem o próprio painel. Entretanto, o login PostgreSQL `purchase_rehearsal_a` tem permissões `SELECT` na tabela de clientes e na tabela de pedidos. Um acesso SQL direto usando esse login consegue consultar outros clientes da mesma organização, mesmo que a camada PHP filtre `customer_id`. Este é um **NO-GO de segurança para dados pessoais reais**.

A PR #694 provou o isolamento com logins SQL distintos por cliente, mas criar um papel PostgreSQL por usuário da plataforma não foi aprovado como arquitetura SaaS. Esta prova testa uma **segunda alternativa, ainda de pesquisa e revisão**: um único login SQL compartilhado que não possui privilégios de leitura/gravação nas tabelas, com três operações muito limitadas via funções `SECURITY DEFINER`.

## Fixture técnica nova

- SQL exclusivo de PostgreSQL18 descartável `portal_capability_ci`, recusando bancos de outros nomes. Nunca é instalado como migration na aplicação.
- Tabela de pedidos com `FORCE ROW LEVEL SECURITY` e **nenhum GRANT direto** ao login `portal_capability_ci`, sem `BYPASSRLS`, `SUPERUSER` ou permissão de `SET ROLE`.
- Chave HMAC fictícia em tabela privada que o usuário da aplicação **não pode ler**. Os testes representam o serviço autenticador de aplicação assinando um escopo *ação + organização + cliente + referência + expiração*. SQL recusa payload adulterado e prazo expirado / superior a dois minutos. As funções não são acesso autenticado de produção, não possuem controle de nonce e precisam de integração futura com identidade real validada.
- `create_owned_order`, `list_owned_orders` e `approve_owned_order` validam HMAC antes de consultar a tabela. Têm filtros SQL por empresa e proprietário imutáveis, idempotência por par empresa/cliente e restrição de produto.
- Uma mesma credencial SQL de teste cria e lê pedidos independentes de **dois clientes da mesma empresa e um de outra empresa**, sem permitir leitura direta, roubo de chave, forja de ator/tenant, troca de recurso, idempotência conflitante ou aprovação cruzada. Relogin SQL não altera a restrição.
- Testes PHP8.3 e PHP8.4 no GitHub Actions, PostgreSQL18 efêmero; nenhum teste usa senha ou dados pessoais reais.

## Limites e gates antes da aplicação

Este é **um recorte de prova de mecanismo**, não um patch do `CustomerPreviewStore` operacional. O login real compartilhado da prévia continua com acesso amplo às tabelas. O segredo HMAC está escrito no código de teste apenas para replicar as mesmas condições; não constitui custódia de credenciais, rotação ou controle anti-replay de um sistema real. `SECURITY DEFINER` amplia o impacto caso se cometa erro em código SQL, portanto precisa de auditoria especializada e papel proprietário **sem poderes superuser**, limitações `EXECUTE` e de `search_path`, testes de concorrência, verificação de sessão principal/MFA, revisão de LGPD, expiração, logging, backup e revogação.

**Não aplicar o SQL no banco existente ou na VPS**. O próximo gate precisa escolher a arquitetura final e adaptá-la ao fluxo real de registro/pagamento/briefing, com migração planejada, testes de ataque e rollback. Nenhuma permissão para contratar/publicar é inferida deste trabalho.

Status: **DRAFT / NO-GO / sem merge ou deploy.**
