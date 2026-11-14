# P0 — vínculo do cliente no encaminhamento Portal → Sites → CRM (CI)

## Problema observado

A PR #725 recebe quatro contas de teste e encaminha dois pedidos elegíveis ao Sites, mas o extrato transitório do banco PostgreSQL continha apenas `id`, produto, estado do pagamento e presença de briefing. Assim, a rotina de admissão não comprovava de forma independente que cada pedido ainda pertencia a **uma conta ativa diferente**, ou que a empresa vinculada à compra permanecia a mesma. Isso deve bloquear o provisionamento, não apenas ficar implícito na navegação do browser.

## Entrega de teste

O script `run_owned_to_sites_ci.sh` exporta **somente dentro da runner descartável**, além dos campos anteriores, `customer_id`, empresa do pedido, empresa da conta e o sinal `enabled`, sem nome, e-mail, senha ou outros dados pessoais. O PHP do laboratório exige **quatro proprietários diferentes e ativos**, correspondência `pedido.tenant = cliente.tenant = test-org-a`, quatro briefings e pagamentos fictícios aprovados. O teste recusa formato inválido, cliente duplicado, conta desabilitada, empresa trocada, ausência de briefing e produtos Performance em Sites.

Antes de contar a admissão como comprovada, também injeta **cópias em memória** de um pedido aprovado com `enabled=false` e outro com a empresa adulterada. Ambos devem ser recusados pelo encoder de contrato; nenhuma alteração é feita ao banco. A evidência sanitizada agrega `unique_synthetic_customer_owners_verified=4` e `disabled_and_foreign_owners_refused=true`. O validador CI exige esses atributos.

Em sequência, o teste preserva os dois eventos Sites com nonce, outbox e ACK e continua para os dois leads fictícios `@example.invalid` no terceiro PostgreSQL18. O extrato temporário é removido com `trap` até mesmo em caso de erro.

## Distinção para produção

Isso **não equivale** a consultar o estado da conta de usuário real através de uma autoridade IAM independente. O fluxo ainda usa superusuário PostgreSQL **somente no CI** para criar o extrato e campos de consentimento inventados; não possui PSP, contrato, API service-to-service assinada ou revogação externa. O bloqueio pós-pagamento de uma conta desabilitada ainda precisa de fonte de autoridade real e fencing contra recuperação de estado antigo.

**DRAFT / NO-GO. Sem merge automático, publicação, VPS, clientes reais ou dados pessoais.**
