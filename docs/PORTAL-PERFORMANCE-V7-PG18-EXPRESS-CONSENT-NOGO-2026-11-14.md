# Portal Performance V7 F830 — aceite expresso versionado sob identidade SQL

**14/11/2026 — CI PostgreSQL18 descartável. DRAFT / NO-GO.**

## Avanço em relação à PR #829

A PR #829 já consulta sessões e permissões do esquema PostgreSQL18 F66/F76/F113 do Portal e compara empresa, contrato, competência e fingerprint estável de plano/termos. Porém o estado de aceite era criado por administrador CI. Esta entrega introduz um **evento de aceitação/revogação sintético, append-only, disparado por um sinal booleano explícito e ligado ao ator obtido da própria sessão SQL**.

- `portal_v7_express_consent_events.sql` cria `portal_v7_consent_events_lab`, com `FORCE RLS`, registros imutáveis de revisão, `actor_id` (identidade retirada de `portal_auth_sessions`), organização do acordo, fingerprint de plano e termos, e data do banco. O login de aplicação de laboratório não recebe SELECT/INSERT/UPDATE/DELETE na tabela.
- `portal_v7_consent_record_lab`: só cria um evento se `session_user` for o login restrito de laboratório, o parâmetro `p_express` for explicitamente verdadeiro, o gate SQL F829 aprovar sessão empresarial/MFA/permissão e a revisão anterior corresponder ao estado atual. Usa `pg_advisory_xact_lock` por empresa/acordo/competência para serializar double-click e corrida entre aceite/revogação. Não recebe `actor_id`, nem permite duplicar aceite ou revogar contrato inexistente.
- `portal_v7_consent_current_lab`: reconsulta a revisão mais recente, exige `synthetic_accepted`, mesmo plano e termos e repete o predicado de sessão/MFA/organização. Após revogação, um consentimento anterior não volta a valer só porque o nonce/HMAC comercial continua válido.
- `PortalV7SqlExpressConsentLab`: adaptador CLI com `APP_ENV=testing`, PostgreSQL local, banco/role previstos e dados sintéticos, retornando apenas revisão ou booleano, sem dados pessoais ou credenciais. Todas as flags operacionais seguem falsas.
- Teste dentro da fixture SQL F829: sinal falso, sessão forjada, empresa/planos/termos divergentes, aceite válido, dupla submissão, revogação, reaceite com revisão nova, aba com revisão obsoleta, membro suspenso, logout, privilégio SQL limitado e bloqueio ao desligar a feature flag.

## Por que ainda não libera produção

Mesmo com o ator derivado da tabela de sessão, o teste injeta uma **identidade fictícia e um sinal booleano fornecido pelo próprio script de CI**; isso não comprova clique de cliente em interface autenticada, termos efetivamente exibidos, IP confiável, forma de aceite ou base legal. O predicado recebe o SHA256 do bearer, que é equivalente a credencial: **não expor SQL, token ou RPC publicamente**.

**Próximos gates obrigatórios:** fluxo browser com autenticação/MFA realmente operacionais e CSRF, recibo de termos/versão e prova de apresentação para cliente, integração S2S autenticada, trilha de auditoria minimizada, teste de concorrência e cancelamento durante execução, LGPD/SEG/DBA/Financeiro, PSP sandbox, extratos Ads oficiais, NFS-e e GO homologação VPS. A consulta atual não elimina revogação após o SELECT.

**Sem merge, deploy, cobrança, CRM write ou anúncios reais.**
