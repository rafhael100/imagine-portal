# SEC-P02-RV01 — Revisão técnica preliminar E01/E02: identidade Portal e membership

**Data:** 06/11/2026.
**Objeto:** análise estática seletiva dos contratos SEC-P02-E01/E02, código de domínio SEC-P01 e planejamento PostgreSQL F1-01-B-B/PR #19.
**Natureza:** PARECER TÉCNICO PRELIMINAR ASSISTIDO, NÃO INDEPENDENTE, SEM PENTEST, TESTE SQL OU HOMOLOGAÇÃO.
**Autorização:** “ok aprovado” à etapa de revisão documental e levantamento de evidências. Não libera implementação, merge ou produção.
**Referências Git:** código da fast-track em 0aef6838b0452fcbb195fcad2e50aed748ec41c5; PR #24 no SHA 2e3d2051a9491ac02863b9acecff1e88d89c24cc; PR #19 no SHA 75b32cef4afa0e7eae83e3b30fdc13c571a767d4.

## 1. Conclusão inicial

E01 e E02 **estão coerentes como requisitos candidatos**, mas **ainda não produzem segurança operacional**. Os mecanismos que realmente comprovariam o usuário Portal, a origem e atualidade do vínculo, o titular dos dados, a revogação concorrente e o isolamento PostgreSQL não existem neste recorte. A ausência atual de rotas privadas reduz a superfície de exposição; os achados abaixo são **bloqueadores para integração futura**, não afirmações de vulnerabilidade hoje explorável.

## 2. Achados priorizados

| ID | Severidade para uma futura ativação prematura | Evidência inspecionada | Lacuna e ação concreta |
| --- | --- | --- | --- |
| RV01-ID-01 | Bloqueante | app/Domain/Identity/PortalUserId.php aceita e compara IDs textuais; app/Domain/PortalAccess/DemoPortalSession.php contém dados fictícios | Identificar emissor exclusivamente Portal, credencial/fator, estados, revisão e sessão real revogável, com autoridade verificável em backend |
| RV01-ID-02 | Alta | Igualdade de strings não identifica produto/emissor; PORTAL-TEC-D02 veda SSO | Exigir teste de ID textual, e-mail e CNPJ coincidentes Portal/CRM sem concessão de vínculo ou sessão |
| RV01-MEM-01 | Bloqueante | app/Domain/Organizations/OrganizationMembership.php possui construtor público; app/Domain/Authorization/OrganizationAuthorizationService.php apenas avalia dados recebidos | Definir fonte canônica para membership, grants e resource ownership, verificação server-side, estado e versão; nunca hidratar diretamente do request |
| RV01-MEM-02 | Alta | Contratos E02 e PR #19 descrevem revogação, sem mecanismo executável de linearização | Desenhar algoritmo de revisão/lock/transação, retry e política de jobs/cache/réplica, preservando efeito de revogação |
| RV01-GOV-01 | Alta | PR #19 D03/D05 adia fluxo de último gestor, reingresso, papéis e bootstrap | Estabelecer matriz concedente→alvo, primeiro gestor e recuperação com alçadas distintas, sem autoelevação ou staff global |
| RV01-DB-01 | Bloqueante | PR #19 permanece documental/draft; nenhum esquema, role ou RLS homologado | ADR de PG/driver/pool; ERD/FK composta; roles sem BYPASSRLS/DDL; políticas por operação e teste cruzado futuro |
| RV01-QA-01 | Média | CI SEC-P01 cobre apenas domínio sintético e ausência de rotas | Matriz E2E de emissor, reset/MFA, sessão, tenant, concorrência, SQL/pool, logs; evidência de negação E ausência de efeito |

**Criticidade condicional:** os níveis descrevem risco **se** tais recursos fossem liberados sem correção, e não severidade de incidente confirmado atualmente. Não existem indícios de rota que explore as classes sintéticas neste escopo.

## 3. Inspeção do código — o que ele realmente comprova

- **PortalUserId:** objeto de valor com formato validado e igualdade textual, cujo comentário alerta expressamente que não é atestado de origem Portal. Um usuário externo pode apresentar o mesmo texto. Não substituir comprovante de autenticação por um VO.
- **OrganizationMembership:** modelo readonly em memória aceita actor/org, status e permissão fornecidos pelo chamador. Não representa fonte oficial autenticada nem garante unicidade, atualidade, expiração ou revogação.
- **OrganizationAuthorizationService:** nega ator ausente, org divergente, membership não ativa e permissão ausente. É útil como avaliador puro, mas não confirma autenticação, titularidade do recurso no banco, origem de grants nem controle TOCTOU entre decisão e efeito.
- **DemoPortalSession e DemoNavigationPolicy:** o próprio código declara que são apenas testes. A simulação pode permitir preview com entradas arbitrárias; DemoNavigationVerdict.canAuthorizeHttp() é sempre false. Não utilizar classes Demo como middleware ou controle real.
- **routes/web.php:** sem endpoints de login, tenant, Performance AI e checkout; apenas health check padrão é configurado no bootstrap. Portanto, os bloqueadores não descrevem exposição atual demonstrada; nenhum pentest foi feito.
- **PR #19, D03 e D05:** já exigem fontes próprias Portal, membership canônica por par, grants versionados, defesa RLS, controle de último gestor e barreira de revogação. Ainda faltam desenho físico, papel runtime, contrato da função RLS, versão PG/driver/pool e prova negativa em laboratório.

## 4. Contrato mínimo que deve passar por revisão técnica — G-ID01/G-BB03

1. Escolher e documentar **quem emite identidade humana Portal** e em qual store exclusivo; o emissor não pode ser inferido do formato de ID nem de correspondência com o CRM.
2. Definir cadastro/verificação, estados de usuário, versão monotônica de credencial/fator e o mecanismo que revoga sessões após suspensão, logout e mudança de senha/MFA. Conta verificada não significa membro de empresa.
3. Desenhar caminho autenticado: prova de credencial e MFA exigido → sessão Portal opaca validada no servidor → ator/versão vigente → seleção de empresa como intenção → fonte oficial da membership/recurso.
4. Separar login humano Portal de CRM e identidades serviço-a-serviço Portal/Performance. Sem sessão/credencial humana compartilhada.
5. Desenhar bootstrap do primeiro responsável e recuperação do último gestor, sujeito a revisão de Segurança e alçadas; nunca criar bypass universal para equipe de suporte.
6. Atestar no plano de testes futuro que ator CRM de mesmo ID textual, e-mail ou CNPJ não gera sessão Portal/vínculo e que sessão pré-MFA ou revogada não permite ler a lista privada de empresas.

**Estado:** apenas candidato; nenhuma prova de emissor real, MFA, reset, store e revogação foi produzida.

## 5. Contrato mínimo que deve passar por revisão técnica — G-ID05/G-BB04

1. Par canônico ator Portal–organização com estados invited/active/suspended/revoked, revisão, vigência, unicidade, origem explícita de convite/aceite e trilha mínima. Não vincular pela igualdade de e-mail/CNPJ.
2. Papéis e grants locais versionados por empresa, catálogo granular de ação e alçada do concedente; papel owner e staff Imagine não podem significar override sem contrato.
3. Prova de titularidade de cada recurso na fonte privada, com chaves compostas e autorização contextual. Uma organização selecionada por URL/header não é autoridade.
4. Barreira verificável de revogação e ação sensível sob concorrência: definir ponto de linearização e efeitos permitidos antes/depois, como reprocessar idempotentemente, negar cache/replica obsoleta e cancelar jobs/exportações futuras.
5. Tratamento de reingresso, último gestor e restore que não reabre grants antigos, com controle por alçada e trilha.
6. Integrar ao futuro contrato físico PR #19: role runtime mínima, FKs compostas, RLS por SELECT/INSERT/UPDATE/DELETE e contexto transacional seguro. SET LOCAL é transporte, não certificado criptográfico de identidade.

**Estado:** apenas candidato; nenhuma prova persistente de unicidade, isolamento e revogação concorrente foi executada.

## 6. Próximos artefatos de evidência e revisores propostos

| Prioridade | Entrega objetiva | Revisão necessária por papéis | Condição documental verificável |
| --- | --- | --- | --- |
| P1 | ADR de emissor/conta Portal e contrato de sessão/revisões | Identidade + Arquitetura + Segurança | Fonte, confiança, eventos de revogação, bootstrap e testes negativos definidos sem ambiguidade |
| P2 | Catálogo de concessão de membros, últimos gestores e revogação | Produto + Segurança + Dados | Matriz concedente→alvo e transições de estado; algoritmo candidato de concorrência e retries |
| P3 | Mapa de interfaces P1/P2 com PG/RLS de PR #19 | Backend + DBA + Segurança | Campos, ownership, roles, RLS, pooling e dependências apontados sem mesclar PRs |
| P4 | Modelo de ameaça MFA/reset/sessões, E2E e privacidade | Segurança + QA + Privacidade | Casos de replay, fixation, CSRF, recuperação, abuso e restore rastreáveis a evidência futura |
| P5 | Pareceres independentes e avaliação de eventual laboratório | Segurança/DBA e Direção | Revisores identificados, riscos residuais e escopo/custos/rollback decididos em autorização **separada** |

As funções listadas não são pessoas nomeadas e **não forneceram pareceres** nesta análise; a presente revisão assistida não substitui opinião técnica independente.

## 7. Restrições e conclusão

- **Todos abertos:** D02-SEC01–06; D03 físico/RLS; G-ID01–09; G-BB01–08; P0 e demais gates de privacidade, qualidade e produção.
- Parâmetros propostos de 30min inatividade, 12h sessão, 5min step-up e 15min reset **não homologados**.
- Sem SQL, migrations, autenticação real, MFA, API, cookies, laboratório, dados de cliente, integração CRM/Performance, alteração main, merge de PR #19/#24 ou deploy.
- Não há ensaios PostgreSQL, testes HTTP autenticados, análise dinâmica ou pentest. Os achados são oriundos somente de fontes e arquivos efetivamente inspecionados.

**Recomendação técnica:** não iniciar login real enquanto RV01-ID-01, RV01-MEM-01 e RV01-DB-01 estiverem abertos. A próxima atividade concreta é submeter o ADR de emissor e o contrato de membership/revogação a revisão técnica efetiva, não criar novas aprovações documentais genéricas.
