# Portal F119 — Autenticação sintética de serviço e nonce transacional em PostgreSQL 18

## Objetivo

A F118 já comprova no PostgreSQL 18 de CI uma transação atômica entre o registro técnico do orçamento e o evento pendente da outbox. A F119 acrescenta **verificação de integridade de requisição entre serviços**, janela temporal limitada e **nonce persistido na mesma transação**. Trata-se de um recorte de segurança isolado em PHP, sem nenhuma rota Laravel nem conexão com serviços externos.

A assinatura F119 tem domínio próprio, `imagine.portal.synthetic-service-request.v1`, para **não ser confundida com o recibo experimental do CRM F3-15** (`imagine.lead-intake.synthetic-receipt.v1`). Uma requisição aceita pela F119 **não é** um ACK do CRM.

## Implementação no laboratório

- `PortalF119SyntheticServiceReservation.php` recebe envelope técnico F117, binding separado de laboratório, metadados de autenticação e uma conexão PDO em PostgreSQL 18 **descartável**. A chave de teste é criada em memória pelo teste PHP e injetada no servidor simulado. Não existe identidade real, sessão compartilhada Portal–CRM, chave do usuário ou segredo versionado.
- O MAC usa HMAC-SHA256 sobre domínio exclusivo + seis UUIDs normalizados, em ordem fixa, + emissão, expiração e nonce. A comparação é feita por `hash_equals`, com assinatura hexadecimal minúscula, nonce UUID minúsculo, tempo limitado a cinco minutos e desvio futuro máximo de 30 segundos.
- Só após MAC válido o código exige `GITHUB_ACTIONS=true`, `RUNNER_ENVIRONMENT=github-hosted`, `PORTAL_F119_PG_LAB=1`, host local e banco `portal_commercial_lab`. Não admite uma conexão já transacionada.
- `nonce_pg18.sql` adiciona ao schema **somente de CI** uma tabela de quatro metadados: empresa UUID, nonce UUID, submissão UUID e carimbo do banco. A PK `(empresa, nonce)` evita reutilização, e uma FK adiada exige uma submissão lógica existente na mesma empresa ao final da transação. As duas empresas sintéticas usam `FORCE RLS`, `NOLOGIN`, `NOBYPASSRLS` e permissões `SELECT/INSERT` limitadas.
- **Uma única instrução SQL** grava o nonce, a submissão nova e o evento da outbox, por CTEs dependentes, e fornece contadores de sucesso. Se a assinatura ou identidade não passar, nada é gravado. Nonce repetido encerra a operação sem criar uma segunda solicitação.
- Um **nonce diferente para a mesma submissão lógica** deve ser distinguido de um novo orçamento. O código verifica a integridade dos metadados originais e a existência da outbox. Se o destino original for alterado, a transação é desfeita, incluindo a tentativa de registrar o nonce.
- A suíte `test_pg18_service_mac_nonce.php` verifica assinaturas/chaves inválidas, cabeçalhos extras, expiração/tempo futuro, replay, múltiplos nonces para um mesmo pedido, alteração de destino, campos pessoais extras, tentativa de usar o tenant B pelo papel A, isolamento RLS e negação de UPDATE, DELETE e TRUNCATE de nonces.
- Workflow específico usa PostgreSQL 18 temporário acessível apenas em `127.0.0.1`, matrizes PHP 8.3 e 8.4, roda F117+F118+F119, e confirma que nada foi importado em rotas/bootstrap/app Laravel.

## Limites e próximos marcos

Esta implementação **não é API de autenticação operacional**: a chave é uma fixture aleatória de CI; o banco temporário usa papel privilegiado para assumir identidades SQL fictícias; o contrato não tem TLS, rotação ou revogação de chave, proteção de payload de contatos, consentimento jurídico, mecanismos de antiabuso, retenção ou monitoramento de produção. Em especial, não confirma persistência no CRM, e nem um MAC correto pode autorizar recibos, reenvio de resultado desconhecido ou processamento de dados pessoais.

O próximo recorte operacional requer autenticação de serviço via credenciais em cofre, identidade de empresa derivada do servidor, autorização de membership/destino, transação persistente, fila/worker de produção, confirmação idempotente do CRM com provas de durabilidade, reconciliação interbancos, testes de partição e revisão SEG/DBA/LGPD.

**Gate F70 Comercial continua bloqueado**. Nenhum endpoint público foi criado, nenhuma alteração `main`, DNS, VPS, produção ou despesa foi autorizada. Somente fixtures, testes e documentação em branch/PR de revisão.
