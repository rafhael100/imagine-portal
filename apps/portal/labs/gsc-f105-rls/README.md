# Portal GSC-F105 — prévia F104 condicionada a um registro PostgreSQL RLS

## Resultado técnico pretendido

A F104 gera DTO diário de origem **não verificada**; a PR anterior de
Portal #771 provou a leitura em PHP. Esta fase conecta o consumidor ao
gateway **já existente** `GoogleSearchConsoleTenantGrantGateway`, para que a
prévia de laboratório exija simultaneamente:

- uma identidade interna de empresa/usuário com formato estrito;
- correspondência entre empresa e fingerprint de propriedade no DTO,
  contra a propriedade exata selecionada pelo backend;
- ambiente PHP CLI de testes, `APP_ENV=testing` e duas flags de laboratório;
- um registro de grant vigente, não revogado, escopo `webmasters.readonly`,
  encontrado via transação `SET LOCAL app.tenant_ref` com PostgreSQL RLS
  e papel não-privilegiado (sem `BYPASSRLS`).
- recusa de métricas/credenciais falsificadas pelo contrato F104.

O código **não** emite autorização. O registro no PostgreSQL
não é comprovação de sessão, MFA, consentimento, propriedade Google atual
ou permissão de exibição de dados. A saída tem sempre sinais negativos
`customerDisplayAuthorized=false`,
`googleOAuthVerifiedNow=false`,
`productionReadAuthorized=false`, `publicationAuthorized=false`.

## Prova em banco real descartável

Workflow `portal-gsc-f105-rls-review-ci.yml`: carrega o SEO Engine
exatamente na SHA `ec1739621b20860f729c59d9863ba1b341da43e8`,
usa o emissor Node da F104 e valida em PostgreSQL isolado (16-alpine)
três cenários sintéticos: completo, parcial e vazio. A suíte testa
o papel com RLS, contextos de empresas distintas, outro ator, propriedade
incorreta, expiração, revogação, adulteração de métricas, contexto com
campo extra, uso de conexão administrativa e ausência de escopo residual.

Migrações Laravel existentes são aplicadas **somente** no runner CI
e revertidas no final. Nenhuma tabela nova, rota, migração, consumidor
HTTP, token OAuth ou dado de clientes é introduzido por esta PR.

## Limite de segurança e próximo gate

O parâmetro chamado `trustedContext` **não** é autenticado por esta
classe: deve vir de um futuro middleware/service do Portal que obtenha
e verifique sessão, MFA, tenant membership, RBAC e consentimento.
Não passar contexto recebido pelo usuário ou pela web diretamente.

Mesmo com um registro sintético de grant fresco, o motor devolve
somente uma **prévia interna bloqueada**. A transformação em um serviço
operacional requer Google OAuth read-only real, prova vigente da
propriedade, cofre de segredos autenticado, revisão AppSec/DBA/LGPD,
homologação privada com backup/rollback e autorização separada de
release.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
