# Portal Comercial F11 — Reprocessamento controlado (PostgreSQL descartável)

## Entrega
- A reserva de dispatch F7 não equivale a uma entrega. F11 adiciona uma tabela independente `delivery_leases` para permitir recuperação após falha.
- `acquire_delivery_lease` exige publicação autorizada na geração atual, com recibo e hash, bloqueia worker simultâneo e permite uma nova tentativa somente após expirar o lease de cinco minutos.
- A contagem de tentativas avança monotonamente: um ack de tentativa anterior é rejeitado. Ack repetido da tentativa corrente é idempotente.
- Revogação durante uma tentativa bloqueia sua confirmação posterior. Role leitora não pode chamar funções de reserva/ack e lê somente o tenant simulado.
- CI executa dois processos PostgreSQL concorrentes e exige exatamente um lease adquirido e um resultado busy.

## Limites fundamentais
- O ack é **local** e não comprova entrega efetiva em Cloudflare. A aplicação operacional precisará validar comprovante confiável do destino antes de confirmá-lo.
- Uma solicitação externa cuja resposta se perde pode ser reenviada após o lease expirar. Deve utilizar a **mesma chave de idempotência** por release no destino e consultar o estado remoto antes de reexecutar, caso contrário podem ocorrer efeitos duplicados.
- Geração e estado de revogação ainda residem no mesmo banco experimental; backup restaurado pode violar a proteção. Identidade real/MFA, vínculo de empresa, chave remota, serviço outbox e recuperação de falhas continuam pendentes.
- Os scripts SQL vivem apenas em `apps/portal/labs`, não são migrations. Sem merge, VPS, DNS, clientes ou produção.
