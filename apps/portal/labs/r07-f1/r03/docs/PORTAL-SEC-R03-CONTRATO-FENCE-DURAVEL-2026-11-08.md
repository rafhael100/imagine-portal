# PORTAL-SEC-R03-D01 — Contrato de revogação e recuperação com fence durável (v0.1)

**Situação:** PROPOSTA DOCUMENTAL PARA REVISÃO INTERNA, **não homologada nem implementada**. Direção aprovou elaborar o contrato após a PoC do PR #67.  
**Origem:** Issue #64, PR #51 (SEC-R02), PR #67 (SEC-R03), CI PG18 [42514185621](https://github.com/rafhael100/imagine-portal/actions/runs/42514185621).  
**Escopo de alteração deste documento:** somente Markdown, branch filha da branch do PR #67; sem SQL, código funcional, deploy, VPS, migrações, merge ou gastos.

## 1. Fato demonstrado e perigo não resolvido

- PR #51: `pg_dump` anterior à revogação pode trazer de volta sessão e grants no clone PG18 (banco vivo 0 sessão válida; restaurado 1 potencialmente válida). Resultado é **KNOWN GAP**, não PASS de segurança.
- PR #67: gate externo ao **dump do PostgreSQL**, mantido em diretório temporário do runner e checado pelo PHP de laboratório, recusou HTTP 503 antes de reconciliação e 401 para sessão antiga após reconciliação manual do ator A. O arquivo **não** é registro monotônico durável nem isola falha do host. N06/N07/N08/N10 não foram executados.
- Não existem ainda todos os writers operacionais do Portal na branch dev. RLS por GUC dentro de Z2 não substitui identidade confiável; Z1 comprometida com sessão A legítima (T3) continua problema.

## 2. Objetivo e invariantes obrigatórios

**O Portal NÃO pode servir dados ou produzir efeitos a partir de uma restauração cuja geração, revogações e direitos não tenham sido confrontados com uma autoridade confiável preservada fora do domínio de rollback.**

- INV-01: `serving=DENY` é o estado inicial após crash, perda da autoridade de fence, ambiguidade de generation, perda de comunicação, restore, rotação de credencial, divergência de auditoria ou versão desconhecida.
- INV-02: restaurar um snapshot jamais diminui o **high-watermark** de geração, revogação, permissões, credenciais ou efeito externo. A fonte dessa decisão não pode ser restaurada em conjunto com a base protegida.
- INV-03: todo leitor **e escritor** com acesso a dados protegidos passa por controle confiável de generation/revocation no ponto efetivo de autorização. HTTP gate isolado não basta. Job, n8n, CLI, webhook, migração, replay, suporte, API e worker entram no inventário.
- INV-04: qualquer cache, conexão reutilizada, token, desafio, fila, outbox ou tentativa idempotente perde validade quando sua geração está aquém do fence. Caches não são autoridade; TTL não substitui bloqueio imediato de revogação.
- INV-05: nenhuma sessão, membership, papel, tenant, MFA, intenção humana ou credencial é reconstruída de claims de Z1. Grants revogados depois do backup nunca são reativados pelo restore; se não for possível provar, permanecer fechado.
- INV-06: read/write sobre organizações distintas continuam isolados; nenhuma compensação ou replay pós-restore pode afetar tenant errado.
- INV-07: a autorização para **abrir** deve depender de uma prova verificável, registrada e associada ao hash da base restaurada, geração da autoridade, versão das políticas, inventário de writers e aprovação gerencial específica.
- INV-08: nenhuma implementação presume que um `epoch++` gravado apenas no mesmo PostgreSQL restaurável seja proteção anti-rollback.

## 3. Modelo lógico candidato (não escolha de fornecedor)

**Z1 — borda não confiável:** sem SQL, tokens administrativos, acesso ao ledger ou capacidade de afirmar identidade/permissões.  
**Z2 — autoridade de aplicação:** serviços fechados, validam sessão/recurso/tenant/grants e obedecem a controle de acesso e commit com fence atual.  
**Z3 — dados restauráveis:** PostgreSQL de negócio, caches e filas de trabalho; não são fonte suficiente para provar que o snapshot é atual.  
**Z4 — autoridade de recuperação (CANDIDATA):** estado pequeno, durável e monotônico, com domínio de falha e de backup **independente** de Z3 e com controles de escrita separados dos writers comuns. Nem arquivo no mesmo VPS nem o diretório do GitHub runner satisfazem Z4 em produção. Não selecionar serviço externo nem contratar nesta etapa.

Registro lógico mínimo de Z4 (campos conceituais):

| Campo | Finalidade |
| --- | --- |
| `environment_id`, `installation_id` | Escopo imutável por instalação/ambiente |
| `generation`, `sequence` | Ordenação monotônica sem regressão; atualização atômica compare-and-swap |
| `serving_mode` | DENY / RECONCILE / READY / OPEN / BLOCKED; default DENY |
| `recovery_operation_id`, `snapshot_digest` | Vínculo do ciclo de restore ao artefato exato |
| `revocation_high_watermark` | Posição verificável de revogações ainda não aplicadas no snapshot |
| `policy_version`, `writers_manifest_digest` | Versão de privilégios e de superfície de escrita que deve ser coberta |
| `authorization_proof_digest`, `approved_by` | Evidência vinculada ao lote; identidade/aprovação gerencial a modelar |
| `previous_record_digest`, `created_at` | Encadeamento, integridade e auditoria; horário não substitui sequência |

**Obrigação de desenho:** definir como Z4 persiste eventos de revogação/grants e o high-watermark sob perda de comunicação, failover e desastre regional, e como impede split-brain. Um escalar de geração sem histórico/prova externa das permissões revogadas é **insuficiente**.

## 4. Protocolo de fencing e barreira de commit

1. O coordenador de recuperação adquire exclusividade por `environment_id` **na Z4**, avança generation monotonicamente e define `serving_mode=DENY` antes de tocar em Z3; nenhuma identidade de Z1 pode fazer isso.
2. Serviços Z2, API, workers, suporte, automações, jobs, reprocessadores e sessões persistentes precisam receber/revalidar generation **não forjável**. Sem resposta válida de Z4, negar (ou aguardar sem efeito).
3. **Uma checagem HTTP antes de abrir transação NÃO prova atomicidade.** Um writer pode atravessar o corte entre checagem e commit. O desenho operacional terá de impor barreira transacional/fencing de efeito em todos os escritores e impedir que um writer antigo faça commit após avanço da generation; documentar ordem linearizável e falhas.
4. Todo efeito externo (pagamento, notificação, campanha, webhook, CRM, n8n) exige contrato próprio de idempotência e recuperação; o fence do PG não desfaz efeito executado fora dele.
5. Após `RESTORE`, reconciliar sessão, refresh, MFA/recovery, memberships, revogações, tokens técnicos, chaves e filas pelo histórico confiável. Incerteza => BLOCKED, **não** revalidar tudo só porque o banco sobe.
6. Executar auditorias negativas com o serviço **ainda fechado**; exigir prova do estado real de dados/efeitos, não só HTTP 403.
7. Apenas após todos os gates de segurança e decisão gerencial explícita, registrar `READY -> OPEN` na Z4 por transição atômica, atualizando workers em ordem controlada.
8. Perda posterior de Z4, divergência de generation, retorno a snapshot ou revogação de emergência força DENY inclusive depois de um OPEN anterior.

## 5. Alternativas para decisão futura

**A — Invalidação global controlada (priorizar estudo de simplicidade):** no restore, proibir serving, invalidar todas as sessões, tokens derivados, desafios e credenciais temporárias, impor novo login; **também** reconciliar grants, revogações, writers e efeitos por autoridade confiável, ou manter BLOCKED. Sem âncora independente de geração/serving, uma restauração integral poderá desfazer a própria invalidação.

**B — Ledger externo de gerações e revogações:** referência monotônica com atualização condicional, histórico de concessões/revogações e escopo de falha independente. Pode permitir recuperação mais precisa, mas introduz complexidade de HA, credenciais, custos de operação e disponibilidade. **Não pressupor produto, fornecedor nem orçamento.**

**C — Híbrido:** Z4 com registro pequeno e independente + invalidação global de sessões sob desastre, reconciliando também direitos e effects. É **hipótese preferencial para próximos ensaios**, não aprovação de arquitetura. Não usar GitHub CI, KV eventual, arquivo local no mesmo host ou epoch isolado como substitutos de Z4 sem demonstrar consistência e independência.

## 6. Gates e não objetivos

- **NOT RUN:** split-brain, failover, restauração do host inteiro, fonte Z4 inacessível, concorrência de writers com commit, sessões reais, MFA, token refresh, grants reais, jobs, outbox, cache/pool, T3, Z2 comprometida e controles de integração CRM/Performance.
- **BLOQUEADOS:** todos os P0 R01-01/02/03/09/10, G-ID/G-BB, D02-SEC, D03/RLS, SEC-R03 produção e release.
- **Revisão interna** da Direção com apoio de IA/Codex conforme Issue #58: sem homologação independente/certificação.
- Só autorizar PoC posterior após definição da semântica de Z4, domínio de falha, atores autorizados, fencing de TODOS os writers e plano de testes; qualquer gasto ou implantação requer autorização própria.
