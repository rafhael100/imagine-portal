# PORTAL-F92 — revisão integrada Imagine Portal (10/11/2026)

## Corte técnico
Base `dev/fast-track-portal-2026-11-05`, branch `review/portal-f92-budget-audit-security-convergence-2026-11-10`. Integra todas as alterações revisáveis F1–F90 e o incremento F91. **Não equivale a homologação/lançamento.**

## Entregas nesta rodada

- **F87 / PR #224:** integração do contrato tipado Performance F100 de conta/campanha/revisão em PHP, HTML offline com CSP sem rede/script, testes de dados ofensivos e contagens/referências; workflows PHP8.3/8.4 Node22/24 rodando inclusive em repositório GitHub privado. A primeira execução detectou enums de estado ausentes, corrigidos antes do CI final. **6/6 workflows aprovados** na HEAD F87.
- **F88 / PR #225:** a imagem Docker removia `app/Labs` desde F85; agora remove também três leitores de demonstração SEO/Performance antes do autoload Composer. Teste em runtime verifica filesystem, classmap e apenas `/up` HTTP. **2/2 workflows aprovados** na HEAD F88.
- **F89 / PR #226:** migração de eventos de segurança `portal_security_events` com FK de membership+organização, hash de evidência, sequence SQL, FORCE RLS sem policies e triggers bloqueando UPDATE/DELETE/TRUNCATE. PHPUnit/PDO no PG18 descartável, PHP8.3/8.4, testa adulteração, tenant incorreto, code inválido, rollback. Não fornece writer com identidade real nem antirollback fora do backup.
- **F90 / PR #228:** consolidação anterior com **21/21 workflows aprovados** na HEAD `e4d5d1d183eb847466435c83a686ab0bebd5d973`, reunindo segurança, performance, SEO, Docker e Laravel.
- **F91 / PR #229:** contrato tipado para orçamentos Performance F107 em BRL exato, somente centavos inteiros, divisão Google/Meta 10.000 basis points, reconciliação a cada dia UTC e ano bissexto, sem cobranças/execução. CI PHP8.3/8.4 × Node22/24 funciona em repositório privado. A classe de preview de orçamento também é removida da imagem Docker antes do classmap.
- **F92:** revisão única de todas as etapas com CI completo da SHA exata, e centralização dos critérios de habilitação.

## Porta de produção — permanece NO-GO

Não existe login, MFA ou recuperação de clientes no servidor. As rotas de negócios permanecem fechadas no PHP/Laravel; somente `/up` responde. Contratos e HTML são demos. O PostgreSQL das provas é descartável, não o banco da VPS. Toda verificação de `credential_binding_confirmed` diz respeito ao PostgreSQL de laboratório e **não autentica usuário do Portal**. Nenhum cenário F91 é orçamento financeiro autorizado.

Bloqueios principais:

1. Homologação da VPS Leaseweb, redes/segredos/TLS, PostgreSQL em papel de mínimo privilégio e backups/restore.
2. IAM Portal-only real, convite e validação de identidade, senha/MFA custodiados, sessão HTTPS, proteção CSRF, rate limits de borda e recuperação.
3. Autorizações por organização/serviço assinadas e revogação persistente; as funções `SECURITY DEFINER` de laboratório devem passar por revisão AppSec/DBA.
4. Witness de revogação/antirollback **externo ao domínio do backup** SEC-R03; F89 somente bloqueia operações SQL quando os objetos estão presentes, não resiste a restore/DBA.
5. Auditoria/retencão LGPD, contratos e aceites reais, PSP/cobrança idempotente, integração Sites/Performance/SEO/CRM/Universidade com autorizações e testes E2E.
6. Revisão humana de segurança e aprovação distinta do lançamento, seguida de deploy/rollback supervisionados.

**PR DRAFT, sem merge, deploy, gastos, anúncios, pagamentos ou dados de clientes.** Código e CI não substituem a aprovação de segurança/operacional.
