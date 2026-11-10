# PORTAL-F90 — revisão integrada do Portal (10/11/2026)

**Base:** `dev/fast-track-portal-2026-11-05`. **HEAD:** branch `review/portal-f90-security-performance-audit-2026-11-10`. **Status:** DRAFT / NO-GO operacional, sem merge ou deploy.

## Linha consolidada

A F90 incorpora todo o histórico revisável F1–F86 e mais três incrementos concretos:

1. **F87 / PR #224:** contrato tipado Performance F100 e HTML offline de contas candidatas, campanhas e revisões, com índices e flags expressamente sem permissões. Regressões de coerência de referência/contagem, script injection, exposição de token e adulteração de flags. A matriz PHP8.3/8.4 e Node22/24 roda inclusive em repositório GitHub **privado**, sem condição que silenciosamente pula os checks.
2. **F88 / PR #225:** Docker de homologação remove `app/Labs` e agora também consumidores estritamente offline de SEO/Performance antes do classmap Composer. Smoke prova que os arquivos e as classes não existem no runtime, que o banco e as fixtures não são copiados e que só `/up` é servido.
3. **F89 / PR #226:** esquema PostgreSQL18 de `portal_security_events` com sequência, UUID único, tenant e membership com FK composta, evento de catálogo restrito e hash de evidência sem conteúdo sensível. Triggers rejeitam UPDATE, DELETE e TRUNCATE e RLS mantém acesso negado por padrão. CI PHP8.3/8.4 comprova limites de escrita/leitura, cross-tenant e rollback no banco descartável.
4. **F90:** os dois workflows de contrato Performance passam a incluir mudanças nos enums de canais/estado em `pull_request.paths`. Qualquer alteração nesses vocabulários deverá executar as regressões do consumidor/renderer.

## Evidências anteriores verificadas no GitHub

- **F86 / PR #223:** 18/18 workflows SUCCESS na SHA `c4d43db675fbb8b6426728764c4558fad0af15f7`.
- **F87 / PR #224:** 6/6 workflows SUCCESS na SHA `df31e3553dfe436380a025c6a0fc18c8571e990b`; incluiu correção das classes enum esquecidas na primeira execução de CI.
- **F88 / PR #225:** 2/2 workflows SUCCESS na SHA `db0a9faca2d90a3517ce6e7b6c9c8a64d70ed1d5`, com Docker iniciando e 404 nas rotas comerciais.
- **F89 / PR #226:** suíte audit PG18 PHP8.3/8.4 SUCCESS na SHA `2401ee30e48acc0816f0f7ab2aa13013687469be`. A prova depende só de banco efêmero, não de VPS.
- **F90:** exigir CI da HEAD exata antes de afirmar que todas as linhas foram integradas com sucesso.

## O que NÃO existe ainda em operação

| Área | Limitação |
| --- | --- |
| Acesso aos usuários | O contêiner Laravel ainda só serve `GET /up` (com HEAD); não há página de login, OAuth, MFA real, fluxo de convite ou dashboard com dados |
| Identidade/tenant | F66–F83 são esquemas/fluxos e verificações de laboratório; permissões de negócio não foram liberadas em produção |
| Serviços e faturamento | Contratos Sites, Performance, SEO, Universidade, CRM e pagamentos permanecem não integrados ao runtime, sem prova de elegibilidade ou consentimento |
| Auditoria e LGPD | O log F89 é imutável **enquanto SQL/triggers existem**; administradores e restores podem reverter/apagar o histórico. Não há writer confiável, retenção formal, KMS nem witness externo |
| Infraestrutura | A imagem Docker funciona apenas em runner privado de CI; não há homologação da VPS Leaseweb, política de segredo/rotação, TLS, monitoramento, recuperação ou rollback validado |
| Segurança final | SEG/DBA/AppSec independentes, SEC-R03 antirollback externo, permissões de escrita não-superuser, E2E de clientes e aprovação formal continuam pendentes |

## Critérios de habilitação responsável

- Ativar infraestrutura de homologação com segredos e papéis independentes, sem publicar endpoints de cliente.
- Implementar IAM Portal-only com identidade verificada, convite/recuperação, MFA, cookies HTTPS, CSRF, rate limiting de ponta a ponta e autorização real por organização.
- Implementar writer transacional para eventos F89 com fonte verificável, sem superuser, testemunha externa fora dos backups e prova de restauração hostil.
- Integrar serviços e contratos reais por versão/tenant com consentimento, cobrança e idempotência, mantendo o CRM com login independente.
- Executar AppSec/DBA/LGPD e homologação funcional/end-to-end antes de qualquer mudança de status para GO.

**PR DRAFT; não autoriza merge, VPS, produção, mudança de DNS, campanhas, cobranças nem novos gastos. CI verde não é homologação.**
