# PORTAL-F54 — Não exposição operacional do CreateProject sintético

Rastreamento: PR F53 → F54, 09/11/2026.

Verificação Laravel em `tests/Feature/PortalSiteCreateProjectOperationalBoundaryTest.php`:

- Varre arquivos PHP/JSON dos pontos de entrada conhecidos de rotas, bootstrap, config, providers, HTTP, jobs, console, listeners, observers, migrations e seeders. Bloqueia referências às classes F51-F53 e marcadores de fixture de criação de projeto.
- Assegura 404 em GET, POST, PUT e DELETE para caminhos de criação/envio de projeto sob `/portal`, `/api/portal` e `/labs`, mesmo com supostos campos `actor`, `authentication` e `tenantRef`.
- Um payload local que passa no validador F52 **continua incapaz de autenticar, criar pedido real, transmitir, aceitar revisão ou publicar**.

## Restrições

Teste negativo limitado aos pontos de entrada e rotas conhecidos. Não constitui auditoria AppSec independente, autenticação de produção, validação LGPD, análise de outras URLs ou prova de impossibilidade de execução por todo o código. Requer revisão em cada mudança de arquitetura/rotas. Não autoriza entrega externa, contratos, pagamentos, merge/deploy, VPS ou publicação.

**Gates pendentes:** IAM/MFA Portal-only, autorização persistida com isolamento de empresa, idempotência transacional, revisão de assinatura e replay, AppSec/DBA, LGPD, SEC-R03/Z4 e P0/D02/D03/T3/OPEN.
