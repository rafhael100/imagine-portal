# PORTAL-F86 — consolidação supervisionada para homologação (revisão de segurança)

Data-base: **10/11/2026**. Comparação: `dev/fast-track-portal-2026-11-05` → `review/portal-f86-secure-runtime-auth-convergence-2026-11-10`. PR draft, sem merge ou implantação.

## Código revisável incorporado

A linha contém todos os avanços do Portal até F80 (PR #215) e os novos recortes:

- **F81 / PR #216:** integração do finalizador de MFA com `organization_revision` e e-mail confirmado, na mesma transação SQL, negando a sessão quando os dados de organização ficam desatualizados.
- **F82 / PR #219:** demonstração E2E PostgreSQL Argon2id → limitação por escopo HMAC e origem de teste → bearer aleatório persistido somente em SHA-256 na sessão `pending_mfa` de 5 minutos → TOTP de segredo cifrado da identidade → permission gate restrito da F76. Casos negativos de senha, tenant, MFA pendente, token, revisões, grant e brute force. O código é exclusivamente de laboratório PHP CLI.
- **F83 / PR #220:** função SQL de consulta com `SECURITY DEFINER` sob um owner `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`; cinco policies específicas `FOR SELECT TO owner`, sem acesso direto pela role de conexão. Teste do catálogo PostgreSQL, revogações e rollback de função, políticas e papéis.
- **F84 / PR #221:** revisão integrada dos componentes anteriores; **18/18 workflows passaram** na SHA `8faadaa81334e47cccf63a7a86a749bda4ee4e26`.
- **F85 / PR #222:** isolamento de imagem Docker: `app/Labs` é removida **antes do classmap Composer**; o CI prova que helpers sintéticos não estão no filesystem/autoload do runtime e que `/up` continua a única rota. Também não entram dados de teste, migrations ou segredos.
- **F86:** documento de consolidação e evidências da HEAD exata F86 a verificar.

O Portal tem ainda os contratos Sites e previews offline de serviços, Performance e SEO. Essas telas **não são publicadas pelo PHP-FPM**.

## Status correto

| Frente | Comprovado por testes | Bloqueio de utilização com cliente |
| --- | --- | --- |
| Laravel/infra | Build Docker em CI, serviço `/up`, gateway HTTP 404 no restante | VPS ainda não homologada; TLS e controles de rede/operadores pendentes |
| Identidade | Esquema PostgreSQL Portal-only, Argon2id, MFA, sessões/revisões e consentimento técnico do MFA sintéticos | Sem convite/recuperação, verificação de titularidade, KMS, sessão/cookie HTTP real ou RLS operacional revisado |
| Autorização | Role restrita EXECUTE-only e proprietário NOLOGIN não-superuser no laboratório | O predicado usa hash bearer-equivalente e não deve ser endpoint público; auditoria AppSec/DBA obrigatória |
| Segurança | SEC-R03 C04 em modo NO-GO, testes de revogação/RLS e CI múltiplo | Witness externo antirollback resistente a restore, LGPD e aprovação independente pendentes |
| Módulos | Contratos e demonstrações offline Sites/Performance/SEO | Sem operação de cliente, campanhas reais ou validação de propriedade Search Console |

## Ordem de execução necessária para liberar produto real

1. Ativar e homologar VPS Leaseweb autorizada, inventário de firewall/rede, TLS, contas administrativas e Docker rootless quando viável, observabilidade e backup/restore; separar espaços e credenciais dos vários projetos.
2. Substituir os fluxos SQL sintéticos por serviço de IAM Portal-only com papéis de aplicação não-superuser, teste concorrente real, convite, email verificado, MFA com chave protegida, cookies seguros, CSRF, recuperação, rate limiting de borda, revogação e auditoria.
3. Integrar witness SEC-R03 que sobreviva à restauração do PG, com testes hostis de rollback; revisão independente AppSec, DBA e LGPD.
4. Ativar jornadas comerciais com contratos/serviços elegíveis, aprovação e pagamento via PSP em homologação, idempotência, filas e autorização cruzada entre CRM (login independente), Sites, Performance, SEO e Universidade.
5. Executar E2E real na homologação, segurança/aprovação formal do rollout e deploy/rollback com evidências, **sem presumir autorização só por CI verde**.

## Política operacional

Este material é um PR **DRAFT / NO-GO**. A branch principal, a dev, produção, DNS e VPS não são modificados. Não houve contratação nem novas despesas. Nunca publicar helpers `App\\Labs` ou credenciais de laboratório; a imagem Docker continua private e sem área de cliente.
