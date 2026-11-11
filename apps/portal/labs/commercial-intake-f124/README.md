# Portal F124 — Falha de resposta após COMMIT remoto e retenção de estado no Portal

**Escopo:** apenas GitHub Actions, PHP 8.3/8.4, dois bancos PostgreSQL 18 descartáveis, UUIDs fictícios e servidor PHP acessível somente em `127.0.0.1:19121`. A base receptora é um **CRM simulado**, nunca o CRM Imagine real.

A F121 comprova resposta HTTP perdida depois de COMMIT do mock. A F122 cria o bloqueio persistente `unknown`. A F123 impede que um lease vencido, que já pode ter iniciado HTTP, seja reclamado por um segundo worker. A F124 testa **as três propriedades juntas**, com os dados em bancos separados.

## Cenário de teste

1. O Portal grava metadados e outbox no banco de origem, sem guardar dados pessoais.
2. Obtém lease `claim_lab_a` e confirma no PostgreSQL `begin_dispatch_lab_a` **antes** de fazer HTTP.
3. Envia pedido HMAC assinado ao receptor PHP/PG18 em loopback, que faz COMMIT e simula perda de resposta, retornando 503 **sem recibo**.
4. O banco de origem permanece no estado `dispatching` até que o consumidor registre `unknown`; reenvio automático é proibido, inclusive após o vencimento do lease.
5. Faz consulta separada, também autenticada, ao mock; valida recibo HMAC de domínio distinto, com empresa, binding, submissão, site, formulário, destino e prazo exatos. Chave errada, destino alterado e MAC forjado são rejeitados.
6. A consulta é **somente evidência local da fixture**. Mesmo com recibo válido do mock, o Portal mantém `unknown`; não armazena um ACK, não registra sucesso de cliente, não reenvia e nunca produz `canAcknowledgeVisitor=true`.
7. Confirma que o destino tem exatamente um registro e um nonce de tentativa, enquanto o banco do Portal mantém uma outbox e o hold técnico.

## Critérios de avanço

F124 prova integração segura **em laboratório**, não valida persistência no CRM real, identidade de serviço de produção, KMS, TLS, políticas de tenant operacionais nem confiabilidade em máquinas distintas. A resolução do estado `unknown` depende de operação de reconciliação autenticamente implementada no CRM, verificação AppSec/DBA/LGPD, rastreabilidade, privacidade, supervisão e homologação controlada.

Sem rotas Laravel, credenciais reais, dados de clientes, publicação, deploy, alterações na `main`, VPS ou novas despesas. PR deve permanecer em draft.
