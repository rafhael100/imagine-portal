# Imagine Portal F17 — prévia HTML de homologação gerada do PostgreSQL 18 na CI

## Como funciona

O comando `php bin/export-pg18-integration-showcase.php`, executado **somente após a suíte de PostgreSQL da CI**, constrói um cenário 100% fictício: uma organização, três projetos distintos (Onepage, Landing Page e Performance AI), os respectivos checklists e três solicitações com revisões auditadas. Os dados são inseridos exclusivamente no banco **`portal_browser_lab`** de um contêiner `postgres:18-alpine`, apagado pelo GitHub Actions ao concluir a execução.

O comando consulta os registros novamente através dos leitores test-only F14–F16, **não** via HTML fictício fixo. Só prossegue se obtiver exatamente três projetos, três solicitações, escopos corretos e relatórios sem permissão de cliente. Renderiza o Blade `portal.demo.integration-rehearsal` com duas tabelas: preparação por serviço e histórico de solicitações, mantendo o layout Imagine aprovado.

A logomarca oficial, cujo tamanho e digest Git foram fixados na auditoria F94, é **incorporada como PNG base64**, para que o arquivo abra localmente sem acessar a internet. O documento exige CSP restritiva, `noindex,nofollow`, ausência de scripts, formulários e URLs externas, duas regiões de tabela roláveis por teclado e nomes claramente fictícios.

A CI cria `storage/app/portal-pg18-integrated-showcase/index.html` e um `manifest.json` com `html_sha256`, `production_approved=false`, `customer_access_approved=false` e `synthetic_only=true`. Verifica os hashes e publica um **artefato GitHub Actions com retenção curta (7 dias)**, nomeado `imagine-portal-pg18-html-php8.3` ou `...php8.4`. Isso permite inspeção visual manual sem VPS, serviço de hospedagem ou cliente real.

## Bloqueios técnicos

Este HTML não é uma aplicação nem autentica pessoas. Não há CSS/JS externo, rotas Laravel ou endpoints CRUD, contratos ou pagamentos. A reprodução depende de GitHub Actions, `GITHUB_ACTIONS=true`, `APP_ENV=testing`, `PORTAL_BROWSER_PG_LAB=DISPOSABLE_PG18`, PostgreSQL 18 no loopback e credenciais inteiramente sintéticas da job. **Nunca executar o comando contra banco da VPS.**

O preview prova integração visual e compatibilidade do banco em condições sintéticas, mas **não homologa operação real**. Faltam identidade Portal/MFA operacional, isolamento de leitura com RLS e grants independentes, comprovação contratual, witness externo de revogação (#64), revisão AppSec/DBA e aprovação de integração (#341). Todos os PRs continuam DRAFT e sem deploy em produção.
