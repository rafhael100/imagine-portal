# F27 — Recibo Ed25519 verificado e consumo de nonce em uma transação (LAB)

Data: 09/11/2026. Escopo estrito: CI e PostgreSQL 18 **descartável**. Sem Cloudflare real, publicação, HTTP ou acesso ao Portal de produção.

## Objetivo
Eliminar a lacuna de laboratório identificada na F26: a assinatura Ed25519 do destino fictício era testada em PHP e o consumo único do nonce/ACK era testado em SQL, mas os dois caminhos não eram chamados por um mesmo processo. A F27 liga os dois apenas no teste de integração, sem conceder permissões ao runtime.

## Implementação
- `apps/portal/labs/commercial-publishing/VerifiedDeliveryReceiptConsumerLab.php`: recebe conexão PDO local já configurada, recibo assinado e chave pública de um destino **pinada externamente**. Começa transação própria, bloqueia `publication_fence` e a linha do lease, recupera da base os valores esperados de organização, release, payload/tree SHA-256, dispatch, geração, tentativa, destino, ambiente e nonce.
- Só então chama `DestinationDeliveryReceiptVerifier` F26 para verificar Ed25519, domínio criptográfico e igualdade **com os valores lidos no banco**; não aceita scopes vindos da requisição como fonte de verdade.
- Sem fazer `COMMIT`, usa o SQL F26-B de consumo pré-verificado, que revalida nonce, digest, tentativa, geração e lease. A mesma transação registra o consumo e confirma o ACK **local**. Erros produzem rollback.
- `test-f27-verified-ack.php`: gera chave Ed25519 de destino fictício em memória, cria novo tenant/release sintético, recebe hash do build Astro F24 do CI, prepara lease e desafio e prova que recebimento adulterado não produz ACK.
- O CI PHP→Astro adiciona extensões `pdo_pgsql`/`pgsql` e executa a F27 depois dos testes F26. Sem dependência externa paga.

## Casos de regressão
Chave não confiável, tree SHA-256 diferente, tenant adulterado, nonce inexistente, confirmação fora da janela temporal, tentativa 1 antiga após recuperação para tentativa 2, replay do mesmo recibo após ACK e revogação do fence após ACK. O caso positivo associa uma única assinatura fictícia válida à tentativa atual e exige **um único ACK local**.

## Segurança: o que ainda não está pronto
- **Não há testemunha de destino independente verdadeira**: as chaves do emissor externo são geradas dentro do teste, e a assinatura comprova apenas controle da chave de teste.
- O processo de CI usa o usuário PostgreSQL privilegiado do banco descartável. Não é um desenho de RBAC produtivo; nenhum grant de execução operacional foi criado.
- O registro do nonce e do ACK permanece no mesmo banco de laboratório: pode sofrer rollback/restore. Falta uma testemunha externa durável para impedir ressurreição de estado.
- Não há serviço Laravel/endpoint HTTP, sessão real do Portal, MFA, autorização humana/tenant verificada, chave operacional em KMS, Cloudflare, autenticação mTLS, rede restrita, staging privado, deploy ou idempotência comprovada no provedor.
- A função SQL `consume_preverified_delivery_receipt` depende de identidade do chamador. A F27 só mostra o caminho seguro **quando chamado pelo serviço local de prova**. Jamais liberar acesso de execução direto a usuários, Workers ou clientes.

Nenhum merge na main, troca de DNS, contrato pago, publicação externa ou credencial operacional está autorizado. O PR de integração do Comercial continua exclusivamente de revisão.


## Evidência CI F27 verificada — 09/11/2026

- [Pipeline PHP→PostgreSQL 18→Astro #42666716318 — aprovada](https://github.com/rafhael100/imagine-portal/actions/runs/42666716318).
- Verificador de destino F26 preservado: **26 verificações** criptográficas positivas/negativas.
- Consumidor F27 testado com nonce de tentativa 1 expirado, retry para tentativa 2, prova de destino assinada válida e **um único ACK local**; **9 cenários rejeitados**, incluindo assinatura falsa, árvore SHA-256/tenant/nonce errados, prova fora do prazo, tentativa anterior, lease expirado com assinatura válida e rollback integral, replay e revogação.
- [Ledger PostgreSQL LAB #42666716645](https://github.com/rafhael100/imagine-portal/actions/runs/42666716645), [testes Portal #42666716399](https://github.com/rafhael100/imagine-portal/actions/runs/42666716399) e [validação Laravel isolada #42666716450](https://github.com/rafhael100/imagine-portal/actions/runs/42666716450): aprovados.
- Este relatório atesta **somente a integração sintética**. Não há recibo real Cloudflare, grants autenticados de serviço, canal seguro do destino, witness externo de rollback, nem deploy.
