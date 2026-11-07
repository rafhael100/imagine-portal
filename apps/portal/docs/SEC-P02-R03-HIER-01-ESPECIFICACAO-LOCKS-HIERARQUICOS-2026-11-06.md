# SEC-P02-R03-HIER-01 — Especificação técnica candidata da revogação hierárquica D01-B

**Data:** 06/11/2026. **Base normativa:** SEC-P02-R02-DEC-01, D01-B aprovada documentalmente; PR #33, SHA base b330578056301d410457d0f908298fd6a7c9f7a7.
**Estado:** ESPECIFICAÇÃO DETALHADA CANDIDATA. NÃO implementada, ensaiada, homologada ou adotada fisicamente. Sem SQL executável, lock efetivo, migração, código ou autorização de laboratório.
**Riscos-alvo:** R01-02, R01-03, R01-04, R01-05, R01-11, R01-12; G-ID03/05, G-BB04/06. Este texto substitui a orientação de G global exclusiva do ADR02 anterior apenas no desenho-alvo, preservando-o como histórico.

## 1. Invariante a provar

Em cada efeito interno protegido, a transação relê sob barreiras compatíveis a identidade Portal, estado de conta e sessão, fator requerido, empresa, membership, concessões, política e titularidade do recurso. Um evento de revogação que se linearize antes da ação invalida aquela autorização; um efeito que tenha concluído seu commit antes da revogação não é desfeito. A chegada da requisição HTTP não determina a ordem de linearização.

Mudanças de conta ou política global atingem múltiplas empresas e precisam disputar barreiras com operações afetadas. Empresas sem dependências comuns não devem competir por um único **lock exclusivo global por operação**. Nenhum cache, JWT contendo allow, réplica ou objeto de memória autoriza uma operação futura. Para leitura/exportação longa e serviços externos, não estender a garantia de revogação de um commit de escrita a bytes/comandos já entregues.

## 2. Domínios de autoridade e barreiras candidatas

| Ordem lógica | Âmbito | Operação privada normal | Mutação que revoga/altera a autoridade | Observação |
| --- | --- | --- | --- | --- |
| 1 | Política e catálogo global (P) | Barreira compatível de leitura contra mudança global, candidata compartilhada | Barreira exclusiva para mudança de semântica de capability/política | Leituras concorrentes de tenants distintos não devem exigir exclusividade; evento global excepcional poderá interrompê-las |
| 2 | Conta Portal / epoch de autenticação (A) | Barreira de leitura compatível com sessão ativa | Exclusiva para suspensão, reset, MFA, logout global e credencial | Ator pertencente a A e B pode ter sincronização legítima entre ambas |
| 3 | Sessão específica (S) | Confirmação de sessão, revisão, fator, propósito, tempo | Exclusiva para rotação/logout individual/alteração de segurança da sessão | Testar emissão concorrente e desafios pré-MFA |
| 4 | Organização / política local (O) | Barreira de leitura dos estados, entitlement e revisão de grants local | Exclusiva para suspensão org, role/grant, catálogo local, concessão de gestão | Compartilhada entre memberships da mesma organização; evita revogação de role sem tocar todos os vínculos |
| 5 | Membership(s) (M) | Barreira de leitura do vínculo do ator e alçada exigida | Exclusiva para convite/aceite, revogação, suspensão, concessão e reingresso | Se concedente e alvo participam, bloquear por ID determinístico depois das camadas anteriores |
| 6 | Recurso, intenção e idempotência (R) | Verificar owner e agir sob proteção do domínio | Exclusiva na mutação do recurso, reserva e outbox | Ordenar recursos por identificador canônico; FKs e locks implícitos precisam inspeção |

**Modelo ainda candidato, não SQL selecionado:** modos shared/exclusive em registros canônicos e uma ordem total. Não afirmar que cada camada necessitará de um row lock ou que row locks bastem para todos os vínculos. A relação exata entre row locks, atualizações de FK, PostgreSQL READ COMMITTED/SERIALIZABLE e ORM exige revisão DBA, incluindo lock upgrades e implicit locks.

**Regras de ordem:** adquirir P→A→S→O→M→R para os domínios realmente necessários; múltiplos atores, organizações, memberships e recursos sempre por ID canônico crescente dentro da classe. Nunca adquirir O e só depois descobrir que é necessário A ou P: abortar/reiniciar com conjunto de autoridades conhecido. Se uma operação afetar global e múltiplos atores, explicitar algoritmo/lock set antes de habilitar; evitar atalhos que contornem a ordem. Ações bootstrap sem identidade autenticada exigem fluxo próprio e gate, nunca ator fabricado.

**Responsabilidade de versionamento:** catalog_epoch e global_policy_epoch (P); identity/credential/mfa/session_epoch (A); session_revision (S); organization/tenant_policy/entitlement_revision (O); membership/role_assignment incarnation e revision (M); resource_revision/outbox incarnation (R). O vetor é verificado após a aquisição de barreiras, não como substituto delas.

## 3. Sequências de operação — contratos de transação, não código

### Efeito interno de membro da empresa
1. Validar formato de handle e escopo público sem gerar allow; resolver referências mínimas para localizar barreiras, sem materializar dados privados.
2. Iniciar unidade transacional no primário. Adquirir barreiras em P→A→S→O→M→R, com modos compatíveis e IDs provenientes de autoridade, não somente da URL.
3. Descartar qualquer pré-leitura autorizadora. Reler conta/sessão/MFA, catalog/entitlement, organização/vínculo/grants e owner do recurso dentro da mesma unidade, sob contexto tenant correto e role mínima.
4. Avaliar capacidade granular. Estado desconhecido, lock ausente, timeout, conta suspensa, membership não ativa, grant revogado ou owner divergente → negar e rollback.
5. Revalidar tempo e revisões no instante exigido para o efeito, persistir escrita, idempotência e evento obrigatório de auditoria na transação; liberar barreiras somente em commit/rollback. Resposta de erro depois de commit não justifica nova escrita sem consultar chave de idempotência.

### Revogação de membro ou concessão
1. Identificar concedente/revogador e alvo, empresa, papéis envolvidos e alçadas pela autoridade atual; obter todas as barreiras necessárias antes de decidir (em especial O e M do concedente + alvo).
2. Aplicar mesmo esquema de ordenação e bloquear mutações concorrentes com leitores/efeitos dependentes. Se concedente perdeu poder durante a espera, negar.
3. Mutar estado/revisions/epoch apropriados; invalidar permissões cacheadas/intents ainda não executadas, registrar auditoria e commitar.
4. O commit marca o ponto interno de revogação. Nova ação deve reler a autoridade e negar. Sem garantia de recuperar bytes já enviados ou impedir um terceiro que já aceitou comando.

### Revogação global ou de conta
A mudança de P usa modo incompatível com os leitores dependentes de P; suspensão de A usa modo incompatível com operações que dependem da mesma conta em todas as empresas. Exigir evidência de que não há fluxo que pule P/A por usar sessão antiga, jobs ou manutenção. Conta suspensa por segurança não permanece ativa por ser último gestor; organização poderá entrar em recuperação restrita, sujeita a decisão futura.

## 4. Contraexemplos que o desenho deve vencer

- T1 obtém O/M de empresa A e lê role r1; T2 altera a mesma role sob O exclusivo; T1 não pode commitar efeito com r1 depois que T2 linearizou.
- T1 usa sessão de ator em A e B; T2 suspende a conta; ambas empresas precisam respeitar a revogação da mesma conta, mesmo com lock local distinto.
- T1 e T2 retiram-se mutuamente como os dois únicos gestores; obter M individual sem O organizacional pode deixar zero gestores. Determinar o gestor efetivo, contingência e decisão Produto.
- T1 adquire M, T2 muda catalog_epoch global; sem P compartilhada/exclusiva a autorização antiga pode escapar.
- Se duas rotinas adquirirem A→O e O→A, surge ciclo de espera; operação deve falhar/reiniciar em vez de ignorar um dos locks.
- O tempo expira enquanto transação espera: conferir vigência e MFA no ponto exigido, não exclusivamente no instante da request.
- Um job em fila carrega allow/r7 após revogação r8; a próxima unidade de efeito deve revalidar. Em serviço externo, exigir fencing aceito pelo receptor ou declarar a janela residual.

## 5. Limites e pendências técnicas

A leitura/materialização de dados em lotes não é idêntica ao commit interno: streaming e export precisam unidades limitadas, revisão por lote e fronteira definida. Operações de cliente externo não são atômicas com o banco Portal. Integrações CRM/Performance/Google/Meta não estão autorizadas. A promessa de progresso independente deve ser avaliada em testes de contenção, p95/p99 e starvation; locks compartilhados globais podem virar gargalo de outro tipo sob catálogo alterado com frequência.

**A definir por parecer e Produto:** representação real de P/A/S/O/M/R; compatibilidade dos modos; lock de conta para refresh de sessão; como rotinas globais recuperam lock sets; coerência entre store de sessão e efeitos; grafo de dependências do ORM/FK; READ COMMITTED e snapshots; política de último gestor/recuperação; limites temporais; métricas de throughput; risco residual external fencing/restore.

## 6. Evidências futuras obrigatórias

L01–L03/L06 de SEC-P02-R02-VAL-01; HIER-01..07; REV-01..10; IDs R01-02/03/04/05/11/12. Em cada caso observar sequência de locks e commits, permissões/epochs antes/depois, ausência de efeito após revogação, privacidade, retry sem duplicação e métricas. Todos PLANEJADOS/NÃO EXECUTADOS.

**Referência técnica não homologatória:** PostgreSQL, Explicit Locking (https://www.postgresql.org/docs/current/explicit-locking.html) e Transaction Isolation (https://www.postgresql.org/docs/current/transaction-iso.html). A versão principal concreta e driver do ambiente não foram inspecionados ou fixados.

**Gates D02-SEC, G-ID, G-BB, P0 e revisão independente permanecem ABERTOS. Sem código, SQL, laboratório, merge, VPS ou deploy.**
