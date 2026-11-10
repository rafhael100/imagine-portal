# PORTAL-F111 — Trava automática de segurança da identidade e dos convites

**Data:** 10/11/2026. **Situação:** teste estático de pré-homologação, **não** é aprovação de produção.

## Objetivo e implementação

A F110 adicionou o contrato `PortalInvitationIdentitySource`, a implementação `DenyingPortalInvitationIdentitySource` e uma pré-verificação sintética de sessão, empresa, e-mail, MFA e revisões. O resultado só indica a plausibilidade de dados construídos para testes: nunca consome convite, emite sessão ou autoriza HTTP.

A F111 introduz `apps/portal/tools/f111-audit-invitation-release-fence.mjs`, que verifica, sem rede e sem dados reais, se a implementação Laravel ainda mantém essas condições:

- O `AppServiceProvider` vincula uma única fonte de identidade do Portal à implementação negadora, sem substituição acidental por CRM, adapter provisório ou fonte sem revisão.
- O método `findFor()` dessa fonte continua devolvendo `null`.
- O `PortalInvitationPreflight` continua exigindo validação de token canônico e um resultado bloqueado para entradas que falham.
- O `PortalInvitationPreflightVerdict` continua retornando **`false` em `canConsumeInvitation()`, `canIssueSession()` e `canAuthorizeHttp()`**, mesmo diante de uma prova de identidade sintética positiva.
- O prazo de MFA sintético continua limitado a cinco minutos, sem alterar a política sem análise.
- `routes/` contém apenas as rotas fechadas do scaffold, `bootstrap/app.php` mantém somente o health-check `/up` e eventuais arquivos `app/Http` não podem acessar os contratos de identidade/aceite ainda não operacionais.
- O auditor só funciona com `APP_ENV=testing` e `PORTAL_F111_RELEASE_FENCE_LAB=1`; falha fechada em ambiente `production`, configuração ausente, arquivo fora de padrão ou symlink.

## Testes negativos e integração

`apps/portal/tests/ui/test-f111-invitation-release-fence.mjs` copia somente os arquivos necessários para uma pasta temporária e simula alterações adversariais: substituição do adaptador negador, fonte que retorna prova fabricada, retorno verdadeiro em emissão de sessão, consumo ou autorização HTTP, afrouxamento da janela MFA, remoção da validação de token, inclusão de uma rota HTTP de aceite e exposição de API. Também testa arquivos simbólicos e desativação do gate.

O workflow F111 executa auditoria e mutações em Node 22 e 24, além dos gates F93/F94 de exclusão de laboratórios e contenção do runtime. O PR herdará testes Laravel, suítes F110 e smoke Docker privado da linha atual.

## Restrições e próximos gates

Isto é um **verificador estático de regressão**, incapaz de provar que uma sessão foi emitida ou validada com segurança. Não substitui análise de fluxo de dados, threat modeling, revisão de código independente, pentest, proteção de segredos, RLS multiempresa, transações de revogação e auditoria externa SEC-R03. Alterações intencionais no futuro fluxo autenticado exigirão um novo modelo de autorização aprovado e atualização fundamentada dos gates, não simples supressão da verificação.

**Nenhum HTTP de negócio, autenticação operacional, consumo real de convite, envio, acesso ao CRM, alteração na `main`, deploy VPS ou custo novo. PR draft / NO-GO.**
