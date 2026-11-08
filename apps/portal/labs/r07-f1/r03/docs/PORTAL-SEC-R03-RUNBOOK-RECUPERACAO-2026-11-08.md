# PORTAL-SEC-R03-D02 — Procedimento de recuperação fail-closed (v0.1)

**Estado:** rascunho documental para revisão; NÃO EXECUTAR em ambiente real. Autorização deste lote restrita ao planejamento do contrato. Dependências: D01, Issue #64, PR #67; revisão técnica interna #58.

## 1. Responsabilidades e pré-requisitos

- **Direção:** aprova políticas de risco, início de eventual recuperação operacional e decisão de reabertura, depois de evidências.
- **Coordenador técnico futuro:** impede serving, gerencia geração externa, registra hashes e conduz o procedimento; ainda não existe componente operacional autorizado.
- **Operadores de dados futuros:** restauram somente o clone isolado com privilégios segregados; não usam credenciais Z1/Z2, e toda intervenção privilegiada exige rastreabilidade.
- **Revisão técnica interna:** verifica testes, versões, commits, cobertura de writers e riscos residuais; IA/Codex não constituem avaliação independente.
- **Pré-condição absoluta:** componente Z4 genuinamente externo ao domínio restaurado definido e testado; se não existir, **BLOCKED**. A exceção de contingência para global reset precisa de autorização explícita, referência externa confiável e reconciliação de autorização.

## 2. Máquina de estados proposta

| Estado | Transição permitida | Bloqueio e evidência |
| --- | --- | --- |
| S0 NORMAL | S1 QUIESCE por operador aprovado e geração nova na Z4 | Em S0 há serviço normal; iniciar recuperação exige DENY confirmado em todos writers antes de restore |
| S1 QUIESCE | S2 ISOLATED | Cessar ingress e consumidores, rejeitar comandos antigos, registrar geração de corte, ordem das transações e pendências |
| S2 ISOLATED | S3 RESTORED_OFFLINE | Rede/GRANT sem acesso Z1/Z2; nenhum serviço aponta para DB restaurado |
| S3 RESTORED_OFFLINE | S4 RECONCILING | Validar backup/versões; não reabilitar credenciais restauradas |
| S4 RECONCILING | S5 VERIFYING | Aplicar revogações, memberships, chaves e token gerações; segregar outbox e jobs; mapear alterações posteriores ao snapshot |
| S5 VERIFYING | S6 READY | Evidências negativas completas, integridade multiempresa, reprocessamento seguro e aprovação técnica |
| S6 READY | S7 OPEN por comando atômico e gerencial | Regenerar contextos autorizados, aceitar somente writers nova generation; manter rastreabilidade |
| Qualquer estado | SX BLOCKED | Rede/fonte externa indisponível, prova ausente, inconsciência sobre writers, tampering ou tentativa de regressão |
| SX BLOCKED | S1 QUIESCE ou S4 RECONCILING | Somente com autorização explícita e reaquisição de geração nova; nunca OPEN por timeout |

**S7 OPEN não é permanente:** qualquer mudança em generation, revogação ou perda da autoridade externa provoca DENY até nova validação. Estado do DB restaurado jamais comanda sua própria abertura.

## 3. Checklist operacional futuro — sem executar agora

### A. Capturar fatos e estabelecer corte

1. Identificar ambiente/instalação, incidente, backup candidato, hash do dump, geração atual na Z4, política e manifesto de writers.
2. Fixar DENY externamente **antes** de apontar qualquer serviço ao dump, emitir novo token de fencing; verificar revogação para HTTP, filas, jobs, cron, n8n, CLI, webhooks, CDC e integrações. Se algum caminho puder escrever sem fence, a recuperação continua BLOCKED.
3. Registrar operações em voo, transações, mensagens duplicáveis, efeitos externos e instante lógico do corte; relógio de parede não define ordem de autorização.

### B. Restaurar sem exposição

4. Verificar integridade/versão de backup, suporte de criptografia, segredo disponível, compatibilidade de migrations e dependências.
5. Restaurar em clone isolado sem grants de conexão ao Z2 e sem rede de aplicação; o procedimento de restore e migração não pode conceder BYPASSRLS indevido a serviços comuns.
6. Comparar `snapshot_digest` e `recovery_operation_id` com Z4; rejeitar dump errado ou restaurado duas vezes sob geração antiga.

### C. Reconciliar confiança e efeitos

7. Reconstituir log de revogações posterior ao snapshot (contas, sessões, memberships, permissões, API keys, dispositivos, tokens de refresh e recovery, MFA, desafios e intents). Se histórico externo não for confiável/completo: global reset de credenciais **não substitui** reconstrução de grants; bloquear até decisão específica.
8. Revalidar todas as identidades e tenants, objetos e relacionamentos FK, privilégios SQL, roles, owners e definer, especialmente superuser/migrator.
9. Quarentenar jobs, outbox, filas, webhooks e comandos externos até conhecer sua relação com o snapshot, a ordem de commit e a chave idempotente no destino. Não repetir efeitos não reconciliados.
10. Confirmar caching/pooling/long-polling, leases e sessões persistentes; invalidar conexões, geração e tokens antigos. Uma resposta HTTP 503 não demonstra que nenhum worker pode fazer commit.

### D. Provar e reabrir

11. Executar matriz D03 em clone com dados fictícios; em ambiente real um plano de homologação independente dessa PoC seria requerido. Verificar negação de leitura/escrita e **ausência de efeitos no DB e integrações**.
12. Registrar evidências imutáveis: Git SHA, geração, backup digest, SQL queries/contagens sanitizadas, versões, identidades operadoras, lista de writers, resultados PASS/FAIL/BLOCKED, resíduo e parecer técnico.
13. Se e somente se estiverem completos os gates, exigir aceite gerencial específico, registrar OPEN condicional em Z4 e habilitar consumidores gradualmente. Um incidente ou divergência reverte para DENY.

## 4. Abortos e rollback

- Em qualquer falha de transição, Z4 inacessível, concessão de privilégios inesperada, erro de snapshot, timeout de operador ou discrepância do inventário: `serving=DENY/BLOCKED`; parar writers e manter clone isolado. Nunca "voltar ao último backup" com sessões automaticamente ativas.
- Falha durante S4 exige novo `recovery_operation_id` ou continuação idempotente comprovada; não reutilizar arbitrariamente intents nem limpar o histórico de revogação.
- Ações de suporte emergencial não têm bypass de fence silencioso; recuperação de acesso precisa trilha e dupla revisão quando aplicável.
- Não assumir segurança sob perda simultânea de Z3+Z4, host inteiro, split-brain, duas regiões ou credenciais administrativas comprometidas: requer desenho/PoC separados.

## 5. Limites atuais

Este runbook não foi executado contra o Portal operacional; nenhuma infraestrutura, SSO ou endpoint de cliente foi criado. Z4 ainda não foi escolhido, construído ou provado. O fluxo do PR #67 apenas demonstrou arquivo externo ao dump dentro do runner e reconciliação restrita ao ator A; não é implementação deste protocolo. Todos os gates P0, SEC-R03, G-ID/G-BB, D02-SEC, D03/RLS e release continuam abertos.

**Decisões pendentes:** fonte Z4 e falha independente; política de reautenticação global; como verificar consistência atômica em commits concorrentes; fonte do histórico completo de revogações; envelope do reprocessamento de outbox; desenho HA e RPO/RTO; revisão e aprovação gerencial de riscos/custos. Sem escolha de fornecedor ou despesa nesta etapa.
