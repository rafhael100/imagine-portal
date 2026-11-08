# PORTAL-SEC-R03-D03 — Matriz de testes e decisões para próxima PoC (v0.1)

**Estado de todos os casos abaixo: NOT RUN neste lote.** É matriz documental, sem programação ou execução de novos testes. Evidências históricas dos PRs #51/#67 **não** constituem execução de Z4 durável. Responsável pela aprovação gerencial: Direção; revisão técnica interna conforme Issue #58.

## 1. Formato obrigatório por caso

Identificador, risco, pré-condição e geração Z4, manifest de writers, SHA, versão de PG e do código, backup digest, ações HTTP/SQL e externas, estado final verificado com DB e auditoria, logs saneados, resultado PASS/FAIL/BLOCKED/NOT RUN, limites e revisor interno. O teste somente recebe PASS quando a evidência observável inclui ausência de efeitos não autorizados; resposta 401/403/503 isolada não é suficiente.

## 2. Matriz negativa mínima para contratos de produção

| ID | Cenário adversarial | Critério observável | Estado |
| --- | --- | --- | --- |
| SEC-R03-X01 | Snapshot anterior à revogação e serviço Z2 inicia prematuramente | DENY antes de servir; 0 reads/effects indevidos | NOT RUN |
| SEC-R03-X02 | Tentativa de reduzir generation Z4 por rollback do PostgreSQL | Z4 monotônica rejeita regressão; serviço não aceita geração velha | NOT RUN |
| SEC-R03-X03 | Restore integral de host com cópia antiga de arquivos locais | Fence em outro domínio detecta downgrade; mantém DENY | NOT RUN |
| SEC-R03-X04 | Z4 inacessível antes da abertura | Falha fechada; nenhum writer opera | NOT RUN |
| SEC-R03-X05 | Z4 cai depois de OPEN | Todas as rotas e writers param de autorizar novo efeito | NOT RUN |
| SEC-R03-X06 | Duas autoridades fazem recover/reopen simultâneo | Apenas uma geração prevalece; sem split-brain | NOT RUN |
| SEC-R03-X07 | Revogação e commit de writer concorrentes | Ordem linearizável: após corte não há commit indevido | NOT RUN |
| SEC-R03-X08 | Worker longo mantém lease/conn antiga após corte | Lease invalidado; nenhuma escrita tardia | NOT RUN |
| SEC-R03-X09 | Cache ou pool guarda grant anterior | Nenhuma decisão de cache sobrepõe estado Z4 | NOT RUN |
| SEC-R03-X10 | Tentativa com sessão, refresh ou MFA/recovery revogados após snapshot | Tokens e desafios antigos negados; sem reautenticar silenciosamente | NOT RUN |
| SEC-R03-X11 | Grants/roles de tenant A alterados depois do dump | Nunca ressuscitam permissões; recusa se ledger incompleto | NOT RUN |
| SEC-R03-X12 | Token de API técnica revogado, mas restaurado no banco | Não consegue ler/produzir efeito | NOT RUN |
| SEC-R03-X13 | Replay de intent C3, step-up, webhook ou n8n | Idempotência contextual e autorização atual; nenhuma repetição | NOT RUN |
| SEC-R03-X14 | Outbox entregue antes do desastre, mas marcada como pendente no dump | Não repete efeito externo sem confirmação/deduplicação | NOT RUN |
| SEC-R03-X15 | Job/scheduler/CLI não listado tenta escrever | Gate detecta writer sem contrato; bloqueio de serving | NOT RUN |
| SEC-R03-X16 | Owner/migrator superuser altera GRANT/triggers | Boundary de privilégios detectado; fail closed; risco documentado | NOT RUN |
| SEC-R03-X17 | Restores com dumps de tempos e gerações diferentes | Só snapshot aprovado e reconciliado pode avançar para READY | NOT RUN |
| SEC-R03-X18 | Falha na metade da reconciliação/migração | Nunca OPEN; operação retomável/repetível sem conceder direitos | NOT RUN |
| SEC-R03-X19 | Z1 fornece claims falsos ou sessão A tenta acesso B | Fonte confiável de tenant/actor; zero efeitos em B | NOT RUN |
| SEC-R03-X20 | Z4 e banco discordam sobre membership e revogação | BLOCKED, sem reparar por palpite; alerta e trilha | NOT RUN |
| SEC-R03-X21 | Credencial operacional de Z4 vazada para Z1 | Z1 não pode mudar serving/revogações; auditoria de tentativa | NOT RUN |
| SEC-R03-X22 | Recuperação de Z4 de snapshot desatualizado | High-watermark confiável não diminui; caso contrário BLOCKED | NOT RUN |
| SEC-R03-X23 | Reabertura durante mensagem em voo ou commit iniciado | Prova de fencing na fronteira de commit, não apenas no gateway | NOT RUN |
| SEC-R03-X24 | Término/repetição inesperada do coordenador | Transição idempotente, geração sem regressão e clone fechado | NOT RUN |

**Segurança de contexto:** validação contra credenciais comprometidas de Z2 e contra owner/superuser requer limites de confiança próprios; não declarar "proteção absoluta". Um ledger durável é somente um mecanismo dentre vários; riscos de T3 e RLS/GUC permanecem.

## 3. Evidência histórica disponível, sem promoção de status

- PR #51, CI [42511626388](https://github.com/rafhael100/imagine-portal/actions/runs/42511626388): snapshot anterior à revogação demonstrou risco de sessão antiga potencialmente válida no clone; a cópia não foi servida. **Falha de desenho reproduzida**, não certificação.
- PR #67, CI [42514185621](https://github.com/rafhael100/imagine-portal/actions/runs/42514185621): R03-N01/N02/N03/N04/N05/N09/N11 passaram no ambiente sintético com fence no runner; N06/N07/N08/N10 foram NOT RUN. Esse ensaio não implementou Z4 externo durável. Não reutilizar os PASS como status de X01–X24.

## 4. Critérios para sair de BLOCKED

1. Decidir formalmente escopo de falha do Z4, consistência transacional/CAS, controle de acesso, rotação de chaves, durabilidade, disponibilidade, backup independente e modelo de ameaça de administrador.
2. Escolher com aprovação gerencial entre A (reset + reconciliação confiável), B (ledger externo durável) ou C (híbrido), incluindo custos e operação sem exigir fornecedor novo nesta fase.
3. Registrar **manifesto completo** de todos os writers reais quando existirem, com credenciais, privilégios, contratos de fence, geração, leitura/escrita, efeitos externos e incident response.
4. Implementar **separadamente e apenas após autorização específica** uma PoC descartável que demonstre X01–X24 ou marque casos ausentes como BLOCKED, sob falhas e concorrência.
5. Exigir testes de integração com sistemas reais em ambiente seguro e decisão posterior de segurança operacional; nenhuma aprovação deste documento altera main/dev, VPS, produção, login de clientes, custos ou gates de release.

## 5. Perguntas para decisão gerencial futura

- **D01:** Usar C (ledger de geração realmente independente + logout global pós-desastre) como referência preferencial para **estudo**, sem homologá-lo?
- **D02:** Quais sistemas externos entram na primeira revisão de writers: Portal, CRM, Performance, n8n, Sites, suporte e backups?
- **D03:** Aceitar reautenticação obrigatória de todos os usuários após recuperação como política inicial de segurança?
- **D04:** Qual topologia já contratada pode oferecer domínio de falha independente de forma verificável? Um único VPS central não basta para desastre integral do host.
- **D05:** Quem assina a prova técnica e a autorização de OPEN, e como impedir que o mesmo agente possa alterar o ledger e apagar o histórico?

**Recomendação:** submeter D01–D05 à Direção após revisão interna; manter LOCKED / NOT RUN até nova autorização explícita para PoC. Nenhuma despesa foi aprovada neste documento.
