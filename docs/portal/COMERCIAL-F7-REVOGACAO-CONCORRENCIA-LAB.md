# Portal Comercial F7 — Fence de revogação e reserva concorrente de dispatch

## Funcionamento demonstrado no PostgreSQL descartável
- Cada organização recebe uma geração crescente e um estado habilitado/bloqueado no `publication_fence`.
- A aprovação de conteúdo vincula o release/hash a uma geração em `release_fence`.
- A reserva da tentativa de envio exige um registro de outbox aprovado, hash idêntico, geração ainda válida e organização habilitada.
- Um `SELECT ... FOR UPDATE` protege a verificação e a reserva na mesma transação, serializando com revogação que atualiza a mesma linha.
- Revogar desabilita e incrementa a geração; reabilitar não resgata aprovações antigas.
- Reservas ficam em registro append-only, com unicidade por organização/release. Em duas conexões concorrentes, exatamente uma cria reserva, a segunda recebe resultado idempotente.
- O papel leitor experimental continua sem direito de inserir/alterar reservas e tem RLS por organização.

## Limites decisivos
O resultado chamado *reserva* **não confirma envio externo**, deploy, publicação, entrega exatamente uma vez ou estado remoto. Um crash entre reservar e efetuar o envio pode gerar perda de entrega. Exatamente-uma-vez exige protocolo de recuperação/reconciliação e idempotência no destino, e continua pendente.

A revogação neste banco descartável não protege contra restauração de um backup anterior do próprio banco. O fence real precisa estar em fonte independente, com autoridade de identidade/MFA, revisão de vínculos e barreira na hora do efeito. A GUC de tenant é apenas um dado experimental, não uma prova de autenticação. O teste escreve como superusuário do contêiner efêmero; não modela uma role segura de publicação real.

Todas as tabelas e scripts permanecem exclusivamente em `apps/portal/labs/commercial-publishing`. Nenhuma migration Laravel, endpoint, API, consumer real, VPS, pagamento, DNS ou produção está conectada.
