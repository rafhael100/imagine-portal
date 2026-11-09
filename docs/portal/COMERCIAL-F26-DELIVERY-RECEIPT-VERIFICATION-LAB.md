# F26 — Recibo de destino: contrato Ed25519 de verificação antes de ACK

Data: 09/11/2026. **Laboratório sintético e totalmente isolado**. Não há integração com Cloudflare, nem envio HTTP, nem ativação de publicação.

## Problema concreto

O laboratório PostgreSQL comercial F11 possui funções de lease, recuperação, revogação e ack. O comentário da função ack_delivery_lease determina que o chamador prove uma confirmação remota confiável **antes** de avançar o estado. Até esta etapa não existia um verificador de mensagem com escopo criptográfico. O próprio receipt de reserva local da F7 não é confirmação de publicação remota.

## Verificador PHP

A classe DestinationDeliveryReceiptVerifier recebe um envelope assinado por uma chave de DESTINO confiável (chave pública Ed25519 independente, injetada pelo operador), um escopo esperado PINADO por fonte confiável e um horário de referência. Ela **não recebe chaves privadas** e jamais chama SQL ou deploy.

O contrato exige, para um payload JSON canônico de até 4 KiB:

- Protocol e domínio criptográfico separados do catálogo editorial: imagine-commercial-delivery-receipt-v1.
- keyId explicitamente esperado, chave pública Ed25519 RAW com 32 bytes, assinatura válida com 64 bytes.
- organizationId e releaseId exatos, payloadSha256 e treeSha256 exatos.
- dispatchId, generation e attempt da tentativa atual, impedindo confusão entre retries e gerações de revogação.
- destinationId e environment exatos, evitando aceitar recibo de outro ambiente.
- nonce aleatório de 128 bits emitido pelo orquestrador, vinculado à tentativa, e data observedAt de formato estrito.
- Janela de observação: até 5 minutos no passado e tolerância de 30 segundos no futuro.
- Campos inesperados, JSON ambíguo com chaves duplicadas, Base64 não canônico e omissão de campos são rejeitados.

## Fluxo futuro — NÃO implementado aqui

1. Serviço de publicação com identidade do Portal validada, MFA e grants atuais reserva uma tentativa no PostgreSQL em transação.
2. Serviço de destino verdadeiramente independente observa a publicação e assina um atestado com nonce e SHA-256 da árvore de arquivos implantados.
3. O Portal busca o atestado por canal autenticado, usa chave pública de confiança pinada e confere toda a identidade com o verificador F26.
4. **Ainda falta** transação que verifique o estado atual do fence, commit/lease, nonce já consumido, replay e ACK. Nunca chamar ack_delivery_lease somente com base em conteúdo de requisição ou assinatura válida.
5. Reconciliar estados em timeout, reprocessamento, revogação e restauração de backup. Exigir testemunha durável externa para evitar que rollback de banco reabilite releases e nonces antigos.

## Testes

O script apps/portal/tests/Contract/test-destination-receipt-f26.php gera pares Ed25519 descartáveis, incluindo chave de emissor Portal diferente da chave do destino. A CI roda PHP 8.3 + sodium com testes positivos e negativos para assinatura, chave, tenant, release, payload/tree hash, dispatch, geração, tentativa, destino, environment, nonce, validade temporal e JSON ambíguo.

**Limites essenciais:** o F26 verifica uma *declaração criptograficamente assinada*, não prova que um site foi realmente publicado no Cloudflare ou que um operador remoto foi honesto. Sem integração com um testemunho realmente independente, registro de uso único durável, credencial segura ou observação HTTP real, **não existe recibo de entrega operacional**. Não habilitar ack nem tratar a prova como autorização produtiva.

Nenhum merge em main, DNS, Cloudflare público, dados reais, custos adicionais ou migração de banco existente está autorizado.
