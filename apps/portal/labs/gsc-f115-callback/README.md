# F115 — estado OAuth vinculado à sessão MFA ORIGINAL do Portal

**Laboratório PostgreSQL 18. Não é callback de produção, token Google
ou autorização de exibição.**

A F114 emitia um estado PKCE cifrado somente depois de verificar sessão
business/MFA e `seo.gsc.connect`. Porém seu ledger continha apenas
`tenantRef` e `actorRef`. Outra sessão ativa do mesmo usuário/empresa
poderia parecer equivalente ao emissor original, e o estado continuava
persistido após o logout.

A F115 resolve essa lacuna no **contrato candidato**:

- `portal_gsc_f115_state_session_bindings` registra o SHA-256 da sessão
  exata que emitiu o estado, UUID da empresa e
  `membership_revision`. A inserção ocorre **na mesma transação**
  que a gravação do estado cifrado, sob `FOR SHARE` das linhas de
  sessão, identidade, empresa, associação e grant `connect`.
- A rotina de início `OfflinePortalGscOAuthStateStartReview` seleciona
  `portal_gsc_f115_store_bound_state` somente com
  `PORTAL_GSC_F115_CALLBACK_LAB=1`. Sem essa flag, o comportamento
  da F114 permanece exatamente igual.
- A função `portal_gsc_f115_consume_bound_state` confirma que a
  **mesma referência de sessão** continua ativa, com MFA, finalidade
  business, revisões atuais e permissão `seo.gsc.connect`.
  Compara o hash persistido, UUID de empresa e revision antes de
  atualizar `consumed_at` sob RLS e bloqueios de linha.
- O método `reviewBoundCallback` valida apenas o callback sintético,
  decripta o verificador **exclusivamente em memória do backend** e
  reutiliza o `GoogleSearchConsoleOAuthCallbackPolicy` original para
  classificar estado/código. O retorno expõe somente um resumo seguro:
  nunca código, bearer, verifier, UUID, refresh token ou autorização.
- Testes exigem que callback com bearer falso, sessão provisória,
  outra organização, código inválido, replay e callback após logout
  sejam negados; revogação de sessão ou remoção do grant concorrente
  devem sofrer SQLSTATE `55P03` enquanto a transação valida/consome.
  Uma negativa por logout não consome indevidamente um estado que
  já não possui sessão válida.
- Os três cenários F104 (completo/parcial/vazio) executam esse
  laboratório em PostgreSQL18 descartável, com scripts e migrations
  reais da identidade Portal e da cadeia GSC F108/F109 (SHAs fixadas),
  seguidos de limpeza/rollback.

## Bloqueios mantidos

Mesmo com o código recebido e o estado consumido, `tokenExchangeAuthorized`
permanece **false**. O resultado não é autenticação Google nem prova de
propriedade; ainda faltam OAuth Google Cloud próprio, redirect URI
registrada, cliente HTTPS seguro, refresh token/vault, verificação
de escopo e propriedade Google, gestão de revogação, consentimento,
retenção LGPD, auditoria e integração real com HTTP/cookies do Portal.

A implementação SQL é **fixture de laboratório**, não migração operacional;
requer revisão DBA/AppSec independente de SECURITY DEFINER, locks,
RLS, keys e grants de coluna antes de qualquer homologação na VPS.

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-CUSTOMER-DATA.**
