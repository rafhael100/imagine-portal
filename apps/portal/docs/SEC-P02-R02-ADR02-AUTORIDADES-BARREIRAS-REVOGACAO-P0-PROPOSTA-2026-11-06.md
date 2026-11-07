# SEC-P02-R02 — ADR02: autoridades e barreira candidata de revogação

**Data:** 06/11/2026 (America/Sao_Paulo). **Atividade:** [CODEX-SEC-P02-R02 / Issue #30](https://github.com/rafhael100/imagine-portal/issues/30).
**Estado:** CORREÇÕES DOCUMENTAIS CANDIDATAS — GATES ABERTOS — AGUARDANDO PARECER HUMANO.

**Proveniência:** complemento novo, sem substituir nem editar os documentos históricos. Base documental do PR #24: `e76a9089727d8ccefd492cd660014ce3b22dc5d8`; PR #19: `75b32cef4afa0e7eae83e3b30fdc13c571a767d4`. Achados: [R01, comentário 6690120467](https://github.com/rafhael100/imagine-portal/issues/27#issuecomment-6690120467). Referências completas e matriz de provas no [documento de rastreabilidade](SEC-P02-R02-MATRIZ-ACHADOS-NEGATIVOS-PARECERES-PROPOSTA-2026-11-06.md).

**Limites:** somente Markdown. Nenhum código funcional, SQL executável, DDL, migration, conexão a banco, login/MFA real, laboratório, teste operacional, integração, dados reais, custo, merge, main/dev, alteração dos PRs #19/#24 ou VPS. Este texto não ratifica arquitetura, não resolve P0 por si só e não homologa gate. Termos de lock, chave e policy são requisitos em prosa, não implementação.


## 1. Premissas e escolha candidata, não ratificada

Trata R01-02 e R01-03. Complementa o [ADR01 R02](SEC-P02-R02-ADR01-CONTRATO-IDENTIDADE-SESSAO-P0-PROPOSTA-2026-11-06.md) e depende da integridade/privilégios do [ADR03 R02](SEC-P02-R02-ADR03-TENANCY-RLS-LIMITES-AMEACA-P0-PROPOSTA-2026-11-06.md). A membership isolada não serializa mudanças na conta, sessão, role, catálogo, empresa ou entitlement.

**Preferência A para avaliação:** uma barreira canônica global Portal G, representada logicamente por registro persistente único, preexistente e não removível pelo runtime. Todas as transações de autorização com efeito interno, materialização de leitura privada e todos os escritores de autoridade adquirem **lock exclusivo transacional na mesma G**, mantido até commit/rollback. Não há lock em memória, TTL de cache, lease de worker ou advisory key fornecida pelo cliente. Ausência/duplicidade de G é erro de autoridade e nega. G não tem tenant padrão nem dá acesso aos dados.

Escolha deliberadamente conservadora: serializa globalmente e pode ser inviável em throughput/latência. Não é recomendação de produção sem avaliação. A vantagem documental é um ponto comum verificável sem fan-out de invalidações e sem afirmar que incrementar contador elimina TOCTOU. A autoridade de identidade/sessão/política e os efeitos deste recorte ficam no mesmo primário Portal. G não coordena CRM ou motor externo.

**Alternativa B, pendente:** barreiras hierárquicas compartilhadas/exclusivas por catálogo, actor/sessão, organização/política, membership e recurso. Pode permitir concorrência, mas exige grafo de dependências e ordem total para mudanças globais, concedente/alvo, suspensão de conta multiempresa e último gestor. Não misturar B parcialmente com A, nem manter ação que só bloqueia membership.

**Alternativa C, pendente:** epochs/fencing ou autoridade externa. Comparar epoch depois de ler e antes de gravar não basta sem vincular atomicamente ao efeito. Fencing tem de ser validado pelo receptor autoritativo no ato de aceitar o efeito; TTL/evento eventual não é fencing. A adoção de outra topologia exige novo parecer; este ADR não declara transação distribuída.

**DEC-R02-B pendente:** Arquitetura/DBA/Produto decidem se a perda de paralelismo e a co-localização lógica são aceitáveis para um candidato inicial. Sem essa decisão, a preferência é apenas proposta e P0 permanece aberto.

## 2. Inventário fechado de escritores e revisões

Todos os caminhos abaixo, inclusive CLI, job, suporte e correção administrativa, devem estar no inventário de privilégios. Escritor novo fora desta lista impede promoção até análise de dependências. Um processo de manutenção com bypass pode quebrar a garantia; nunca tratá-lo como exceção silenciosa.

| Autoridade / escritor candidato | Mudança de estado ou operação | Revisão/epoch e sincronização exigidos |
|---|---|---|
| Registrador/verificador/gestor de conta | Criação, canal, elegibilidade, suspensão, desativação, anonimização e reativação | identity_revision; mudanças de segurança incrementam session_epoch; G + auditoria. Nenhum vínculo implícito |
| Autenticador/emissor de sessão | Primary/MFA challenge, consumo, emissão, rotação, step-up, último uso, logout individual/global | Desafio e session_revision; snapshots de identidade/credencial/MFA/session_epoch relidos sob G; emissão e consumo atômicos |
| Gestor de credencial/MFA/recuperação | Senha/reset, fator, recovery code, canal sensível, invalidação de desafios/tokens | credential_revision/mfa_revision/identity_revision pertinentes e session_epoch; consumo/verificador/revogação/trilha no mesmo commit sob G |
| Autoridade de organização | Criar, suspender, arquivar, reativar empresa; política de recuperação | organization_revision e tenant_policy_revision; G; nenhuma exceção de suporte tácita |
| Gestor de membership/convite/atribuição | Convite, aceite, suspensão, revogação, reingresso, vigência, concessão/rebaixamento de role | membership_revision, incarnation e tenant_policy_revision; par único; G; concedente e alvo atuais |
| Editor de role local / role_assignment / grant | Definição, ativação/desativação, vínculo de capability, validade, alteração de composição | role_revision/assignment_revision e tenant_policy_revision; G, sem exigir editar todas as memberships |
| Mantenedor de catálogo global / política global | Capability reconhecida/ativa, semântica, regra global de ação/MFA/delegação | catalog_epoch/global_policy_epoch em transação sob G; invalida dependências de todas as empresas sem fan-out incompleto |
| Autoridade contratual de entitlement Portal | Serviço/contrato habilitado, suspenso, cancelado, vigência e escopo | entitlement_revision e tenant_policy_revision; G; pagamento externo não atualiza sem evento validado/idempotente |
| Proprietário de recursos / workflow de domínio | Criar/alterar/arquivar recurso, versão e referências privadas | resource_revision e tenant imutável; G; transferência entre empresas desabilitada neste recorte |
| Executor de ação interna / job / exportador | Efeito protegido, materialização de lote, persistência de intenção outbox | Reavalia vetor completo sob G; idempotência e auditoria atômicas; nunca usa allow de job/cache |
| Dispatcher / reconciliador | Claim, cancelamento, resultado e dedupe de intenção interna | Estado/revisão de intent sob G; envio remoto não pertence à transação nem à garantia de corte interno |
| Manutenção / migrador / restore / rotina de expiração | Evolução de política, limpeza, replay e recuperação de backup | Não escrever enquanto tráfego estiver aberto sem G; restore/DDL/epoch são procedimento operacional separado com serving bloqueado até reconciliação |

A hora passando pode expirar vigência sem escritor: nenhum contador atualizado por cron substitui checagem temporal. Alterar catálogo global pode invalidar capacidades sem alterar uma membership; cada decisão relê catálogo/política global e local atuais.

Vetor lógico da decisão: encarnação de recuperação do núcleo, epochs globais, revisions de conta/credencial/MFA/session_epoch, sessão, organização, política tenant, membership/encarnação, atribuições/roles/grants, entitlement e recurso. Só componentes que de fato condicionam a ação pertencem ao vetor, mas a enumeração de dependências deve ser explícita por ação. Revisões são evidência e detecção de staleness; **G, e não o número sozinho, impede mudança concorrente entre validação e commit**. Não gravar segredos junto ao vetor.

## 3. Ordem de locks, isolamento e operação candidata

Premissas verificáveis: G existe em primário; nenhum writer autoritativo usa réplica/cache como verdade; a role runtime não pode alterar G/autoridades diretamente fora do caminho aprovado (ADR03). Erro ou falta de conexão aborta antes de efeito.

1. Abrir transação explícita no primário e adquirir G exclusiva antes de qualquer lock de domínio, consulta privada ou decisão positiva. Lookups públicos restritos/parse do handle anteriores não autorizam. Leituras e objetos coletados antes da aquisição são descartados.
2. **Isolamento candidato READ COMMITTED**, não ratificado: após adquirir G, executar novas leituras autoritativas em instruções posteriores à aquisição. Não reutilizar snapshot pré-lock, cursor, plano que carregou grants antes da espera, identity map do ORM ou allow em cache. REPEATABLE READ/SERIALIZABLE exigiriam redesenho/validação dos snapshots e retries; não são substitutos automáticos.
3. Estabelecer contexto tenant/ator da transação na mesma conexão conforme ADR03. Consultas de validação global/sessão são limitadas às autoridades, não bypass geral de dados empresariais. Verificar conta/sessão/MFA, organização, membership, alçada, grants/catálogo, entitlement e ownership.
4. Locks adicionais de domínio, se necessários, vêm sempre **depois de G**; ordem lógica determinística por classe e ID: autoridades de conta, sessões/desafios, organizações, memberships, roles/atribuições/grants, recursos, intents/idempotência, auditoria. Para múltiplos IDs, ordem binária estável aprovada pelo DBA, nunca ordem de input. Nenhum job pré-bloqueia intent e depois espera G. Incluir locks implícitos de FK/trigger/índice e paths de manutenção na análise.
5. Revalidar vigência temporal e frescor exigido imediatamente no ponto de instrução que produz efeito/materializa lote, com relógio autoritativo atual após as esperas. Não usar tempo do navegador nem assumir que o instante de início da transação avança. Registrar referência temporal mínima, sem fixar valores de TTL.
6. Persistir efeito interno + controle de idempotência + auditoria obrigatória + intenção outbox quando pertinente, na mesma transação. Não acessar rede externa segurando G. Commit libera G; aborto desfaz todos esses registros. Notificação assíncrona não é auditoria autoritativa substituta.
7. Timeout, deadlock, cancelamento, falha de leitura ou auditoria: rollback integral. Somente depois de confirmar término da transação, nova tentativa limitada, com backoff definido futuramente, nova identidade/sessão/tenant e vetor atual. Não reusar allow, snapshot ou contexto do retry anterior.

G reduz concorrência entre participantes, mas não elimina deadlock com caminho externo ao protocolo, gatilhos, manutenção ou locks tomados antes de G. Qualquer descoberta desses caminhos é bloqueador. Máximo de espera/transação, orçamento de retry e capacidade são pendências de DBA/Plataforma, sem números homologados. Pool por statement incompatível; transação precisa afinidade de conexão, inclusive após timeout e savepoint.

**Tempo e commit:** a elegibilidade temporal é medida na instrução protegida do efeito, não há promessa de commit antes do relógio de expiração em caso de pausa posterior. Se Produto exigir corte exato em wall-clock de commit/entrega, esta preferência não prova isso e o fluxo fica bloqueado até contrato adicional. Ponto de revogação por evento é o commit sob G, distinto da expiração natural.

## 4. Revogação, emissão e interleavings

Revogação reconhecida ao usuário somente após commit da autoridade sob G. “Pedido recebido” ou evento em fila não significa revogado. Timeout de resposta após commit requer consulta idempotente; não anunciar sucesso/fracasso sem evidência.

| Caso analítico | Sequência candidata | Garantia condicional / contraexemplo rejeitado |
|---|---|---|
| Ação antes da revogação | T1 obtém G, relê r7, grava/audita e commita; T2 só então adquire G e revoga r8 | Efeito T1 é anterior ao commit de revogação; não é violação |
| Revogação antes da ação | T2 obtém G e commita r8; T1 adquire G, relê estado atual, nega e encerra | Zero mutação privada/outbox autorizada por r7 |
| Suspensão/reset/fator/logout global | T1 não pode manter G enquanto T2 altera conta, sessão ou epoch | Fecha a janela check→membership-lock de R01-02 somente se ambas autoridades estão no núcleo G |
| Login em andamento durante reset | Credencial foi verificada em r7; reset sob G produz r8; emissor só depois obtém G | Emissão precisa revalidar r8 contra prova r7 e negar; não emite sessão antiga depois do reset |
| Role compartilhada por M1/M2 | Mudança de grant sob G incrementa tenant_policy_revision; qualquer ação de M1 ou M2 espera G e relê role/catálogo | Não precisa fan-out para todas M; writer fora de G quebra garantia |
| Empresa/entitlement suspenso | Estado muda sob G; nova decisão relê org/serviço e nega | Nenhuma permissão residual em outra role, sessão ou empresa |
| Capability global retirada | Mantenedor toma G, muda catálogo/epoch e commita | Todas as decisões seguintes relêem catálogo. Deployment que muda semântica em código é mudança de política e precisa compatibilidade/barreira próprias |
| Duas concessões/revogações | G serializa operações e segunda relê concedente, alvo, política e unicidade | Não usar resultado da primeira leitura fora da barreira; autoelevação continua proibida |
| Store separado com revogação própria | Fonte externa muda r8 enquanto núcleo ainda vê r7 | **Não coberto pela preferência A.** Negar migração desse fluxo sem fencing e confirmação definidos |
| Contexto SQL forjado/runtime hostil | Atacante consegue escrever autoridade ou ignorar G | **Fora da garantia do protocolo cooperativo**; ADR03 define limite e privilégios, não alegar invulnerabilidade |

Casos coordenados futuros devem demonstrar ordem de aquisição/liberação/commit e revisões com dados fictícios, ausência de efeito/vazamento e auditoria sanitizada. Nenhuma sequência foi executada.

## 5. Membership, concedente e último gestor: dependência P1 expressa

G impede write skew entre escritores participantes, mas não define qual capacidade constitui gestor recuperável, quem pode convidar ou a política de contingência. Uma mutação de gestão relê concedente e alvo, proíbe autoelevação e concessão acima do mandato, e precisa preservar a invariante aprovada antes de commit. Sem catálogo/alçadas definidos, concessão/convite/reingresso/recuperação ficam desabilitados.

Suspensão de segurança de uma conta **não pode ser negada só para preservar gestor**. Caso ela retire o último gestor efetivo, a proposta é colocar a empresa em estado restrito de recuperação, sem staff global nem uso das credenciais suspensas; seleção de contingência, notificações e alçadas é **DEC-R02-C pendente de Produto/AppSec**. Não afirmar que R01-05/06 foi resolvido. Expiração temporal de gestores também pode ocorrer sem writer; disponibilidade operacional não é garantida por lock.

## 6. Filas, cache, leituras e fronteiras externas

Cache só pode sugerir referência; nenhum allow positivo ou revisão antiga autoriza. Sessão/grants, réplicas, workers e objetos por request/job são relidos sob G. Cada lote de leitura/exportação inicia unidade própria e revalida; G cobre materialização no banco, não entrega de bytes depois do commit. Resposta já materializada antes da revogação pode chegar depois; contrato de entrega/stream/URL privada permanece R01-11. Não segurar transação global durante rede lenta.

Intent outbox registra tenant, ação, recurso, ator causador, membership/incarnation, vetor esperado, entitlement e chave idempotente vinculada ao payload. Identidade de executor técnico é separada da sessão humana; se ação exige mandato humano ainda vigente, revalidar esse mandato sem reusar cookie de pessoa.

Dispatcher valida/atualiza claim sob G, libera transação e envia ao motor. Revogação pode ocorrer entre check e envio: **R01-12 continua aberto**. Fencing validado no receptor e dedupe durável, com revisão/epoch e escopo tenant, são requisitos de outro contrato. Cancelar intent, renovar lease ou checar no Portal não recolhe efeito aceito. Timeout após aceite exige estado incerto/reconciliação, nunca duplicação cega de campanha/cobrança. Esta tarefa não integra nenhum motor.

## 7. Falhas, idempotência e auditoria

Idempotência candidata escopada a tenant, actor/executor, operação e digest do payload; só mesmo escopo/payload recupera o resultado. Requisição repetida não reexecuta efeito e não revela resultado privado sem autorização atual. Restore e reingresso não tornam chave antiga válida em nova encarnação. Retenção da chave/resultados é pendência, não TTL inventado.

Falha de auditoria obrigatória aborta concessão/revogação/efeito. Diagnóstico de negação fora da transação não deve vazar dados e não transforma log não durável em prova de alteração. Negar por indisponibilidade não precisa causar retry infinito para “registrar negação”. Resultado não confirmado no cliente após commit deve poder ser reconciliado por identificador opaco sem retornar segredo.

## 8. Restore e fronteiras de garantia

G/revisões restauradas do mesmo backup não provam que revogações posteriores foram preservadas. Antes de servir: bloquear entradas privadas e workers, reconciliar eventos de revogação a partir de fonte confiável independente do snapshot ou invalidar autorizações/handles/tokens/intents afetados; atribuir nova encarnação de recuperação controlada e verificar políticas/código compatíveis. Sem fonte confiável, permanecer fechado. Procedimento, retenção e custódia independentes permanecem R01-13/G-ID08/G-BB07, sem criação de store externo nesta entrega.

Garantia proposta limita-se a efeitos internos de escritores conformes, sob role/autoridade corretas no mesmo primário, enquanto transação/G são mantidas. Não inclui runtime comprometido, superuser/operador malicioso, restauração incompleta, código de política divergente, relógio defeituoso, bytes entregues ou sistemas externos. Corrigir essas premissas exige avaliação independente, não apenas mais um contador.

## 9. Decisões e validação pendentes

AppSec e DBA independentes devem emitir parecer por SHA sobre DEC-R02-A/B (núcleo/G), inventário completo de writers, READ COMMITTED e releituras, locks implícitos, temporalidade, contenção/DoS e privilégios. Backend/Plataforma detalham retry, pooler, observabilidade e custo; Produto decide alçadas/último gestor, garantia de entrega e disponibilidade. Ninguém está designado nem aprovou.

Provas futuras R02-N02/03/05/06/07/08/12/13/14/15, REV-01..10 e H08/H09/H12/C04/C05/C08/C09/P04/P05/P07/P09/P11: **PLANEJADO / NÃO EXECUTADO**. G-ID03/05/06/07/08, G-BB03/04/05/06/07, D02-SEC/D03 físico/RLS/P0 seguem abertos. R01-02/03 têm tratamento candidato, não encerramento.
