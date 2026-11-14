# F113 — Senha Argon2id e MFA do Portal até o grant GSC sob RLS

## Avanço

A F112 juntou o esquema real de `portal_membership_grants` e o
módulo GSC em PostgreSQL 18, mas inseria a sessão de negócio diretamente
via administrador de testes. A F113 retira esse atalho:

1. Executa o SQL da infraestrutura original de autenticação do Portal
   (`portal_browser_auth.sql`) com o papel `portal_browser_runtime`
   restrito e uma chave HMAC **aleatória apenas para a fixture**.
2. Cria identidade, empresa e vínculo fictícios, com senha Argon2id,
   segredo TOTP cifrado e grants separados de `seo.gsc.connect` e
   `seo.gsc.view`. As triggers reais de membership fazem a revisão
   subir de 1 para 3 antes de qualquer sessão ser emitida.
3. `PostgresPortalBrowserAuthentication` emite uma sessão provisória
   após senha e rotaciona para uma **nova sessão business** após TOTP.
   Não usa INSERT manual de sessão.
4. `PostgresPortalPermissionPreflight` (F111) verifica no mesmo
   PostgreSQL que a sessão autenticada tem o grant `seo.gsc.view`.
5. O leitor GSC F109 com o SQL integrado F112 aceita essa referência
   de sessão somente na transação com `FOR SHARE` de identidade,
   empresa, membership, grant SEO, vínculo GSC e property grant.
6. Conexão de revogação concorrente não consegue invalidar sessão
   nem excluir o grant até COMMIT. Um logout real feito por
   `PostgresPortalBrowserAuthentication::revoke` passa a bloquear
   **ambos os leitores** na próxima observação.

O workflow usa **PostgreSQL 18 descartável**, os arquivos do Portal e
GSC fixados em SHAs exatas e o emissor SEO F104 original para três
cenários fictícios (completo, parcial e vazio). Aplica migrations
pertinentes do Portal e GSC apenas no runner e verifica rollback.

## Gate de produção ainda fechado

O sucesso comprova interoperabilidade entre o emissor de sessão e
as verificações de grants em laboratório; não ativa o serviço do
cliente ou uma API. As chaves, usuários, sessão, domínio e propriedade
Google são fictícios. Ainda faltam:

- Review e integração dos branches de autenticação e GSC em um backend
  operacional com políticas de rede, cookies e roteamento seguros;
- OAuth GSC `webmasters.readonly` próprio do Portal, refresh-token
  vault, consentimento e verificação atual da propriedade via Google;
- política de direitos de exibição, rastreamento e retenção LGPD;
- revisão AppSec/DBA/LGPD independente das funções `SECURITY DEFINER`,
  grants de coluna, row locks e custo de contenção;
- execução em staging privado com backups/restores/rollback, gates de
  releases e autorização explícita.

Todas as flags `customerDisplayAuthorized`,
`productionReadAuthorized`, `publicationAuthorized`,
`googleOAuthVerifiedNow` permanecem negativas.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.**
