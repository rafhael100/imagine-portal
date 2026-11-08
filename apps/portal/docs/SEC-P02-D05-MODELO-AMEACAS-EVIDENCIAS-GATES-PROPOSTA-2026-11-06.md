# SEC-P02-D05 — Modelo de ameaças, contratos de falha fechada e plano de evidências de autenticação

**Data:** 06/11/2026
**Status:** PROPOSTA DOCUMENTAL PARA AVALIAÇÃO — NÃO HOMOLOGADA.
**Precedência:** SEC-P02-DEC-01 e DEC-02 ratificam SEC02-01..09, IDN-D01..05 e REC-D01..05 **apenas como diretrizes preliminares**; F1-01-B-B/PR #19, G-BB01–08, D02-SEC01..06 e D03/RLS permanecem abertos.

## 1. Objetivo, fronteiras e modelo de atacante

O futuro cliente deverá usar login próprio do Portal para visualizar seus serviços e o painel Performance AI; a autoridade de sessão não pode se propagar automaticamente ao CRM, Google/Meta ou ao motor Performance. O projeto atual só contém classes demo, não há autenticação nem tenant de produção. Este documento propõe como transformar diretrizes em **provas antes de qualquer rota real**, sem executar testes novos ou implementar software.

**Fronteiras de confiança:**
1. Navegador, cookies e identificadores em URL são **entradas não confiáveis**. Headers de tenant, membership, permissão e confirmação de MFA enviados pelo cliente jamais são autoritativos.
2. Aplicação Portal, autenticador e repositório oficial de identidade/sessões devem ter emissor verificável, versão de revogação e competências segregadas. RLS é defesa adicional, não prova de origem do ator.
3. Banco/role runtime sem superuser, BYPASSRLS e DDL; não aceitar que SET LOCAL ou contexto SQL ateste identidade. Risco de injeção e alteração de contexto permanece mesmo com RLS.
4. Motor Performance e CRM possuem autoridades e contas distintas; integração técnica precisa de identidade de serviço, escopo, assinatura ou autenticação mútua conforme contrato e revogação. Nenhuma sessão humana compartilhada.
5. Operadores internos, staff, suporte, robôs e jobs não recebem autorização universal por função, acesso técnico ou leitura de logs.

**Classes de ameaça em avaliação:** roubo/replay de sessão, fixation, phishing, CSRF, XSS, força bruta e enumeração, bypass de MFA/recuperação, troca de organização, concessão de poderes por ID/cargo, vazamento de conexão/role RLS, corrida após revogação, cache/replay em filas, restauração obsoleta, operadores privilegiados, indisponibilidade do armazenamento de sessão e abusos de recuperação.

## 2. Contratos propostos de estado e falha

| Componente | Fonte oficial futura | Resposta em falha | Evidência necessária |
| --- | --- | --- | --- |
| Identidade humana Portal | Autenticador Portal, versão de conta e estado atual | Negar login ou sessão quando origem/revisão inválidas | Teste de emissor Portal×CRM e conta suspensa |
| Sessão | Store opaco no servidor, revisão, TTL e fator vinculado | Negar, revogar em falha de integridade, não reutilizar pre-auth | Replay/fixation/rotação e abas/dispositivos |
| MFA | Verificador do desafio e fator cadastrado com prova de posse | Não concluir login nem step-up se ausente/obsoleto | Replay, relógio, tentativa e recuperação |
| Membership e grants | Vínculo canônico e poderes atuais por organização | Negar ao não resolver vínculo/escopo e se revogado | Concessão/negação concorrente e troca A↔B |
| Recurso e serviço | Fonte de ownership por organização e contrato vigente | Não retornar dados de outra organização nem executar operação | E2E recurso cruzado, ID adulterado e cancelamento |
| Banco PostgreSQL | Role runtime limitada, FK composta, RLS, transação contextual | Rollback, sem bypass nem vazamento de tenant | Ensaios H/C/P no PR #19 e revisão DBA |
| Token de reset | Repositório de verificadores únicos, propósito, expiração e consumo atômico | Negar token duplicado, vencido, sem prova, com store indisponível | Corrida no consumo, enumeração e retenção |
| Sessão serviço-a-serviço | Contrato INTEG-01/arquitetura de fronteiras e segredos em cofre | Recusar ação sem identidade/escopo vigente | Replay, revogação, idempotência e contrato de integração |

## 3. Transições de alto risco a homologar

**Login:** pedido (não autenticado) → prova da credencial → desafio de fator exigido → sessão autenticada e rotacionada → contexto de organização revalidado por requisição. Se qualquer fase falhar, não permitir painel, listar empresa ou conceder permissões.

**Troca de empresa:** seleção é intenção, não evidência; somente aceitar depois de verificação de membership ativa e recurso da mesma organização. Invalidar autorização contextual e impedir reutilizar grants/cache de outra empresa. Estabelecer revisão de sessão e política de rotação em mudança sensível.

**Reset de senha:** token de uso único com verificador; consumo atômico e transição de revisão de credenciais; invalidar sessões derivadas conforme contrato; **nunca** liberar segundo fator automaticamente. Falha de notificação não permite supor reset concluído.

**Perda de MFA:** recuperação de alta garantia com alçadas aprovadas e evidências limitadas; equipe de suporte não pode desabilitar fator por coincidência de e-mail/CNPJ, acesso técnico ou alegação verbal. A política de reativação de último gestor deve ter contingência separada da elevação comum.

**Revogação:** rebaixamento de role, suspensão de ator, cancelamento de vínculo ou empresa precisa bloquear autorização a partir do ponto de linearização definido, inclusive com duas abas, sessões de outros dispositivos, jobs atrasados e banco em transação. Snapshot ou cache stale de allow não substitui fonte de autoridade.

## 4. Evidências pendentes por gate

| Gate | Artefato a produzir antes de solicitar laboratório | Prova negativa requerida |
| --- | --- | --- |
| G-ID01 Emissor | Contrato de identidade e estados de conta, registros de revisão e trust boundary | Mesmo ID/e-mail de CRM não gera login Portal |
| G-ID02 MFA | Política de enrolment, fator inicial, step-up, anti-replay e recuperação | Sem fator não há sessão privada nem ação sensível |
| G-ID03 Sessão | Store/cookies/TTL/rotação e desenho de revogação | Cookie antigo e sessão revogada não abrem recursos |
| G-ID04 Recuperação | Verificador e atomicidade de reset, auditoria e anti-enumeração | Replay ou corrida do mesmo token não redefine senha |
| G-ID05 Organização | Membership/ownership/role e matriz de ações/grants | Empresa B inacessível com papel na empresa A |
| G-ID06 Banco | Integração de D03 físico/RLS com G-BB01..08 do PR #19 | Isolamento de leitura e escrita sob role runtime real |
| G-ID07 Integração | Limites Portal×CRM×Performance, identidades de serviço e revogação | Sessão Portal não autentica outro produto nem ad account |
| G-ID08 Operação | LGPD, retenção, alerta, logs mínimos, incidentes e restore | Restore não revive grant/credencial revogada |
| G-ID09 Qualidade | Suite E2E/segurança com rastreabilidade AUT/IDN/REC e parecer independente | Testes adversariais executados em ambiente isolado |

**Situação:** TODOS os G-ID01 a G-ID09 são **GATES CANDIDATOS ABERTOS**. A existência deste documento não implica sua aprovação ou fechamento. São complementares, não substitutos, de G-BB01..08 e P0.

## 5. Decisões novas a submeter — não ratificadas

| ID | Alternativa A recomendada | Alternativa B / observações |
| --- | --- | --- |
| SEC05-D01 | Formalizar G-ID01..09 como checklist de entrada para laboratório e liberar um gate por evidência verificável | Avaliar menos gates, com risco de encobrir dependências |
| SEC05-D02 | Fonte de identidade Portal exclusivamente servidora e verificação por revisão vigente antes de cada operação privada | Cache de identidade por janela curta apenas com invalidação garantida demonstrada |
| SEC05-D03 | Sessão opaca/rotacionada com revogação durável; MFA separado de password reset e recuperações de alta garantia | Token autocontido exigiria prova de revogação e mitigação adicional |
| SEC05-D04 | Mapeamento requisito→ameaça→teste adversarial→prova→parecer antes de considerar gate fechado | Revisão parcial por módulo com riscos de integração |
| SEC05-D05 | Alinhar G-ID05/06 com D05 do PR #19, mantendo RLS, tenant/pool e revogação como gates distintos | Homologação conjunta posterior sem eliminar os gates originais |
| SEC05-D06 | Primeiro laboratório, se autorizado separadamente, apenas sintético e descartável; nada de painel público | Ambiente persistente exigiria governança e custos adicionais |

As alternativas acima são **novas propostas**. A aprovação anterior de IDN-D01..05 e REC-D01..05 não as ratifica.

## 6. Conjunto de ensaios futuros indispensáveis (NÃO EXECUTADOS)

1. Identidade textual igual entre Portal e CRM — nenhum vínculo/SSO por coincidência.
2. Sessão pré-MFA, logout, rotação e replay de cookie — zero acesso a páginas privadas.
3. Token de recuperação inválido, expirado, repetido e consumido em corrida — no máximo um consumo atômico.
4. Perda de MFA, recuperação via suporte e troca de e-mail — sem bypass ou enumeração.
5. Empresa X versus Y, URL/header adulterados, grants desatualizados e role manipulada — zero leitura/escrita indevida.
6. Rebaixamento/revogação concorrente, réplica, restore, jobs e pool SQL — sem allow residual nem reativação.
7. Sessão Portal usada contra CRM, Performance ou Google/Meta — negada por separação de autoridade.
8. Falha em banco, provedor de e-mail, store de sessão, relógio e sistema externo — negar sem fallback inseguro.

A suíte PHPUnit SEC-P01 testou apenas DTOs sintéticos e ausência de rotas. **Não há prova operacional desses cenários neste momento.**

## 7. Regras de controle de mudanças

- Apenas documentos Markdown nesta rodada; PR #24 deve permanecer rascunho sem merge.
- Sem SQL/migrations, conexões a PostgreSQL, tokens, sessões, usuários reais, login, middleware, API, UI de cliente, OAuth, integrações, PSP, secrets, Cloudflare, VPS, main ou deploy.
- Cada futura implementação exige ordem própria com branch e SHA, escopo autorizado, fonte de identidade, ambiente de laboratório, dados fictícios, critérios de parada e revisão de Segurança/DBA. CI positivo sozinho não homologa segurança.
- Prioridade de produto: o painel Performance AI no Portal, mas **nenhuma rota deve ser exposta** antes de autenticação, tenancy e auditoria homologadas.

**Próxima deliberação:** avaliar SEC05-D01 a SEC05-D06 como diretrizes documentais candidatas. A Direção não precisa atribuir a si mesma os pareceres técnicos de Segurança/DBA: indicar revisores e evidências independentes no fechamento.
