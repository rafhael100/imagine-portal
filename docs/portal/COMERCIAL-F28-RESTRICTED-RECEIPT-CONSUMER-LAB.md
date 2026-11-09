# F28 — Consumidor de recibos com privilégio mínimo (laboratório PostgreSQL 18)

Data: 09/11/2026. Escopo: repositório Portal, exclusivamente CI de laboratório e banco descartável. **Não há publicação real nem migração Laravel.**

## Contexto e problema

A F27 vinculou a verificação de um recibo Ed25519 ao consumo de nonce e ao ACK numa transação PostgreSQL, mas o teste PHP utilizava a conexão privilegiada do banco descartável. A F28 reduz o privilégio efetivo durante a transação de consumo, sem expor tabelas diretamente ao papel simulado.

## Implementação

- `schema-f28-restricted-consumer.sql` cria um papel `commercial_lab_receipt_consumer` com `NOLOGIN` e `NOBYPASSRLS`. O papel recebe apenas `USAGE` no schema e `EXECUTE` em duas funções de fronteira; não recebe leitura nem gravação nas tabelas de ledger, leases, nonce, approvals ou fences.
- `read_current_delivery_scope` (`SECURITY DEFINER`, `search_path` fixo) bloqueia `publication_fence` e a linha de lease e devolve o escopo pinado no banco (organização, release, digest, árvore SHA-256, despacho, geração, tentativa, destino, ambiente e nonce). Recusa autoridade desativada, lease ausente, vencido, confirmado ou de geração antiga.
- `commit_verified_delivery_receipt` (`SECURITY DEFINER`, `search_path` fixo) é um invólucro restrito do consumidor F26-B, que revalida o fence, lease, tentativas e nonce antes de escrever consumo e ACK local.
- `RestrictedVerifiedDeliveryReceiptConsumerLab.php` inicia uma transação, usa `SET LOCAL ROLE commercial_lab_receipt_consumer` no PostgreSQL descartável, lê o escopo seguro, verifica o recibo Ed25519 do destino fictício pela classe F26 e chama o invólucro restrito. Tudo ocorre na mesma transação; erros geram rollback.
- `test-f28-restricted-verified-ack.php` usa uma organização separada para confirmar que o papel não pode executar SELECT/UPDATE/INSERT nas tabelas, nem `ack_delivery_lease`, `consume_preverified_delivery_receipt` ou outros procedimentos internos diretamente. Também testa chave inválida, digest/tenant/nonce adulterados, prazo, retry obsoleto, replay, revogação e ACK local único.
- Workflow `commercial-catalog-e2e.yml` executa a F28 após os testes F26/F27, usando o mesmo SHA-256 gerado pelo Astro no CI.

## Limites de segurança que permanecem

1. O CI **ainda abre a conexão PostgreSQL como superusuário descartável** e só então reduz o papel com `SET LOCAL ROLE`. Isso demonstra as permissões e o comportamento sob o papel restrito, **não** um login operacional genuinamente independente. A implementação de produção deve usar credenciais de serviço não privilegiadas e escopo de rede próprio.
2. A função `commit_verified_delivery_receipt` não verifica Ed25519 dentro do PostgreSQL. Um serviço que obtenha `EXECUTE` poderia invocá-la diretamente com claims de laboratório. A confiança na verificação PHP permanece obrigatória; os grants deste laboratório **não são suficientes para segurança produtiva**.
3. Não há testemunha remota real do Cloudflare, confirmação de digest observado no destino, conta de serviço OAuth/mTLS, MFA/identidade de usuário efetiva nem políticas de API implantadas.
4. Nonces, fences e ACKs residem no mesmo banco do laboratório; restauração de backup pode restaurar estados antigos. É necessária autoridade externa durável de revogação/consumo antes de ambiente real.
5. Branches e PRs permanecem draft. Sem credenciais de produção, deploy, alteração DNS, testes pagos, dados reais ou merge em `main`.

## Critério de validação

Apenas após GitHub Actions mostrar `success` para o PR F28, registrar os links das execuções e os casos de teste. A confirmação é **somente de laboratório**, nunca comprovante de entrega externa.
