# PORTAL-F1-02-A — Contrato do emissor Portal, sessões e fronteiras de autoridade

**Data:** 08/11/2026. **Issue:** [#45](https://github.com/rafhael100/imagine-portal/issues/45). **Estado:** candidato documental de especificação. **NÃO** implementado, testado em PostgreSQL ou homologado.
**Base de código:** \`dev/fast-track-portal-2026-11-05\` SHA \`174eeb71b7b32c89be9ac30ee95a1bbc2124a7f3\` (PR #41 integrado). A \`main\` fica intocada. PRs de segurança #19/#24/#33 continuam históricos em análise, sem merge.
**Decisões precedentes:** SEC-P02-R02-DEC-01 (D01-B revogação hierárquica, D02-A reforçada RLS), R04-DEC-02 (alternativas A ratificadas apenas documentalmente), SEC-P02-R05-IMPL/QA/GATE. Novas decisões de produto não são aprovadas por este arquivo.

## 1. Resultado desejado e limite real

Provar no futuro que o **Portal Imagine** emite e valida sua **própria identidade humana e suas sessões**. Nenhum identificador igual, e-mail, domínio, CNPJ, token/cookie de CRM ou Performance ou atributo informado pelo browser autentica um ator Portal. A camada F1 de domínio já integrada contém \`PortalSessionAuthority\`, \`PortalMembershipAuthority\`, \`PortalResourceAuthority\` e \`PortalAuthorityBoundary\`, mas suas instâncias de teste são livremente construíveis. O método \`PortalBoundaryVerdict::canAuthorizeHttp()\` **permanece sempre false**; nenhuma rota real poderá usar a prévia sintética como permissão.

**Não autorizar login, cookies, MFA, sessão real ou consulta privada nesta etapa.** A proposta física é base para laboratório isolado futuro, não sua execução.

## 2. Fontes autoritativas e direção de dependência

| Autoridade | Responsabilidade exclusiva futura | Fontes não aceitas |
| --- | --- | --- |
| PortalIdentityIssuer | Criar \`portal_actor_id\` opaco, validar credencial/fator, controlar estados e revisões de identidade | CRM, Performance, username, e-mail ou CNPJ fornecido como prova de principal |
| PortalSessionIssuer/Verifier | Criar verificador de handle imprevisível, validar sessão, fator, época, propósito, expiração, rotação e revogação | JWT arbitrário, DemoPortalSession, PortalSessionSnapshot ou cookie de produto terceiro |
| OrganizationMembershipAuthority | Resolver vínculo oficial de ator×organização, alçada, grants, vigência e status | DTO, header, hidden field, invite antigo, perfil de pagador |
| ResourceOwnerAuthority | Resolver owner de recurso e entitlement pelo registro no servidor | Owner escolhido no payload, match do ID por acaso |
| AuthorizationTransactionCoordinator | Sincronizar decisão com efeitos e revogações, em hierarquia P→A→S→O→M→R aprovada documentalmente | Snapshot desacoplado, cache positivo, lock apenas de membership, isolamento simulado |
| SQL runtime / RLS | Aplicar privilégios mínimos e tenant isolado como defesa em profundidade | Assumir que \`SET LOCAL\` autentica ator, ou que RLS resiste a SQL arbitrário forjando contexto |

Nenhuma fonte acima está disponível como autenticador operacional neste recorte. Um nome de interface não é fronteira de segurança: deve-se verificar quem a instancia, quem a autoriza e o banco/role de que realmente lê.

## 3. Fluxos de identidade (contratos sem código nem política numérica ratificada)

### F1-02-A01 — Cadastro / comprovação de canal
1. Registrar intenção de cadastro e gerar ID Portal sob autoridade servidora, em estado de verificação pendente.
2. Criar desafio de verificação com propósito, sujeito, limite de tentativas, validade e consumo único, sem emitir sessão privada.
3. Consumir o desafio na autoridade e registrar o estado de canal. **Canal validado não comprova mandato de organização**, não transforma pagador no último gestor e não concede acesso privado.
4. Se cadastro duplicado, timeout, desafio vencido, consumo concorrente ou falha de auditoria, negar sem enumerar contas.

**Pendências:** canal inicial autorizado, proteções antiabuso, privacidade, retenção e recuperação de canal.

### F1-02-A02 — Verificação de credencial, MFA e emissão
1. Resolver conta Portal por autoridade própria e validar canal, status, credencial atual, fatores exigidos e revisões.
2. Estados \`primary_verified\` e \`challenge_pending\` permitem apenas continuar autenticação, nunca consultar tenant ou permitir ação privada.
3. Após desafio MFA vigente vinculado a conta+sessão de desafio+propósito, revalidar tudo na unidade de emissão.
4. Gerar novo segredo de sessão de entropia e formato **a definir por AppSec**; armazenar somente verificador, estado, revisão, epoch, limites de validade e vínculo Portal, sem gravar handle bruto em log.
5. Consumir desafio e criar sessão em transação/autorização coordenada; se falhar qualquer dependência, negar sem criar sessão. Uma resposta perdida não autoriza reemitir a mesma sessão silenciosamente.
6. Entregar cookie host-only Secure/HttpOnly com SameSite e CSRF a definir — **somente etapa operacional futura**, sem escolher configuração por silêncio.

**Pendências:** credencial inicial, fator, step-up, política de expiração e recuperação, tempo de sessão, locking/transaction atomicidade e gestão de segredo.

### F1-02-A03 — Validação para efeito protegido
1. Browser envia referência de sessão e intenção de organização/recurso; nunca um objeto de ator/grants como prova.
2. Servidor resolve verificador do handle em fonte Portal, estado/tempo/epoch e fator/revision atuais, em unidade sincronizada.
3. Resolve membership e ownership por autoridade oficial, sob barreiras do domínio relevante; relê decisão depois de bloqueios.
4. Aplica capability granular, restrição tenant e RLS, grava efeito, idempotência e auditoria obrigatória em transação coordenada.
5. Dúvida, erro, timeout, conta suspensa, sessão expirada, política alterada ou contexto ausente levam a negação sem efeito.

A ordem final de locks PostgreSQL e fronteira de fonte de sessão devem ser comprovadas pelo DBA. **A aprovação documental de hierarquia P→A→S→O→M→R não é prova de linearização.**

### F1-02-A04 — Logout, suspensão, troca de credencial e recuperação
- Logout individual invalida sessão e previne reuso, inclusive token de pre-auth; logout global ou suspensão atualiza epoch do ator, com todos os tenants dependentes sincronizados.
- Rotação não autoriza simultaneamente predecessor e sucessor; falha de resposta e retry exigem idempotência e estado seguro.
- Troca de fator/senha e ação de segurança não são bloqueadas pelo fato de conta ser o último gestor.
- Se faltar gestor, organização entra em recuperação restrita, sem staff global nem permissão automática por CNPJ/e-mail/pagamento.
- Restore de backup antigo não pode ressuscitar credenciais, epoch ou concessão sem reconciliação ou invalidar toda sessão em serving fechado.

**Pendências:** alçadas de recuperação, identidade jurídica, evidência de mandato, SLA, processo de suporte e reingresso.

## 4. Modelo de estados candidato e invariantes

| Estado lógico | Pode emitir sessão privada? | Condição de transição / retorno |
| --- | --- | --- |
| \`registered\` / \`pending_verification\` | NÃO | Verificar canal sob desafio válido |
| \`eligible\` | NÃO diretamente | Validar credencial primária/fator exigido |
| \`challenge_pending\` | NÃO | MFA/step-up por propósito com one-time consume |
| \`authenticated\` | Só sessão já emitida e persistida pelo issuer | Revalidar conta, sessão, epoch, prazo, MFA e grants a cada efeito |
| \`step_up_required\` | NÃO para ação sensível até novo fator | Sessão pode existir mas capacidade restrita |
| \`revoked\` / \`expired\` / \`suspended\` | NÃO | Recovery formal novo; não restaurar sessão antiga |

Estes são nomes **lógicos**, não enum SQL ou Laravel validado. Estados separados de conta e sessão serão definidos em ERD para não permitir estados impossíveis.

## 5. Modelo lógico persistente candidato para laboratório futuro

| Tabela/entidade | Campos/constraints a detalhar | Prova esperada |
| --- | --- | --- |
| \`portal_account\` | actor_id opaco gerado internamente; estados e revisões de identity/credential/mfa/session_epoch | Nenhum principal externo vira ator Portal |
| \`portal_credential\` | referência de segredo/verificador, política de atualização, rotação e auditoria | Restrições de uso/armazenamento e incapacidade de recuperar segredo em log |
| \`portal_auth_challenge\` | propósito, sujeito, emissão, validade, consumo único, tentativa e revisão | Replay ou corrida só consomem uma vez |
| \`portal_session\` | verificador (não handle), subject, state, snapshot de revisions/epoch, criado/expira, política de ociosidade | Suspensão, revogação e expiração negam sem cache positivo |
| \`organization_membership\` | UNIQUE lógico (org, actor), estados e encarnação, policies/grants oficiais | Nenhuma role CRM ou DTO cria vínculo |
| \`tenant_resource\` | tenant owner e FKs compostas; resource owner imutável | Negar cross-tenant mesmo com IDs adivinhados |
| \`audit_event\` | ator, contexto, comando/resultado, revisão, timestamps e correlação sanitizados | Registrar prova de revogação e decisões sem PII/secret |

**Sem DDL, GRANT, RLS, migrations ou banco criado.** Validar roles mínimas para web/job/migrador/owner e os oráculos de FK/unique; não presumir versão de extensão ou pool sem comprovação real.

## 6. Matriz dos primeiros testes futuros (TODOS NÃO EXECUTADOS)

| Caso | Cenário | Critério de evidência |
| --- | --- | --- |
| F1-02-T01 | Cookie/ID/credencial CRM com mesmo texto de ator Portal | Nenhuma sessão Portal emitida |
| F1-02-T02 | Pre-MFA, challenge vencido, repetido ou de outro propósito | Nenhum acesso privado, desafio não reciclável |
| F1-02-T03 | Duas emissões concorrem com suspensão/alteração de fator | Nenhuma sessão com revisão antiga ganha permissão após corte |
| F1-02-T04 | Revogar sessão enquanto duas ações de empresa A e B esperam | Ações após revogação negadas sem lock exclusivo global para todos os tenants |
| F1-02-T05 | Grants/owner falsos em request, cross-tenant FK | Zero leitura/escrita e sem enumeração |
| F1-02-T06 | Savepoint/rollback, falha de store, timeout, retry e pool futuro | Falha fechada e sem efeito duplicado/tenant residual |
| F1-02-T07 | SQL com filtro omitido vs SQL arbitrário que forja contexto | Provar o que RLS contém e **não** falsificar PASS para ameaça sem mitigação |
| F1-02-T08 | Backup anterior a logout/suspensão | Serving privado só reabre após invalidar/reconciliar autoridade |
| F1-02-T09 | Suspensão por segurança do último gestor | Conta suspensa; org em recovery restrito; sem autoelevação |

Relatórios de prova futura: SHA, versão PG/PHP/driver, roles, fixtures inteiramente fictícias, cronologia e commits, logs sem segredo, comparação antes/depois e tratamento honesto de FAIL/BLOCKED. \`HTTP 403\` sozinho não prova ausência de efeito.

## 7. Recortes de autorização e próximos gates

**Autorizado nesta interação:** apenas documentação F1-02-A, criação de branch e PR documental em draft, parecer consultivo futuro para decidir o próximo passo.

**Não autorizado:** ativar emissor real, cookie, sessão persistida, login/MFA, SQL/DDL/migrations, PostgreSQL em ambiente operacional, laboratório, consulta de dados reais, VPS, integração CRM/Performance, despesas, merge, alteração de main/dev ou deploy.

**Primeira futura etapa de código a submeter à Direção:** laboratório **F1-02-B**, sem HTTP de negócio, com PostgreSQL 18 + PDO_PGSQL em ambiente descartável, migrations somente de teste, fixtures A/B sintéticas, jobs com roles SQL mínimas, testes T01..T09, PR draft isolado da dev e plano de rollback. **Não iniciar sem autorização separada, identificação de ambiente/custo e revisão do risco de runtime hostil.**

**P0 R01-01/02/03/09/10, G-ID01..09, G-BB01..08, D02-SEC, D03/RLS e gates de release continuam ABERTOS.** Direção de Produto decide aprovações gerenciais; apenas documentação ou testes sintéticos não são homologação.
