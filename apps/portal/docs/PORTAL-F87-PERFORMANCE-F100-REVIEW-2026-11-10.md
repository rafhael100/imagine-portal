# PORTAL-F87 — integrar contrato Performance F100 em revisão offline

Data: 10/11/2026. Base: `review/portal-f86-secure-runtime-auth-convergence-2026-11-10`.

## Código integrado

Esta etapa incorpora os arquivos dos PRs [#217](https://github.com/rafhael100/imagine-portal/pull/217) e [#218](https://github.com/rafhael100/imagine-portal/pull/218) à mesma linha do Portal, preservando o código dos contratos e dos testes de origem. O DTO `perf.portal.credential-bound-workbench.synthetic.v1` descreve contas candidatas, campanhas e revisões fictícias cuja autoridade está **explicitamente negada**. `credential_binding_confirmed=true` significa exclusivamente uma prova de credencial SQL em laboratório Performance: não prova login do cliente ou autorização Portal.

- Consumidor PHP tipado `CredentialBoundPerformanceWorkbenchPreview`, com validação de chaves exatas, enum de canais e estados, contagem/coerência, referências de contas/campanhas, limite de itens e negação de flags de autorização/publicação/cobrança.
- Renderer PHP CLI `f63-render-credential-workbench.php` com tela estática de contas, campanhas e revisões (HTML responsivo, CSP sem rede/script, sem botões/formulários).
- Regressão Node/PHP com payloads ofensivos, token indevido, inconsistência de contagem, referências cruzadas e tentativa de conversão de demonstração em ação.
- Fixture F100 sintética reproduzível (não é material obtido de PostgreSQL real ou de uma conta de anúncios).
- Workflows PHP8.3/8.4 e Node22/24 **sem a condição `repository.private == false`**, para que as verificações não sejam ignoradas quando o repositório voltar a ser privado. Exportam artefato HTML interno por 2 dias.

## Limites que permanecem fechados

- A prévia roda apenas em CLI `APP_ENV=testing` com flags explícitas; não é endpoint nem painel conectado ao banco.
- O HTML contém valores sintéticos: nenhum operador pode alterar/publicar campanhas, aprovar anúncios, cobrar clientes ou acessar tokens.
- Em produção, a origem de identidade Portal-only, a autoridade dos serviços, consentimento por conta, as credenciais Google/Meta, revogação, auditoria e o controle de acesso transacional ainda exigem implementação e validação independentes.
- O backend web mantém somente `/up` e o gate SEC-R03 permanece NO-GO.

**PR DRAFT / sem merge, deploy, dados de clientes, OAuth ou custo adicional.**
