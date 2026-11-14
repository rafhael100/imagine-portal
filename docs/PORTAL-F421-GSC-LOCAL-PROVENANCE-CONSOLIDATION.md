# F1-421 — consolidação de GSC com a cadeia financeira do Portal

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY / NO-GOOGLE-HTTP / NO-CHARGE.**

## Objetivo

A linha integrada [F420](https://github.com/rafhael100/imagine-portal/pull/820) já inclui
Portal Laravel, identidade Argon2id/TOTP, sessão MFA, vínculo explícito
`seo.gsc.connect` e `seo.gsc.view`, HMAC/RLS financeiro, Performance
R$490 por canal + mínimo R$170/mês/canal em mês completo e preparo de
credenciais C6 sandbox (não instaladas).

A F421 consolida os próximos blocos **já desenvolvidos e aprovados
separadamente**, para testar compatibilidade da mesma HEAD:

- [F116 #812](https://github.com/rafhael100/imagine-portal/pull/812): transporte HTTP local
  de token OAuth fictício em **127.0.0.1:18765** com parser estrito,
  resposta limitada, recusa de redirects, proxy e reutilização.
- [F117 #816](https://github.com/rafhael100/imagine-portal/pull/816): validação de
  `sites.list` sintético, sem aceitar URL alternativa, propriedade
  errada, permissionLevel insuficiente ou estrutura ambígua.
- [F118 #818](https://github.com/rafhael100/imagine-portal/pull/818): conexão de uma
  callback emitida sob sessão MFA original e consumo único do state
  PKCE com um único POST do token sintético ao receptor local.

## Prova de integração

A CI `portal-gsc-f421-finance-token-property-ci.yml` conserva o
laboratório PostgreSQL18 descartável da F118, em que há autenticação
first-party, estados PKCE cifrados, controle por membership e revogação
concorrente, com migrações `up/down`.

Para não depender apenas de CI independente:
- valida a HEAD exata e a **allowlist restrita a 12 arquivos**
  alterados nesta PR;
- usa fontes anteriores de GSC F109 e SEO F104 com SHAs fixados;
- roda casos adversariais do receptor F116 no localhost;
- roda casos positivos e negativos de permissão de propriedade F117;
- roda os três cenários F118 com sessão MFA, callback, state de uso
  único e POST sintético à porta 18765;
- mantém os workflows financeiros/Laravel herdados.

## Fronteira de confiança — não flexibilizar

F116/F117/F118 são componentes **CLI testing**, não rotas de produção.
A resposta de `sites.list` é fabricada; nenhum token vem de Google,
não há HTTPS externo, redirect real, consentimento do titular, cofre,
grant operacional nem envio de dados a serviços externos.

O conjunto consolidado demonstra interoperabilidade de contratos, não
concede `seo.gsc.view` de forma automática e não autoriza
consulta de propriedade, cobrança bancária ou publicação da VPS.

## Gates operacionais

1. Projeto Google Cloud, OAuth/redirect URI e escopos realmente
   homologados, com consentimento do cliente e cofre de tokens.
2. Transporte HTTPS oficial Google com validação de origem, TLS,
   retry rate-limit, revogação e auditoria independente.
3. Prova assinada da propriedade e RLS/IAM por empresa no instante
   da chamada, sem confiar em `sites.list` sintético.
4. Staging privado da Leaseweb, backup/restauração, rollback, logs
   expurgados de segredos, revisões AppSec/DBA/LGPD e GO formal.
5. Homologação financeira Asaas/C6 separada; nem GSC nem
   Performance autorizam real money nesta PR.

Nenhuma alteração da `main`, servidor, dados de clientes ou
cobranças reais é feita.
