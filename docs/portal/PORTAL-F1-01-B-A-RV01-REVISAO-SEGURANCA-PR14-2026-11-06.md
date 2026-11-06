# PORTAL-F1-01-B-A-RV01 — Revisão técnica e de segurança do PR #14

**Data:** 06/11/2026  
**Escopo:** revisão somente de código, diff, testes e limites de autorização da F1-01-B-A.  
**Parecer:** **APTO COMO PROTÓTIPO DE DOMÍNIO PURO, COM RESSALVAS EXPLÍCITAS E SEM AUTORIZAÇÃO DE MERGE OU USO OPERACIONAL.**  
**Referências:** [PR #14](https://github.com/rafhael100/imagine-portal/pull/14), [aprovação B-A](PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md), [execução PR14](PORTAL-F1-01-B-A-EXECUCAO-CONTROLADA-PR14-2026-11-06.md).

## 1. Versões e verificações conferidas

- Repositório `rafhael100/imagine-portal`. PR #14 **aberto, draft, sem merge**, head `dev/portal-f1-01-b-domain-2026-11-05`, SHA `916894fe9c322961d28d7a578a9335f137ceeb15`.
- Base exclusiva `dev/fast-track-portal-2026-11-05`, SHA `098cdfd50024ea872861f3ba3a058409e5f976b7`, PR #11 ainda draft, sem merge.
- `main` verificada **inalterada** no SHA `50b683a499e674641df5749051f3d05f7ac3f627`.
- Comparação Git: **5 commits à frente, 0 atrás; 16 arquivos alterados e TODOS sob `apps/portal/`**.
- [CI Laravel — run #42167989718](https://github.com/rafhael100/imagine-portal/actions/runs/42167989718), `pull_request`: **success** em PHP 8.3/8.4, com **18 testes e 36 asserções por versão**; `composer validate`, instalação pelo lockfile, lint PHP e auditoria `composer audit --locked` sem alertas na data da execução.
- [CI Node legado — run #42167989676](https://github.com/rafhael100/imagine-portal/actions/runs/42167989676) **success**, evidência separada; não comprova integração com Laravel.
- `composer.lock` preservado; sem migrations, modelos Eloquent, controladores HTTP, cadastro, sessão, tokens, credenciais ou mudanças na VPS.

## 2. Revisão das regras implementadas

| Controle B-A | Evidência na alteração | Parecer |
|---|---|---|
| Identificador de ator e organização explícitos | `PortalUserId`, `OrganizationId`, `AuthorizationContext` | Adequado para protótipo, sem autenticação real |
| Vínculo explícito `ator × organização` | `OrganizationMembership` e IDs de escopo | Presente, mas **a origem confiável do vínculo não existe nesta fase** |
| Estado do vínculo | `MembershipStatus` ativo/convidado/suspenso/revogado | **Só ativo** pode produzir `allow`; teste negativo para demais estados |
| Permissão tipada e explícita | `Permission` enum e `hasPermission` estrito | Sem wildcard nem superadmin implícito |
| Controle de organização do recurso | Comparação `organizationId` com `resourceOrganizationId` | Nega divergência, **assumindo que o identificador do recurso foi resolvido confiavelmente pelo servidor futuro** |
| Identidade e empresa do vínculo | `membership.actorId` e `membership.organizationId` | Compara com contexto; nega divergência |
| Negar por padrão | `OrganizationAuthorizationService::decide()` | Nega contextos nulos, vínculo ausente, permissionamento ausente e valores desconhecidos |
| Testes Unit + Feature | `phpunit.xml` expandido, testes sintéticos | Passaram em PHP 8.3 e 8.4; não são testes de banco/API/RLS |
| Isolamento e escopo Git | 16 arquivos em `apps/portal/` | Conforme B-A |

## 3. Achados, classificação e tratamento

### SEC-RV01-01 — Origem/autenticidade do vínculo e do contexto (CRÍTICO ANTES DE PRODUÇÃO; FORA DE B-A)

O método `decide(AuthorizationContext, ?OrganizationMembership)` recebe **um vínculo construível por qualquer chamador PHP com os tipos permitidos**. Como B-A é código puro sem camada de confiança, isso **não é evidência de bypass explorável via HTTP nesta versão**, pois **não existe endpoint**. Antes de usá-lo em API/login real, uma camada **servidora autenticada** deve obter ator, vínculo vigente, permissões e organização real do recurso de fontes confiáveis, impedir forjamento, registrar decisões e falhar fechada em caso de dúvida. **Proibir aceitar `membership`, `role`, `permissions` e `resourceOrganizationId` do frontend como provas.**

**Critério de saída para fases futuras:** contrato de resolvedor de identidade/vínculo/recursos, escopo por tenant e testes de falsificação/acesso cruzado sob D02-SEC/P0-DOC06, antes de qualquer rota real.

### SEC-RV01-02 — Estado da organização ainda não modelado (PENDENTE ANTES DE USO REAL)

O enum `MembershipStatus` cobre **o estado do vínculo**, não o estado comercial/operacional/suspensão da **empresa** nem as condições do contrato/serviço. O texto do README menciona “organização ativa”, mas o avaliador **não verifica isso**; portanto, não presumir que contrato cancelado, empresa bloqueada ou serviço suspenso impeçam acesso. **Não confundir autorização de leitura de dados com direito a serviço ativo.**

**Critério futuro:** separar políticas de organização, contrato, serviço e cobrança, definir autoridades e estados, e seus testes negativos. Não acrescentar schema ou regra contratual nesta revisão.

### SEC-RV01-03 — Independência Portal×CRM é apenas conceitual (PENDENTE ANTES DE USO REAL)

O `PortalUserId` é um objeto opaco local; o teste com ator rotulado `crm-actor-a` prova somente que **IDs diferentes não são iguais**. Ele **não** impede que uma string vinda de outro sistema seja convertida em `PortalUserId` por um componente futuro. A separação efetiva de autenticação, sessões, contas, CRM e identidades dependerá de backend e controles D02-SEC.

**Critério futuro:** delimitação explícita de origem/namespace confiável de identidade e autenticadores próprios; não criar SSO, nem fusão por e-mail/CNPJ.

### SEC-RV01-04 — Testes de política e integração ainda incompletos (PENDENTE; NÃO BLOQUEIA DOMÍNIO FICTÍCIO)

A suíte de 18 testes cobre boa parte do núcleo puro, mas **não pode validar revogação sob concorrência/cache, transações, RLS, controle por resource ID realmente resolvido, payloads adulterados, delegações temporárias ou ciclo de convites**. O teste de igualdade e nome/e-mail/CNPJ não demonstra ausência de vinculação em um cadastro persistente inexistente. A auditoria de dependências do Composer é pontual e não é pentest.

**Critério futuro:** planos/testes de autorização em backend e PostgreSQL, cenários negativos de concorrência e isolamento, privacidade e revogação; manter gates abertos.

### QUAL-RV01-05 — Clareza documental (MELHORIA NÃO BLOQUEANTE)

O README ainda contém trecho histórico que afirma que `composer.lock` não existia, superado por CI e lockfile versionado na F1-01-A. As seções posteriores já registram o estado atualizado; sugerir limpeza de texto em próxima revisão documental com preservação histórica, sem alterar software B-A agora.

## 4. Julgamento e guardrails

**Aprovar tecnicamente o escopo do protótipo B-A não é homologar segurança multiempresa real.** Não se identificou, na inspeção das 16 alterações e nos cenários executados, violação dos limites de B-A; entretanto, a autenticação da identidade, a origem dos vínculos, o estado da organização, a integridade da titularidade do recurso e a separação operacional Portal×CRM **não foram implementados nem testados**.

**Decisão de revisão:** manter PR #14 **DRAFT SEM MERGE**, com achados rastreados e pendências para fases futuras. A revisão **não autoriza** integração na branch pai nem na `main`, nova etapa B-B/B-C, migrations SQL, login, MFA, conexão de banco, VPS ou deploy. D02-SEC, D03 físico, P0-DOC06 e demais P0 **continuam abertos**.

## 5. Próximo ato de direção

Deliberar **se autoriza um merge controlado do PR #14 exclusivamente na branch de desenvolvimento** `dev/fast-track-portal-2026-11-05`, mantendo `main`, PR #11, VPS e gates preservados, **ou** se prefere deixar o PR draft enquanto especificamos os contratos de origem confiável de permissões. Não presumir nenhuma das alternativas a partir deste documento.
