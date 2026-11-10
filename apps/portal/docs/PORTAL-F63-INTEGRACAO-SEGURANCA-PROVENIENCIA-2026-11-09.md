# PORTAL-F63 — integração de segurança e proveniência para revisão no desenvolvimento

Data-base: 09/11/2026, sem autorização de produção.

## Convergência verificada pelo código nesta linha

A base F59/PR #190 contém o Portal Laravel, contratos Sites, mock editorial, UI e exportação HTML. A linha posterior contém:

- **F60, PR #191:** SEC-R03 C04 importado para CI Node com 38 gates **bloqueados/não executados**. CI verde significa que o release **não está autorizado**.
- **F61, PR #192:** autoridade PostgreSQL sintética (hash do bearer, MFA/role/RLS e sessões), emissão de envelopes Ed25519 e testes F08/F09 isolados de migrações normais.
- **F62, PR #194:** verificador original Performance F1-29 e regressão F1-30 com leitura fresca do PostgreSQL do Portal. Regressão end-to-end: 60 + 45 + 50 verificações no runner PG16.
- **F63:** teste unitário de proveniência para **11 artefatos de contrato e segurança** copiados entre branches/repos (Sites v1, SEC-R03 C04, Portal AUTH-PERF F07-F09, Performance F1-29). Usa o hash de blob Git de cada fonte. Nos seis PHP com namespace adaptado, o teste reconstitui exclusivamente o namespace original antes de comparar os bytes. Se o import for alterado, a suíte deverá falhar até uma revisão de origem.

## Interpretação de segurança

A conferência de hashes Git garante **identidade de conteúdo do snapshot fixado**, não autoria criptograficamente autenticada, origem remota confiável, aprovação, assinaturas de release, anti-replay distribuído nem prevenção de drift futuro no upstream. Mudanças de contrato exigem PR coordenado com revisão de diff.

As classes de laboratório continuam sob `apps/portal/app/Labs` e `apps/portal/labs`. A migração de PG16 F61 está fora de `database/migrations` e só é executada com `php artisan migrate --path=labs/auth-perf-f61/migrations` dentro do CI descartável, nunca automaticamente em `main` ou VPS.

## Checkpoints para integração real (não cumpridos)

- Autenticação Portal-only de pessoas reais, MFA autenticado, recuperação segura, sessão persistida, vínculo de empresa/owner e tenant cross-service;
- Fonte de permissão e contrato válidos com revisão/epoch e barreira atômica contra revogação concorrente;
- Witness externo anti-rollback comprovadamente fora do domínio restaurável de PG/backups, controle SEC-R03/T3 e testes de restore;
- Segurança independente AppSec/DBA/LGPD, consentimento/aceite versionado e autorização comercial;
- Transporte autenticado Portal↔Sites/Performance, rotação/gestão de chaves, replay store distribuído, observabilidade e E2E em homologação;
- VPS Leaseweb pronta e homologada, backups/restore, custos/credenciais acordados, DNS e deploy separados.

## Proibido nesta etapa

Merge automático em `dev` ou `main`, deploy, chaves persistentes, pagamentos reais, anúncios Google/Meta, Cloudflare produtivo, sessões/contratos/clientes reais. **PR DE REVISÃO / NÃO RELEASE.**
