# PORTAL-F110 — Pré-verificação de identidade para aceite futuro de convites

**Data:** 10/11/2026. **Situação:** código integrado ao Laravel, contrato de segurança sem autenticação operacional e **NO-GO de produção**.

## Entrega

A F109 protege o material dos tokens de convite contra vazamento acidental por inspeção de objetos PHP. A F108 ensaia, em PostgreSQL 18 descartável, o bloqueio de mudanças concorrentes na identidade, no vínculo e na organização enquanto ocorre uma transição de convite. Nenhuma dessas etapas comprova a origem de quem faz a solicitação.

A F110 adiciona um **contrato de identidade separado para o Portal**:

- `PortalInvitationIdentitySource`: interface para uma futura autoridade de sessão **primeira-parte, pertencente somente ao Portal**, nunca derivada de dados enviados pelo cliente ou de cookies/identidades CRM.
- `DenyingPortalInvitationIdentitySource`: implementação padrão sem integração externa, sem sessão, SQL, CRM ou qualquer leitura; devolve sempre `null`.
- `PortalInvitationActorEvidence`: DTO **sintético e construível por qualquer código**, apenas para testar uma matriz de estados. Checa sessão ativa, empresa selecionada, e-mail sinalizado como verificado, MFA sinalizado como verificado **posteriormente à emissão da sessão**, e MFA com idade sintética máxima de **300 segundos**; exige relógio dentro da validade da sessão e igualdade das revisões de segurança de identidade, vínculo e organização.
- `PortalInvitationPreflight`: verifica entradas estruturais do convite Base64URL canônico e da referência opaca de sessão antes de consultar a fonte. Bloqueia entradas nulas, malformadas e referências de tamanho inválido; rejeita falhas ou exceções da fonte sem revelar informações do provedor. Não grava token, digest, ator ou empresa no veredito.
- `PortalInvitationPreflightVerdict`: contém somente o indicador `syntheticInputsPlausible`. Os métodos `canConsumeInvitation()`, `canIssueSession()` e `canAuthorizeHttp()` **retornam sempre falso**, mesmo quando um fixture injetado resulta em preflight positivo.
- `AppServiceProvider`: vincula a interface apenas à fonte **Denying** por padrão. O Laravel não disponibiliza endpoint de convite, login, SSO ou ativação; a única rota continua `/up`.

## Evidências de teste

Em PHP 8.3/8.4, a suíte de unidade percorre casos de prova sintética válida, referência de sessão ausente/longa, convite inválido, origem CRM como bearer, empresa diferente, sessão inativa/futura/expirada, e-mail não confirmado, MFA ausente/futuro/antigo, MFA anterior à sessão, e desencontro de qualquer revisão. Também testa o limite de 300 segundos e exceções do fornecedor de identidade. A suíte Feature inicializa o contêiner Laravel real, confirma o adaptador negador por padrão e testa que `/login`, `/crm/sso` e endpoints de convite permanecem inexistentes e sem cookies de sessão.

O workflow `portal-f110-invitation-preflight-ci.yml` executa essas suítes e regressões de token e deny-all. O PR dispara também os testes gerais e o smoke Docker privado.

## O que **não** está comprovado

Uma instância de `PortalInvitationActorEvidence` **não tem valor como prova de identidade**: seus campos e booleans são construíveis por código de teste. Um adaptador real deverá autenticar origem da sessão por armazenamento primeiro-parte, conferir e-mail com mecanismo verificado, MFA vinculada à sessão com reautenticação, revogação em banco e conexão autorizada, trilha auditável e consentimento/privacidade. Também deverá assegurar a transição F108/F107 dentro das mesmas barreiras transacionais e demonstrar isolamento PostgreSQL RLS multiempresa operacional, rate limiting, CSRF, recuperação segura e testemunho externo SEC-R03.

A exigência sintética de 300 segundos é **um candidato de política para aceite sensível**, não uma política aprovada de produto; pode ser alterada após revisão de ameaças e AppSec. A integração com CRM continua expressamente excluída.

**Nenhuma sessão real, convite enviado/consumido, vínculo ativado, endpoint, banco de cliente, deploy VPS ou merge em `main`.** PR DRAFT e sem despesas adicionais.
