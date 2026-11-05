# PORTAL-F1-01-B — Estrutura de organizações, usuários, vínculos e permissões (proposta v0.1)

**Data:** 05/11/2026  
**Projeto:** Portal Imagine — novo Laravel/PostgreSQL  
**Status:** **PROPOSTA DOCUMENTAL PARA DELIBERAÇÃO — F1-01-B NÃO AUTORIZADA PARA PROGRAMAR, TESTAR OU FAZER MERGE**.  
**Base técnica:** [F1-01-A revisada](PORTAL-F1-01-A-RV01-REVISAO-PR11-INTEGRACAO-CONTROLADA-2026-11-05.md), código no [PR #11](https://github.com/rafhael100/imagine-portal/pull/11), head `098cdfd50024ea872861f3ba3a058409e5f976b7`; `main` permanece isolada.  
**Precedência funcional:** D02 aprovada para login independente Portal×CRM; D03 modelo lógico direcional; D03-R02 legado é protótipo/dados de teste. **D02-SEC01/02 e P0-DOC06 ainda não encerrados**.

## 1. Resultado funcional desejado

O Portal poderá futuramente atender uma pessoa que participa de uma ou mais empresas e permitir que cada organização tenha seus serviços e membros, **sem** tornar e-mail, CNPJ ou nome indício suficiente de autorização. A pessoa representa um usuário próprio **do Portal**; a empresa é um contexto operacional/contratual distinto; o representante financeiro/contratante também pode ser entidade separada. O CRM mantém conta, login e banco próprios.

**Exemplo fictício:** `usuario_demo_A` é vinculado expressamente às organizações `empresa_demo_1` e `empresa_demo_2`, mas verá dados de cada uma apenas quando o contexto for autorizado. `usuario_demo_B` tem vínculo com `empresa_demo_2` e não pode acessar a 1. A troca de contexto NÃO herda direitos de outra empresa. Os registros são exemplos conceituais, não dados a importar do SQLite.

## 2. Deliberação de implementação: três recortes

| Alternativa | Escopo técnico | Avaliação |
|---|---|---|
| **B-A — Contratos puros e testes sintéticos de isolamento** | PHP `readonly` DTOs/value objects/interfaces ou equivalentes, modelos de identidade fictícia, `Membership`, `PermissionScope`, serviço de avaliação de permissão **sem camada web, banco, sessão, login ou API**. PHPUnit com fixtures totalmente fictícias | **RECOMENDADA como primeira F1-01-B**; baixo risco e reversível |
| **B-B — Persistência e migrações físicas PostgreSQL** | Tabelas de organizações/usuários/membros, migrations, constraints, índices, RLS/roles e testes de banco | **POSTERGAR** até D02-SEC02, D03 físico, P0-DOC06 e aprovações específicas |
| **B-C — Cadastro, convite, login e permissões reais** | Identidades, MFA, recuperação, RBAC operacional, console de administração e acesso multitenant verdadeiro | **FORA DO ESCOPO**; depende D02-SEC01..06, políticas jurídicas e gates adicionais |

A aprovação futura de B-A **não autoriza** B-B ou B-C. `F1-02` permanece marco separado.

## 3. Modelo conceitual proposto para B-A

| Conceito/objeto candidato | Responsabilidade | Invariante de segurança |
|---|---|---|
| `PortalActor` / `PortalUserId` | Identidade local do Portal; sem segredo/senha nesta fase | Não é `CrmUser`, não oferece SSO |
| `OrganizationId` / `CustomerOrganization` | Identificador estável de contexto da empresa | Não deduzir acesso por CNPJ/e-mail/nome |
| `MembershipId` / `OrganizationMembership` | Vínculo explícito usuário ↔ organização, estado e eventual perfil | Sem vínculo ativo, **deny** |
| `MembershipStatus` | Ex.: `invited`, `active`, `suspended`, `revoked` — **valores propostos** | Somente `active` é elegível a operações permitidas; convite não concede acesso |
| `Permission` / `OrganizationRole` | Permissões **por contexto**, futuro catálogo administrável | Não permitir permissão global implícita |
| `AuthorizationContext` | Ator, organização explícita, permissão solicitada, membership específico | Escopo idêntico ao recurso/ação; rejeitar mismatch |
| `OrganizationAuthorizationDecision` | Resultado `allow/deny` e motivo técnico genérico | Fail closed; não revelar informações de empresa de terceiro |
| `ContractingPartyRef` | Referência lógica separada a CPF/CNPJ/empresa pagadora | Usuário/membro não é necessariamente contratante |
| `ServiceRef` | Referência a serviço/projeto de uma organização | Acesso ao serviço exige vínculo confirmado e escopo de autorização |

**Importante:** nomenclatura, classes, papéis, status e assinatura das interfaces de B-A são **propostos**, não schemas SQL, contratos de API nem implementação automática. Candidatos futuros `portal_users`, `customer_organizations`, `organization_memberships` e `organization_roles` vêm da D03 e não deverão ser materializados em B-A.

## 4. Políticas de autorização de domínio propostas

1. **Negação como padrão:** organização sem membership ativa, permissionamento inexistente, contexto omisso, papel desconhecido, usuário revogado, organização diferente ou referência cruzada resultam em `deny`.
2. **Membership N:N explícita:** usuário pode participar de mais de uma organização, cada vínculo tem contexto e papel próprios. Nenhuma associação por coincidência de e-mail/CNPJ.
3. **Contexto expresso:** cada avaliação exige `actor_id`, `organization_id`, recurso/ação e membership elegível; jamais confiar em `organization_id` recebido do frontend sem revalidação em camada servidora futura.
4. **Separação de responsabilidades:** colaborador Imagine não possui acesso automático a dados de cliente; eventual administração assistida requer escopo, justificativa, prazo e auditoria, a detalhar antes de B-C.
5. **Perfil ≠ permissão ilimitada:** eventual `owner/admin/member` será lista versionada de permissões, não liberdade para acessar outras empresas, CRM ou pagamentos.
6. **Revogação prevalece:** um vínculo revogado/suspenso não é reativado por cache, mudança de contexto, sessão ainda presente ou mera existência de contrato.
7. **Dados inexistentes nesta fase:** nenhum nome real, CPF/CNPJ real, e-mail pessoal, token, hash, credencial, documento, contrato real ou dado do legado entra nos testes.
8. **CRM independente:** mesmo IDs externos, mesmo e-mail e mesmo CNPJ não criam acesso humano ao CRM, nem substituem a autorização servidor a servidor.
9. **Duas autoridades:** contrato e pagamento pertencem a seus módulos oficiais; uma decisão de acesso a dados não comprova aceite de orçamento ou assinatura vigente.
10. **Impossibilidade de efeito lateral:** serviços e modelos B-A não escrevem banco, enviam eventos externos, criam contas, publicam páginas ou modificam preço.

## 5. Cenários de teste **futuros** (sem execução/autorização nesta minuta)

| Cenário sintético | Resultado esperado |
|---|---|
| Ator A com membership ativa e permissão `view_service` na Empresa 1 | `allow` somente para recurso Empresa 1 |
| Ator A vinculado às Empresas 1 e 2, seleciona 2 | Permissões da Empresa 2 apenas; não carregar direitos da 1 |
| Ator B sem membership na Empresa 1 | `deny` |
| Ator A com convite ainda pendente | `deny` |
| Membership de A revogada ou suspensa | `deny` |
| Mesmo e-mail/CNPJ ou nome em duas empresas | Não criar vínculo, `deny` se não houver membership |
| Recurso referenciado aponta para empresa diferente | `deny` mesmo com permissão na empresa original |
| Contexto de organização ausente, ID inválido ou papel desconhecido | `deny`; nenhum dado sensível no retorno |
| Usuário com papel de colaborador Imagine sem delegação | `deny` a dados privados de empresa |
| Tentativa de interpretar identidade CRM como identidade Portal | `deny`; não conceder sessão nem permissão |
| Plano cortesia CRM ou serviço do Portal associado | Nenhuma permissão de CRM implícita |

**Mínimo de aceite para implementação futura B-A:** suíte PHPUnit com testes positivos/negativos de fronteiras, contratos puros sem I/O, verificação de ausência de endpoint, schema e credenciais, `composer.lock` preservado e diff limitado ao aplicativo e eventuais testes/workflows estritamente necessários. **Não confundir esse critério com teste já executado.**

## 6. Detalhes importantes a fechar antes da persistência B-B

- Ciclo de vida de convite e aceite; emissão/revogação; identidade validada e MFA; responsabilidade por empresa e por agente da Imagine.
- Administração de empresa e papéis; separação entre organização operacional, contratante e titular de cobrança.
- Regras de delegação de ações sensíveis (contratar, alterar CRM vinculado por chamado, aprovar site, gerenciar financeiro e perfis).
- Constraints/FKs capazes de impedir vínculos entre organizações erradas; avaliação de PostgreSQL RLS, testes de concorrência, isolamento transacional, auditoria minimizada e IDs estáveis.
- LGPD, retenção, registros de consentimento aplicáveis, controle de anexos, logs e prevenção de abuso.
- Segurança dos tokens e sessões Portal e suas políticas MFA/RBAC; SSO Portal–CRM **não aprovado**.
- Contratos técnicos Portal×CRM e Portal×Imagine Sites, P0-DOC03/06/12 ainda abertos.
- Homologação de ambientes e credenciais exclusivamente Portal, sem banco compartilhado CRM.

## 7. Gestão Git/Codex sugerida para a eventual fase B-A

1. Preservar PR #11 **draft**, sem merge em `main`. Validar SHA da branch `dev/fast-track-portal-2026-11-05` imediatamente antes de qualquer derivação.
2. **Só após aprovação expressa B-A:** criar um ramo filho, por exemplo `dev/portal-f1-01-b-domain-2026-11-05`, partindo do HEAD da fundação validada.
3. Criar exclusivamente classes/contratos de domínio dentro de `apps/portal/` e **testes com identidades fictícias**. **Sem** schema, migrations, models Eloquent, login, UI, tenants reais, contas externas, API/PSP, alteração da `main` ou VPS.
4. Abrir PR **draft** cujo destino é a branch de desenvolvimento pai, não `main`, com `php artisan test` no CI para PHP 8.3/8.4, diff revisado e relatórios.
5. Não iniciar B-B, B-C ou F1-02 por dedução; reavaliar lacunas D02-SEC02/P0-DOC06 antes de qualquer autenticação ou armazenamento real.

## 8. Proposta de decisão para o responsável

**PORTAL-F1-01-B-DEC-01 — NÃO APROVADA:** autorizar **exclusivamente B-A**, isto é, modelagem em código puro PHP/Laravel de organizações, atores, memberships, permissões e testes **somente fictícios e sem persistência**, em nova branch filha de desenvolvimento, PR draft, sem merge na `main`, login, dados pessoais ou integrações.

**PORTAL-F1-01-B-DEC-02 — NÃO APROVADA:** aprovar a diretriz `INT-A` de manter a fundação PR #11 na branch de desenvolvimento e nenhum merge antes dos gates; [proposta de integração](PORTAL-F1-01-A-RV01-REVISAO-PR11-INTEGRACAO-CONTROLADA-2026-11-05.md).

**Status final:** F1-01-B **documentada e proposta**, mas **não autorizada para programação**. D02-SEC01/02, D03 físico, P0-DOC06 e as demais travas permanecem abertas; F1-01-A tem testes de scaffold passando, sem prontidão para produção.
