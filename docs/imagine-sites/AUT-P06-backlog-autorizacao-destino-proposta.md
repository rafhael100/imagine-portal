# AUT-P06 — Leads pendentes durante troca, suspensão ou revogação

> **Decisão posterior:** a alternativa B foi aprovada em 04/11/2026. Ver [ratificação AUT-P06 B](AUT-P06-B-aprovada-2026-11-04.md). Esta minuta conserva as alternativas como histórico anterior à aprovação; contratos técnicos permanecem pendentes.


**Status: PROPOSTA — NÃO RATIFICADA.** Referência: 04/11/2026, Imagine Sites/Portal/CRM. **AUT-P01 a AUT-P05 B aprovadas; AUT-P07 a AUT-P10 pendentes.** P0-DOC03, RF-PEND-01 abertos; RF-PEND-04 não homologada. Apenas planejamento documental; sem código, testes, implantação ou homologação. AGI-11-RB01 desabilitado.

## 1. Regras já aprovadas

A IN127-R01 a R06 exige T0 na primeira recepção durável confirmada, 72h iniciais + 360h de falhas (432h contínuas), descarte seguro do payload pessoal não entregue no vencimento, auditoria minimizada até 90 períodos de 24h, reconciliação consultiva sem reenvio depois do vencimento e nenhuma captação sem CRM autorizado.

As AUT-P01–P03 exigem autorização versionada nas APIs e troca de CRM por chamado, com barreira de revogação, sem migração ou redirecionamento automático de leads antigos. A AUT-P05 exige confirmação oficial de entrega pelo CRM, consulta antes de repetir resultados incertos e idempotência comprovada. A conta CRM paga não é bloqueada automaticamente por suspensão de outro site.

**Questão AUT-P06:** qual efeito da suspensão ou revogação sobre leads já aceitos, mas ainda não entregues, distinguindo o direito de receber *novos* formulários do direito de *concluir envios anteriores*?

## 2. Alternativas

**A — Autorização congelada em T0.** Sempre continuar enviando ao CRM antigo por até 432h mesmo após revogação. Não recomendada: ignora eventual proibição posterior de recebimento.

**B — Destino imutável + permissão de entrega do backlog revalidada (RECOMENDADA; NÃO APROVADA).** Cada submissão mantém `submission_id`, T0, destino original e revisão de autorização. Antes de emitir novo comando de entrega, revalidar o **direito específico daquele destino para a operação anterior**, sem confundi-lo com (i) direito de captar novos formulários, (ii) cortesia/assinatura CRM, (iii) conectividade técnica ou (iv) permissão de mera consulta do resultado oficial.

- **Troca A → B:** novos leads após ativação usam B; pendências anteriores permanecem referidas a A, sem reencaminhamento a B. Enviar ao CRM A apenas quando o direito relativo à submissão original permanecer oficialmente válido.
- **Site suspenso/cancelado:** bloquear nova captação conforme AUT-P02; avaliar separadamente o direito de concluir backlog antigo. Suspensão financeira não prova, sozinha, permissão ou proibição de entrega anterior.
- **CRM temporariamente fora do ar:** não equivale a revogação; fila e tentativas seguras continuam dentro da janela original, se autorização válida.
- **Revogação de segurança, titularidade ou privacidade:** ausência/revogação da permissão para novo comando exige bloqueio da operação e revisão humana competente; não presumir direito permanente.
- **Resposta incerta:** consulta oficial CRM, sem efeito de criar lead, pode esclarecer entrega anterior nos limites autorizados; nunca tratar timeout como sucesso/falha comprovada.
- **Reativação:** só retomar até T0+432h com direito oficialmente restabelecido, mesmo destino, identidade e idempotência, sem reiniciar prazo.
- **Duas APIs/filas:** worker sem estado de autorização comprovável não deve emitir novo comando; revalidar escopo e versão.
- **Expiração:** aos 432h, nenhuma nova tentativa; aplicar descarte seguro IN127-R03 e metadados mínimos R04, respeitando guarda legítima sem quarentena informal. Após o limite, apenas consultas permitidas sem reconstrução de payload. Entregas já confirmadas permanecem no CRM.
- **Auditoria:** registrar referências mínimas de decisão e escopo, bloqueio, tentativa, confirmação, incidente e descarte, protegendo dados pessoais.
- **Autoridade do escopo:** a definição concreta de qual suspensão/revogação afeta backlog versus novas captações depende de contrato e decisão do sistema titular, não de inferência dos agentes.

**C — Redirecionar pendências para o CRM atual.** Não recomendada e incompatível com AUT-P03/IN127-R06; migração de dados entre contas continua responsabilidade do cliente.

## 3. Casos de borda a detalhar

| Evento | Nova captação | Lead anterior pendente (proposta B) |
|---|---|---|
| Site troca de CRM A para B | Só B após confirmação | Destino A preservado; entregar a A apenas sob direito vigente do backlog |
| Site suspenso por inadimplência | Bloqueada no site suspenso | Revalidar direito específico antes de eventual entrega |
| CRM A tecnicamente indisponível | Depende de autorização do site | Fila dentro do prazo original, sem perda automática de direito |
| Revogação explícita do backlog | Conforme revisão de vínculo | Bloquear novo comando; permitir só consulta oficialmente autorizada |
| Bloqueio de segurança e autoridade incerta | Falha fechada | Não enviar nem redirecionar até prova segura |
| Entrega anterior com resposta perdida | Sem relação | Consultar CRM e confirmar se persistido, sem duplicar |
| T0+432h sem confirmação | Conforme estado corrente | Encerrar, descartar payload conforme R03, manter apenas auditoria R04 |

## 4. O que ainda NÃO está aprovado

Protocolo de permissão para backlog, autoridade por classe de suspensão, como revalidar direitos nas duas filas, contrato de eventos, mecanismos de atomicidade/concorrência, tratamento de revogação legal, retenção em backups e alçadas detalhadas. Não aprovar campos, enumerações, TTL ou implantação a partir deste texto.

## 5. Deliberação solicitada

**Recomendação: alternativa B**, com T0 e destino originais imutáveis, autorização específica para cada novo comando de entrega, bloqueio seguro na revogação/incerteza, consulta CRM para resultado inconclusivo, **sem redirecionamento automático e sem estender 432h**.

**AUT-P06 está PENDENTE de ratificação expressa.** AUT-P07 a AUT-P10 também pendentes. Nenhuma programação ou homologação.

### Referências

- [IN127-R01 a R06](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md)
- [IN-124](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-124-integracao-inteligente-formularios-leads.md)
- [IN-148](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-148-reconciliacao-inteligente-leads.md)
- [IN-149](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-149-protocolo-entrega-confirmacao-leads.md)
- [AUT-P03 — B](P0-DOC03-AUT-P03-RATIFICACAO-ALTERNATIVA-B-2026-11-04.md)
- [AUT-P05 — B](AUT-P05-aprovacao-alternativa-B-2026-11-04.md)
