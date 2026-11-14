# F1-396 — resolvedor privado de aceite explícito do cliente (Portal ↔ Sites)

**Objetivo:** preparar o lado Portal do contrato POST `/internal/sites/v1/approvals/resolve` usado pelo cliente mTLS do [Imagine Sites F1-395, PR #474](https://github.com/rafhael100/imagine-sites/pull/474).

## Contrato restrito

O método `PortalSitesClientAcceptanceResolver::resolve()` exige identidade previamente autenticada de serviço Sites através de `PortalVerifiedSitesServiceIdentity` (default `DenyingPortalVerifiedSitesService`). A entrada canônica `sites.portal-client-approval-fetch.v1` contém EXATAMENTE, nesta ordem: `schemaVersion`, `tenantRef`, `siteRef`, `releaseRef`, `artifactDigest` SHA-256 e `editorialRevision`.

Todos os identificadores são opacos e limitados. Não há parâmetro `paid=true`, consentimento, MFA, emissor, URL, token, usuário, chave ou domínio vindo do navegador. O parser limita a entrada JSON a 2 KiB e profundidade 12. Uma nova identidade de serviço ou qualquer alteração de esquema exigirá revisão e versionamento.

O resolvedor invoca o [emissor Portal F1-394, PR #749](https://github.com/rafhael100/imagine-portal/pull/749) com uma fonte de aceite confiável que, por padrão, sempre nega. Mesmo depois de assinar, compara empresa/site/release/revisão/digest do comprovante com os valores solicitados. Qualquer erro, falta de MFA/ato explícito, aceite revogado, fonte indisponível, dados incorretos ou identidade S2S não verificada devolve erro genérico (sem dados pessoais).

**Assinatura não é autorização de publicação:** o Sites F1-391 ainda exige verificação Ed25519 própria e consulta a testemunha independente de consentimento/revogação, além de todos os gates comercial, domínio e AppSec/LGPD.

## CI e bloqueios

A matriz PHP 8.3/8.4 valida pedidos canônicos e adulterados, alterações de empresa/site/release/hash/revisão, campo extra, esquema incorreto, serviço não autenticado, falha IAM, ausência de MFA, consentimento não expresso, revogação, fonte offline e chave ausente. Todos os atores e dados são fictícios; o emissor assina com chave efêmera em memória.

**Não existe rota Laravel, middleware de certificado operacional, binding de provider, armazenamento de aceites reais, vault, homologação Leaseweb ou gateway Asaas real.** Nenhum cliente foi autorizado nem site publicado. A integração deve ser revisada e homologada antes de configurar credenciais; não utilizar headers `X-Forwarded-Client-Cert` de origem arbitrária como prova IAM.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CHARGE.**
