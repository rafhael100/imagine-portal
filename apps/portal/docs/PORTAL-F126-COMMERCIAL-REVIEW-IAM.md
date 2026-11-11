# Portal F126 — Permissão explícita para revisão e camada Laravel ainda fechada

A F125 expõe somente no PostgreSQL efêmero do CI uma fila de metadados que exigem análise. Antes de criar um painel administrativo, a F126 define um contrato de autorização **exclusivo** para revisar entregas comerciais: `commercial.delivery-review.view`.

## Contrato seguro

`CommercialDeliveryReviewPreflight` aceita somente referência opaca de sessão Portal, organização selecionada, UUID de submissão e instante. Sua dependência `PortalAuthorityBoundary` deve obter identidade, vínculo organizacional e propriedade da submissão por **provedores de confiança do servidor**. Valor recebido do cliente jamais é prova de posse.

- Ausência de grant explícito, outros grants (`services.view`, `services.manage` etc.), sessão CRM, organização errada, MFA ausente, sessão revogada/expirada ou UUID inválido não passam na pré-validação.
- Mesmo a fixture de teste com sessão/MFA/membership/grant sintéticos positivos devolve apenas `syntheticChecksPass=true`. `canReadReviewQueue`, `canReleaseRetry`, `canAcknowledgeVisitor` e `canAuthorizeHttp` permanecem **sempre falsos**.
- `AppServiceProvider` segue usando `DenyingPortalAuthorities`. Nenhum login, route, query de banco, worker, importação de credencial, SSO, OAuth ou painel foi habilitado.
- Os testes Feature do Laravel cobrem o fluxo negativo e o positivo sintético, além de exigir que a única rota seja `/up`.

## Limites

Esta é uma preparação de domínio, não o controle de acesso operacional: nenhuma autoridade real ainda valida sessão, MFA, concessão no PostgreSQL ou titularidade de submissão. A aplicação posterior exige papéis mínimos com RLS, revisão SEG/DBA independente, auditar leitura por operador, LGPD, testes E2E na VPS homologada e autorização específica de publicação. O backlog de revisão permanece somente no laboratório.

Sem alteração de `main`, deploy, custos, customer data ou integração CRM real. Revisão de produção: [issue #315](https://github.com/rafhael100/imagine-portal/issues/315).
