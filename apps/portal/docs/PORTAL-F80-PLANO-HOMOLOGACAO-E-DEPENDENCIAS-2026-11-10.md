# PORTAL-F80 — revisão integrada de identidade, infraestrutura e módulos comerciais

**Corte: 10/11/2026.** Este é um documento de revisão do código em `review/portal-f80-cross-product-readiness-2026-11-10`, comparado com `dev/fast-track-portal-2026-11-05`. Não é um release e não autoriza merges ou deploy.

## Evidência consolidada

O GitHub compara a base `dev` com esta branch e informa, antes da inclusão deste documento, **373 commits à frente, 0 atrás, 285 arquivos alterados**. O número não mede produto finalizado: boa parte ainda é contrato, laboratório, testes ou HTML estático.

### Funcionalidades e contratos existentes

| Área | Entregas reunidas | Estado para cliente |
| --- | --- | --- |
| Portal Laravel | Fundação PHP8.3+, contratos de serviço e sete telas demonstrativas; imagens Docker PHP8.4/nginx com porta loopback e rota `/up` | Nenhuma rota de login/área do cliente habilitada |
| Identidade e MFA Portal-only | Cinco tabelas PostgreSQL FORCE RLS; modelos Laravel, Argon2id, TOTP, segredo cifrado, CAS atômico e transação de sessão de laboratório | Não há autoridade de identidade real, KMS, recuperação, app policy nem cookie seguro |
| Segurança de sessão | F75 obtém segredo TOTP matriculado sob lock; F76 exige grant/tenant/revisões por role EXECUTE-only; F77 controla 5 tentativas/15m no PG18 | Somente prova no CI, roles SECDEF e hash bearer-equivalente não podem ser expostos |
| Imagine Sites | CreateProject v1, snapshot pinado, drift CLI e validação de payload offline | Sem API operacional ou publicação |
| Imagine Performance AI | Contrato de preview e verificação Ed25519 sob PostgreSQL descartável, HTML de campanhas Google/Meta | Sem Ads reais nem campanha autorizada |
| Imagine SEO Engine | Leitor do handoff v1 do [PR #210](https://github.com/rafhael100/imagine-portal/pull/210) incorporado na F79, HTML fictício com dados técnicos, orgânicos e sugestões | Sem Google Search Console ou propriedade/consentimento verificado |
| SEC-R03 | Gate C04 no CI, 38 requisitos marcados BLOCKED/NOT_RUN | Witness anti-rollback real, restauração e revisão externa ainda pendentes |

## Verificações já executadas

- F74/PR #208: **11/11** workflows passando na SHA `176126cc7f00daaaf996d50f354f0e0099dcb508`.
- F75/PR #209: PG18 PHP8.3/8.4, verificação contra segredo realmente cifrado na identidade: **PASS**.
- F76/PR #211: PG18 PHP8.3/8.4, role least-privilege/SECDEF e revisão de organização: **PASS**.
- F77/PR #212: PG18 PHP8.3/8.4, contador transacional/limites de tentativa/RLS: **PASS**.
- F78/PR #213: **14/14** workflows passando na SHA `8c4a1f3c86fd5d6e0ec1f9d71f1dc8cd82c510c0`.
- F79/PR #214: matriz PHP8.3/8.4 + Node22/24: **PASS**; gerador HTML SEO com CSP e negação de identidade, dados e permissões falsas.
- F80: precisa passar novamente **todos** os workflows da HEAD exata após a inclusão deste documento.

## Prioridade para tornar o Portal operacional (ordem de dependências)

1. **Infra da VPS e segredos:** acesso à VPS Leaseweb já autorizada, hardening Ubuntu, Docker sob mínimo privilégio, acesso administrativo protegido, KMS ou estratégia equivalente de custódia/rotação, Postgres independente, backup/restore com witness externo. Os checkouts de GitHub Actions não são homologação de rede/servidor real.
2. **Identidade de primeiro uso:** fluxo de convite/registro com email verificado, senha segura, MFA efetivo, armazenamento de segredo, recuperação segura, rate limit multi-dimensão, HTTP cookies `Secure`/`HttpOnly`/`SameSite`, sessão e CSRF. Substituir funções/roles laboratoriais por políticas auditadas.
3. **Permissão multiempresa:** owner/membership/grants por organização com revisão revogável, RLS e conexão de aplicação com role sem superuser, prova de ausência de fuga cross-tenant em E2E. Não confiar em `current_setting`/GUC ou em hash de bearer como autoridade pública.
4. **Proteção SEC-R03/T3:** witness de revogação e antirollback fora de backups, testes adversariais de restauração e validação de sessão/autoridade após incidentes.
5. **Jornadas comerciais:** serviços elegíveis, aceite versionado, pedidos/contratos, faturamento Pix/PSP, CRM/Sites/Performance/SEO e Universidade, auditoria LGPD, observabilidade e integração transacional sem operação de mock.
6. **Homologação final:** testes E2E de cliente/empresa, segurança AppSec e DBA independente, acessibilidade e LGPD, resposta a incidentes, rollback e autorização explícita do release.

## Regras de não liberação

O produto permanece **NO-GO** até os gates serem cumpridos. As telas HTML DEMO são offline; não mostram dados de clientes e não devem ser hospedadas publicamente. A única rota permitida pela stack F64 é `/up`. Nenhum contrato, aprovação, campanha, SEO ou usuário operacional foi ativado nesta etapa.

Merge em `dev`, merge em `main`, publicação, DNS, PSP, automação de pagamentos ou migração em VPS **não estão incluídos nem autorizados por este PR DRAFT**. Também não há novas despesas contratadas.

A revisão deste PR é necessária, mas não suficiente, porque a origem de autoridade, o gerenciamento de chaves, a segurança de acesso real e a infraestrutura definitiva ainda não foram verificados.
