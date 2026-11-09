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

## Laboratório PostgreSQL de nonce e ACK local — SIMULADO

O escopo F26-B acrescentou os scripts descartáveis:
- apps/portal/labs/commercial-publishing/schema-f26-receipt-consumption.sql
- apps/portal/labs/commercial-publishing/test-f26-receipt-consumption.sql

Após compilar o catálogo PHP assinado em Astro, o CI transfere o treeSha256 real do build para o PostgreSQL 18 **descartável**. O banco grava um alvo imutável de revisão (organização, release, digest, geração, destino e ambiente), emite um nonce aleatório por tentativa de lease e o registra de forma imutável. A função de laboratório consome somente claims **supostamente já validadas por uma aplicação confiável**, confere lease atual, geração, digest, árvore de build, despacho, destino, ambiente, prazo e nonce emitido, registra o consumo uma única vez e executa ACK **local** na mesma transação.

O ensaio força expiração/recuperação de lease, rejeita nonce de tentativa anterior, árvore errada, geração indevida, nonce inexistente, despacho/destino/ambiente incorretos, recibo vencido e replay após ACK. Um leitor PostgreSQL sem privilégios não pode emitir novo nonce.

**Não existe nesta versão uma ligação operacional entre a assinatura PHP do destino e a chamada SQL.** Os testes do verificador Ed25519 e do consumidor de nonce usam fixtures sintéticos em módulos separados do mesmo workflow. O banco recebe claims como pré-verificadas por um chamador de laboratório; portanto, ele NÃO autentica o detentor real da chave e não substitui uma autoridade de aplicação protegida. Nenhuma função nova é concedida a papéis operacionais.

## Fluxo operacional futuro — ainda pendente

1. Sessão Portal autêntica, MFA, escopo empresarial e grants frescos reservam a tentativa no PostgreSQL mediante serviço autorizado.
2. O destino real observa o build efetivamente implantado, assina o digest com chave de destino independente e devolve atestado por canal autenticado.
3. O Portal PINa a chave pública do destino, exige claims correspondentes a desafio/lease/fence atual e executa o verificador F26 com relógio confiável.
4. O serviço consumidor, com privilégios mínimos, liga **a mesma prova validada** à transação SQL de consumo único/ACK. Também precisa validar acesso a tabelas e impedimento de bypass.
5. Registro de uso único e geração de revogação precisam sobreviver a rollback/restore com testemunha externa durável. Idempotência remota, entrega confirmada, monitoração, compensações e staging protegido continuam ausentes.

## Testes

O script apps/portal/tests/Contract/test-destination-receipt-f26.php gera pares Ed25519 descartáveis, incluindo chave de emissor Portal diferente da chave do destino. A CI roda PHP 8.3 + sodium com testes positivos e negativos para assinatura, chave, tenant, release, payload/tree hash, dispatch, geração, tentativa, destino, environment, nonce, validade temporal e JSON ambíguo.

O mesmo workflow executa uma simulação de consumo único e ACK local em PostgreSQL 18: [GitHub Actions #42663904358](https://github.com/rafhael100/imagine-portal/actions/runs/42663904358) — **26 verificações criptográficas PHP e regressão de nonce/retry/fence/ack SQL aprovadas**.

**Limites essenciais:** o F26 verifica uma *declaração criptograficamente assinada*, não prova que um site foi realmente publicado no Cloudflare ou que um operador remoto foi honesto. Sem integração com um testemunho realmente independente, registro de uso único durável, credencial segura ou observação HTTP real, **não existe recibo de entrega operacional**. Não habilitar ack nem tratar a prova como autorização produtiva.

Nenhum merge em main, DNS, Cloudflare público, dados reais, custos adicionais ou migração de banco existente está autorizado.
