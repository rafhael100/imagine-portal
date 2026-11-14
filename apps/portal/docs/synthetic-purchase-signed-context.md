# OWN-03 — contexto assinado por identidade separada (prova PG18, sem autorização real)

A arquitetura convencional de um login PostgreSQL compartilhado por empresa apresenta um risco: um SELECT SQL direto feito com o usuário runtime pode acessar os pedidos de outros clientes se a RLS só restringir a empresa. Esta experiência, **somente no PostgreSQL 18 descartável do CI**, testa uma alternativa para desenvolvimento futuro: **um login runtime compartilhado** + um **contexto por cliente e organização assinado por um emissor diferente**.

## Separação de poderes

- Emissor fictício: o PHP de **teste** gera uma chave aleatória de 256 bits, mantida em memória. O administrador do banco salva o segredo de verificação em tabela inacessível ao login `owner_ci_shared`.
- Requisição: organização, cliente, expiração de até 60 segundos e nonce aleatório são concatenados de forma canônica e assinados por HMAC-SHA256. O runtime do banco só **recebe** essa asserção, jamais o segredo de emissão.
- Transação: o contexto é definido com `set_config(...,true)` **local à transação**. A policy `FORCE RLS` exige que o PostgreSQL valide o HMAC por função `SECURITY DEFINER` com `search_path` restrito, com dados do pedido consistentes com a asserção.
- Autorizações de coluna: o runtime não pode selecionar ou atualizar o segredo, nem trocar de papel SQL. Uma assinatura válida para o cliente A não autoriza o B da mesma empresa; uma assinatura válida para a empresa A não autoriza B. Alterar livremente GUCs de sessão **não** gera MAC válido.
- Após rollback/commit e em uma nova conexão, sem novo contexto a leitura devolve zero linhas. Um contexto expirado é recusado mesmo com MAC correto.

## Limites explícitos

Este teste **não cria um emissor confiável de produção**, não integra login de cliente, MFA, sessões Laravel, IAM, Cloudflare, witness externo ou criptografia rotacionável. O HMAC é um **protocolo candidato** e a assinatura de 60 segundos não impede por si só replay se um contexto válido for roubado. Antes de produção, especificar emissor externo com custódia segregada, nonce/anti-replay, substituição/rotação de chaves, proteção contra restauração de snapshot (#64), transação por requisição, testes concorrentes, tratamento de revogação e revisão AppSec/DBA.

Código: `apps/portal/tools/test_synthetic_signed_identity_pg18.php`. Banco isolado: `portal_ownership_ci`; papel de laboratório `owner_ci_shared`. **Não aplicar migrations, grants ou funções à VPS.** Este experimento não altera nenhuma rota, controlador, tabela operacional, serviço ou cliente.
