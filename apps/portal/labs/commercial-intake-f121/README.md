# Portal F121 — Dois bancos PostgreSQL reais descartáveis e recibo assinado de receptor CRM **simulado**

**Escopo: laboratório isolado do GitHub Actions; não é integração com o CRM Imagine real.** A F121 avança o teste F120 (HTTP → Portal PHP → PostgreSQL local) para testar outro armazenamento, independente do Portal, com confirmação assinada somente após o commit, perda da resposta e reconciliação por consulta.

## Arquitetura de teste

Um único runner GitHub-hospedado inicia um contêiner PostgreSQL 18 com **duas bases independentes**, sem sincronização nem tabela compartilhada: `portal_commercial_lab` mantém o ledger/outbox do Portal F118/F119, e `portal_crm_receiver_lab` mantém um **receptor CRM simulado**. A segunda base é criada do zero pelo workflow, nunca em migrations da aplicação ou ambiente persistente.

O código do mock está em `apps/portal/labs/commercial-intake-f121/`, **fora das rotas Laravel**. Um servidor PHP embutido `127.0.0.1:19121` atende exclusivamente, durante os testes, a `POST /__lab/crm/deliver` e `POST /__lab/crm/lookup`. Não há porta pública, DNS, VPS, cliente real nem chamada a `imagine-crm`.

`mock_crm_receiver_pg18.sql` define duas tabelas com **apenas metadados UUID e timestamp**: `received_submissions` (PK empresa+submissão) e `request_nonces` (PK empresa+nonce, FK diferida), ambas com `FORCE ROW LEVEL SECURITY`, papéis `NOLOGIN/NOBYPASSRLS` e permissões de leitura/inserção por tenant. Não existe payload, nome, e-mail, telefone, consentimento, IP, chave nem token armazenado.

## Teste ponta a ponta sintético

1. O Portal F119 grava, no **primeiro banco**, a submissão técnica e seu evento de outbox, com idempotência e nonce. Isso **não** entrega nada ao CRM.
2. Um cliente de CI assina o pedido para o receptor **simulado** com HMAC-SHA256 sobre seis UUIDs fixados, instante, prazo e nonce. A chave de requisição é gerada aleatoriamente durante o CI, separada da chave de recibo.
3. O mock verifica assinatura, janela temporal de até 300 segundos e binding configurado no próprio receptor; grava nonce + envio no **segundo banco**, em uma transação com RLS. Se o nonce foi reutilizado, rejeita. Se a mesma submissão usa nonce novo, preserva uma única linha e verifica o contexto original.
4. **Somente após o commit**, o mock pode devolver recibo técnico assinado com outra chave e o domínio experimental do CRM F3-15, `imagine.lead-intake.synthetic-receipt.v1`. O cliente compara os seis UUIDs esperados, prazo/nonce e assinatura por `hash_equals`. Alteração de destino, chave errada, recibo vencido ou MAC adulterado são rejeitados.
5. O teste simula **resposta perdida após o commit**. Embora o segundo banco contenha um registro, a resposta HTTP é `503 UNKNOWN_OUTCOME_NO_RECEIPT`, **sem recibo e sem autorização de confirmar ao visitante**. O cliente não reenvia automaticamente o mesmo pedido. Em vez disso, consulta `/__lab/crm/lookup` com **assinatura de domínio diferente**, lê o registro existente no segundo banco e valida um novo recibo sintético.
6. Uma consulta para submissão inexistente não devolve recibo. Identidade de outra empresa, rota não prevista, assinatura inválida, replay de nonce e nova tentativa que altera contexto falham sem confirmar entrega.
7. O CI valida PHP 8.3 e PHP 8.4, inclusive os testes F117/F118/F119, cria e descarta as duas bases, e bloqueia referência ao mock no runtime do Laravel.

## Distinção crítica de segurança

Um recibo com `mockReceiverCommitted=true` comprova **somente** que o banco do mock persistiu metadados naquele teste. Todas as respostas mantêm `realCrmPersisted=false`, `canAcknowledgeVisitor=false` e `productionAuthorized=false`, inclusive após uma consulta de reconciliação. Esta etapa **não demonstra** que o CRM Imagine atual tenha endpoint, autenticação, storage, recibo assinado, replay protection ou persistência real. O contrato HMAC foi inspirado na estrutura sintética F3-15 já existente, mas utiliza chaves do CI e não autentica qualquer serviço externo.

A simulação de resposta perdida retorna 503 sem recibo, mas ainda **não** reproduz todas as falhas de partição de rede, latência/timeout, concorrência entre hosts ou fluxos de recuperação entre infraestruturas independentes. Os dois bancos ficam no mesmo servidor temporário do teste, separados logicamente.

## Próximo trabalho operacional

A evolução segura depende de implementar e homologar, **nos repositórios responsáveis**, o receptor de leads do CRM com sua fonte confiável de tenant/destino, credenciais de serviço em cofre, TLS, outbox/worker persistentes, autorização e revogação, assinatura/rotação de recibos, reconciliação autenticada e auditoria de dados pessoais/consentimento. É indispensável revisão SEG/DBA/LGPD, infraestrutura de staging autorizada e testes independentes antes da captação pública. A F70 do Comercial continua bloqueada.

Não há uso de dados de clientes, chamada a CRM real, endpoint público, `main`, DNS, VPS, deploy ou custo externo nesta entrega.
