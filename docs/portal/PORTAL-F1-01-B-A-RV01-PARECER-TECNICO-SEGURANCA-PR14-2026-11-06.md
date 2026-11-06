# PORTAL-F1-01-B-A-RV01 — Revisão técnica e de segurança do PR #14

**Data:** 06/11/2026  
**Objeto:** [PR #14 — domínio de organizações, vínculos e permissões](https://github.com/rafhael100/imagine-portal/pull/14) no Portal Imagine.  
**Natureza:** **análise estática do diff, dos testes e evidências de CI**, não auditoria de segurança independente, pentest ou homologação de produção.  
**Resultado:** **CONFORME AO RECORTE DE DOMÍNIO PURO B-A, COM RESSALVAS E CORREÇÕES RECOMENDADAS ANTES DE UMA DECISÃO DE MERGE CONTROLADO.** Não promover nem tratar como autorização operacional.  
**Autorização desta etapa:** somente revisão técnica e de segurança do PR, com registro de resultados; **não autoriza novo código, merge ou deploy**.

## 1. Rastreabilidade e preservação dos gates

- Repositório: `rafhael100/imagine-portal`.
- PR #14: **open**, **draft**, **não mesclado**; head `dev/portal-f1-01-b-domain-2026-11-05` em `916894fe9c322961d28d7a578a9335f137ceeb15`; base `dev/fast-track-portal-2026-11-05` em `098cdfd50024ea872861f3ba3a058409e5f976b7`.
- PR #11: **draft**, não mesclado; código-fundação F1-01-A preservado.
- `main`: `50b683a499e674641df5749051f3d05f7ac3f627`, sem alteração.
- Diff do PR #14: **16 arquivos**, **5 commits**, todos em `apps/portal/`; nenhum arquivo de banco, migração SQL, Eloquent, API de login, integração externa ou código Node/SQLite legado modificado.
- Escopo aprovado: [F1-01-B-DEC-01](PORTAL-F1-01-B-DEC-01-APROVACAO-PROGRAMACAO-CONTROLADA-2026-11-05.md); execução documentada em [F1-01-B-A PR #14](PORTAL-F1-01-B-A-EXECUCAO-CONTROLADA-PR14-2026-11-06.md).

## 2. Evidência de CI conferida

**Execução:** [Laravel CI — #42167989718](https://github.com/rafhael100/imagine-portal/actions/runs/42167989718) do HEAD atual. Ambos os jobs concluíram **success**, PHP **8.3** e **8.4**.

| Verificação | Resultado |
|---|---|
| `php artisan test`, suítes Unit + Feature | **18 testes e 36 asserções aprovados por versão PHP** |
| `composer validate --strict`, `composer install` via `composer.lock` | Passaram |
| Lint dos arquivos PHP | Passou |
| `composer audit --locked` | Sem avisos de vulnerabilidade publicados **na execução** |
| Testes de integração com PostgreSQL, login/MFA, CRM, APIs ou produção | **Não executados e não fazem parte de B-A** |

**Advertência:** CI verde neste recorte demonstra apenas as regras de domínio com entradas sintéticas. Não valida a segurança das fontes de identidade, dos vínculos reais, da autorização HTTP, de dados persistidos, nem todas as ameaças possíveis.

## 3. Resultados positivos do exame estático

- `OrganizationAuthorizationService::decide` implementa **deny by default** se ator, contexto, organização do recurso, membership ativa ou permissão reconhecida e explicitamente atribuída não concordarem.
- Comparações de `PortalUserId` e `OrganizationId` são exatas; permissão é enum fechada. Não há wildcard ou privilégios implícitos de `Owner`/staff na implementação.
- `MembershipStatus` separa `invited`, `active`, `suspended` e `revoked`; só `active` pode permitir, com outras condições.
- Objetos de domínio não possuem consultas SQL, chamadas HTTP, autenticação real, e-mail/CPF/CNPJ, integração CRM, operações de cobrança, segredos ou criação de usuários.
- `phpunit.xml` habilita Unit e Feature; os testes cobrem caso positivo, múltiplas organizações, permissões distintas, recurso de outra empresa, identidade diferente, ausência de vínculo, suspenso/revogado/convidado, permissões ausentes/desconhecidas e IDs inválidos.
- `AGENTS.md` reconhece a autorização posterior B-A e conserva as proibições de B-B/C, `main`, VPS e deploy.

## 4. Achados e recomendações, por prioridade

### RV14-SEC-01 — Fonte de autoridade não autenticada (GATE ALTO para integração real; **não é vulnerabilidade operacional explorável neste PR isolado**)

**Evidência:** [`OrganizationAuthorizationService.php#L17-L35`](https://github.com/rafhael100/imagine-portal/blob/916894fe9c322961d28d7a578a9335f137ceeb15/apps/portal/app/Domain/Authorization/OrganizationAuthorizationService.php#L17-L35) aceita um `AuthorizationContext` e uma `OrganizationMembership` já construídos. A [membership](https://github.com/rafhael100/imagine-portal/blob/916894fe9c322961d28d7a578a9335f137ceeb15/apps/portal/app/Domain/Organizations/OrganizationMembership.php#L13-L28) pode ser instanciada diretamente com estado `Active` e qualquer subconjunto do enum de permissões. O avaliador não prova que esses valores vieram de sistema confiável — comportamento intencional do recorte **sem I/O**.

**Risco ao integrar indevidamente:** se futura camada HTTP/serviço aceitar ator, organização ou membership do cliente sem verificar autenticidade e origem, poderá criar uma autorização falsa.

**Exigência antes de B-B/C/qualquer rota real:** resolver o ator por autenticação **somente Portal**; resolver o vínculo e escopo por fonte servidora oficial, com autorização por organização/recurso e segregação; jamais aceitar objeto de membership ou permissões enviadas pelo cliente como evidência. Testar adulteração, enumeração, ausência de vínculo, organizações cruzadas e revogação. **Não ligar o avaliador a endpoints agora.**

### RV14-SEC-02 — Identidade Portal × CRM sem prova de origem (GATE ALTO para login/integração real; **deferido por escopo B-A**)

**Evidência:** [`PortalUserId.php#L13-L22`](https://github.com/rafhael100/imagine-portal/blob/916894fe9c322961d28d7a578a9335f137ceeb15/apps/portal/app/Domain/Identity/PortalUserId.php#L13-L22) identifica usuários por string opaca; o teste [`OrganizationDomainBoundariesTest.php#L45-L59`](https://github.com/rafhael100/imagine-portal/blob/916894fe9c322961d28d7a578a9335f137ceeb15/apps/portal/tests/Unit/OrganizationDomainBoundariesTest.php#L45-L59) usa **strings diferentes** (`portal-actor-a` e `crm-actor-a`). Portanto, comprova desigualdade de valores, **não** uma fronteira criptográfica/operacional Portal–CRM ou que um ID externo com o mesmo texto seria rejeitado.

**Exigência futura:** definir fonte/emissor de identidade, mapeamento independente e testes de mistura de IDs com valores iguais, sem criar SSO por coincidência de ID/e-mail/CNPJ. Seguir D02-SEC01/03, sem compartilhar credenciais, sessões ou bancos de identidade.

### RV14-SEC-03 — Vigência e revogação do vínculo sem contrato técnico (GATE ALTO para persistência real; **deferido por escopo B-A**)

**Evidência:** o serviço recebe **um** `OrganizationMembership` de estado estático; não seleciona vínculo oficial, não considera políticas versionadas, expiração, revogação ocorrida entre leitura e uso, duplicatas ou cache obsoleto.

**Exigência futura:** especificar seleção canônica de membership, invariantes de unicidade, logs minimizados, autorização de mudanças de perfil, prevalência de suspensão/revogação, proteção a leituras obsoletas, concorrência e testes de isolamento. D02-SEC02, D03 físico/RLS e P0-DOC06 **continuam ABERTOS**.

### RV14-TEST-01 — Profundidade limitada das provas negativas (MÉDIO para prontidão futura; melhoria recomendada de B-A)

Há testes positivos e negativos úteis, mas não há teste que simule **todas** as combinações da matriz ator × empresa selecionada × empresa proprietária × membership × permissão. Não há autenticação efetiva, verificação de origem da membership, controle de permissões por fonte e cenário em que um ID externo é igual a um ID Portal. A assinatura `AuthorizationDecision::allow()` é pública, portanto o simples recebimento de um objeto de decisão não pode ser considerado prova inviolável de autorização; a futura camada de execução deve invocar diretamente o avaliador com dados autenticados e de fonte confiável.

**Melhoria recomendada antes de promoção controlada de B-A:** acrescentar testes de matriz e evidenciar explicitamente limites de proveniência; não simular garantias de segurança que dependem de persistência.

### RV14-DOC-01 — README contém informação histórica desatualizada (BAIXO; correção recomendada antes do merge)

**Evidência:** [`README.md#L35`](https://github.com/rafhael100/imagine-portal/blob/916894fe9c322961d28d7a578a9335f137ceeb15/apps/portal/README.md#L35) afirma que o `composer.lock` não foi gerado e que ainda falta validar testes. No mesmo ramo, o `composer.lock` **existe** e o CI PHP 8.3/8.4 passou; o texto é histórico e pode induzir executores a fazer `composer update` desnecessário.

**Correção recomendada:** marcar o trecho como registro histórico superado ou substituir por instrução atual de `composer install` a partir do lock versionado, vinculando execução CI. Conferir também a menção a testes ainda não executados no checklist original. Não alterar o código funcional sem autorização.

## 5. Parecer e gates de decisão

**Parecer:** a implementação atual **satisfaz o recorte B-A de objetos puros e testes sintéticos**, sem indício de invasão de escopo no diff revisado. O risco principal está em **interpretar este domínio como uma camada de segurança pronta para uso real**. Não há evidência de vulnerabilidade em endpoint operacional porque **não existe endpoint operacional implementado** neste PR.

**Recomendação antes de avaliar merge somente para `dev/fast-track-portal-2026-11-05`:** corrigir a documentação desatualizada, esclarecer explicitamente no contrato de domínio a responsabilidade de proveniência de ator/membership e, se aprovado, ampliar testes de adversário e matriz de isolamento **sem I/O**, seguido de nova validação CI e revisão do diff.

**Bloqueios mantidos:** PR #14 draft, PR #11 draft, PR #10 draft; **sem merge para nenhuma branch**, `main` intacta; sem deploy, banco real, login, MFA, API, CRM/Sites, n8n, custos ou dados reais. B-B/C, F1-02, D02-SEC01..06, D03 físico, P0-DOC01..14 e demais gates **não foram liberados**.

**Próxima decisão solicitável ao responsável:** autorizar **somente correções pontuais de documentação, explicitação de proveniência e ampliação de testes sintéticos no PR #14**, mantendo todas as proibições. Merge controlado em branch de desenvolvimento exigiria **autorização própria posterior**, após verificação dos novos testes e do SHA.
