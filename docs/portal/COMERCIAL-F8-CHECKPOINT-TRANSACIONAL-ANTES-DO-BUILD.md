# Portal Comercial F8 — Reserva transacional antes de compilar Astro

## Recorte implementado
O workflow de laboratório E2E agora verifica que a assinatura emitida pelo PHP, a aprovação e a fila PostgreSQL possuem **o mesmo hash SHA-256 e release ID** antes da compilação Astro.

1. PHP emite apenas catálogo fictício e chave de assinatura efêmera.
2. O runner calcula o digest e registra a aprovação sintética no PostgreSQL.
3. A nova barreira F7 fixa a geração de autorização e concede **uma única reserva** para a intenção de envio.
4. Uma tentativa repetida retorna idempotente sem criar novo registro.
5. O Astro consome o snapshot assinado e valida HTML/sitemap.
6. Revogação seguida de reativação mantém o release antigo bloqueado para novas reservas.
7. O teste existente de hash inválido mantém a falha fechada.

## Limitações
- A reserva é somente um **pré-requisito de laboratório**; não significa que um conteúdo já foi publicado em Cloudflare.
- Na produção, a aprovação e o pin devem vir de identidade confiável, não de variáveis/atores do workflow. O Portal continua sem serviço de autenticação de publicação e sem escrita em banco operacional.
- Este fluxo não cobre falhas de rede/worker entre reserva e envio, cancelamentos pós-reserva, replays por restauração de backup, revogação por fonte externa, ou exactly-once no consumidor.
- Arquivos SQL ficam em `labs/`; nenhum DDL é aplicado como migration Laravel.

PR draft, sem alterar main, VPS, DNS, publicação, checkout ou contas de clientes.
