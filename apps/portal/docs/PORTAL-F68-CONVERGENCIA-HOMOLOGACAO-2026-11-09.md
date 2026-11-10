# PORTAL-F68 — convergência de homologação (revisão, não produção)

Estado em 09/11/2026 (ciclo UTC de 10/11). Proposta de revisão integrada com base em `dev/fast-track-portal-2026-11-05`.

## Avanços efetivos incorporados

**F1–F63 (PR #195)** — linha Laravel, contratos Sites, sete telas de prévia offline, suite PostgreSQL/revogação, laboratório Portal→Performance Ed25519, gate SEC-R03 C04 com release BLOCKED e 11 snapshots de proveniência.

**F64 (PR #198)** — execução em Docker Compose de PHP8.4/nginx realmente exercitada pelo GitHub Actions, com porta localhost 127.0.0.1:18080, gateway exclusivo /up, rootfs read-only, rede privada backend e correção de volumes Laravel. Nenhuma migração/cliente ou serviço real. Execução de referência: [smoke Docker final SUCCESS](https://github.com/rafhael100/imagine-portal/actions/runs/42747811488).

**F65 (PR #199)** — consumidor offline do DTO Performance F84 e tela estática de campanhas Google/Meta, integrados byte a byte a partir dos PRs paralelos #193/#196. Matriz PHP8.3/8.4 Node22/24 e artefato HTML temporário validados. Protocolo continua sintético; nenhuma campanha publicável.

**F66 (PR #200)** — primeira migração PostgreSQL de identidade própria do Portal: cinco tabelas (identidades, organizações, memberships, permissões explícitas e sessões com bearer em SHA256). Chave FK composta sessão→membership limita vinculação cross-tenant. Todos os RLS são FORCE e **sem policy de acesso**. CI PG18 PHP8.3/8.4 com constraints, isolamento e rollback SUCCESS. Não há login real.

**F67 (PR #201)** — cinco modelos Eloquent para as tabelas F66, guarda de mass assignment, segredos ocultos no JSON, testes de relacionamentos e prova de não abertura de rotas.

**F68 (esta branch)** — inclusão da F65 sobre a linha F67/F64 atualizada e teste de proveniência estendido para 16 artefatos. PR integrado a revisar; verificar **CI da SHA F68**, não inferir sucesso exclusivamente dos PRs pais.

## Situação técnica não mascarada

Há uma aplicação PHP/Laravel contêinerizável e testada. Porém a rota web operacional permanece **exclusivamente /up**. As telas HTML são prévias locais e o PostgreSQL F66 é uma base de identidade **proibitiva por padrão**, sem permissões para ler/alterar linhas e sem login/MFA funcional. O repositório ainda não é um produto comercial pronto, apesar do grande volume de commits e testes.

## Critérios bloqueantes para abrir login e atender clientes

- Fonte de identidade Portal-only, emissão segura de sessão, MFA de verdade, verificação de email, recuperação de conta, CSRF, anti-brute-force, cookies seguros e política de revogação atômica.
- Policy RLS PostgreSQL de privilégio mínimo com ownership real, app role sem superuser e revisão DBA; `current_setting`/GUC isolada não é prova de autorização.
- Integrar contratos assinados e consentimentos exatos do cliente, pagamentos e serviços com idempotência e auditoria.
- Resolver SEC-R03: witness anti-rollback realmente independente da restauração, revalidação após recovery e testes adversariais AppSec/DBA.
- Homologação VPS Leaseweb acessível, TLS/segredos/DNS, backups/restore, monitoramento, rate-limits, patching e rollback.
- Integração de negócio Sites/Performance/CRM/Universidade elegível com versões/autoridade de serviço e testes E2E.
- Revisão de licenças/segredos/dependências, acessibilidade, LGPD e aprovação distinta de rollout pela direção.

## Segurança operacional

Nenhum login/checkout/API/Ads/Cloudflare ou publicação foi conectado nesta branch; main/dev da aplicação e VPS permanecem inalteradas. **PR DRAFT / não mesclar/deploy sem gates independentes.** Uma suite verde não é certificado de segurança e não indica percentual de conclusão do produto.
