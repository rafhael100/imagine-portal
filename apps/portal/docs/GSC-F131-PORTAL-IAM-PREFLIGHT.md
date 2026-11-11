# GSC-F131 — Pré-validação da conexão Google ligada à camada de autorização do Portal

## Integração implementada

Antes desta etapa, o Imagine SEO tinha a permissão tipada `seo.gsc.connect`, o validador de propriedade `siteUrl` F120 e a fundação de autorização do Portal, mas essas partes ainda não eram compostas no domínio Laravel.

A classe `GoogleSearchConsoleConnectionPreflight` integra as três peças. Recebe uma referência de sessão **do Portal**, a empresa selecionada e o `siteUrl` solicitado; calcula o SHA-256 do identificador exato e encaminha ao `PortalAuthorityBoundary` a permissão **fixa** `seo.gsc.connect` e a referência de recurso `gsc-property-sha256:<sha256>`.

O `PortalAuthorityBoundary` consulta abstratamente sessão, membership/grant e propriedade. Em produção, o `AppServiceProvider` **continua vinculando todas essas autoridades a `DenyingPortalAuthorities`**, que não reconhece nenhuma sessão, membro ou propriedade. Portanto a pré-validação real retorna negação. As fontes sintéticas dos testes podem simular uma correspondência para verificar isolamento de ator, sessão, MFA, revisão, empresa, grant e `siteUrl` exato, mas não concedem direitos reais.

A saída `GoogleSearchConsoleConnectionReview` oferece `syntheticEligibilityPass` apenas para diagnóstico offline; `canStartOAuth()`, `canReadGoogle()` e `canStoreTokens()` retornam **sempre false**, inclusive num caso sintético positivo. Nenhuma classe tem privilégio de iniciar HTTP, consultar o Google ou criar token.

## Testes

Os testes Unit abordam permissão empresarial ausente, role owner sem concessão explícita, diferenças entre propriedade `sc-domain` e URL-prefixo, outra empresa, outra pessoa, membership pendente/suspenso/revogado, sessão expirada, MFA ausente, revisão desatualizada e cookie do CRM não reconhecido.

O teste Feature instancia a aplicação Laravel com os bindings **efetivos** e confirma que as autoridades default-deny permanecem vigentes, que a pré-validação não autoriza nenhuma conexão e que todas as rotas públicas do Google OAuth continuam 404.

## Bloqueios obrigatórios

**Isto não é uma autenticação real.** A `PortalSessionAuthority`, `PortalMembershipAuthority` e `PortalResourceAuthority` seguem interfaces sem adaptadores confiáveis. Um parecer AppSec/DBA independente deve revisar e implementar a sessão first-party segura, revisão transacional de MFA/grants/propriedade, revogação, comparação contra a propriedade realmente verificada no Google, credenciais próprias, cofre de tokens e HTTPS backend antes da liberação.

O commit não aplica migrations, não abre policies RLS, não liga flags de OAuth, não introduz rotas, não contata Google, não gera custos e não altera `main` ou produção. Está exclusivamente em PR draft.
