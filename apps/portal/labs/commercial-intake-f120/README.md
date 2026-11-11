# Portal F120 — Receptor HTTP real em loopback com assinatura e PostgreSQL 18

## Resultado esperado

A F119 já comprova no PostgreSQL real descartável que uma requisição de serviço com **HMAC correto**, nonce novo, identidade de empresa fixa e envelope técnico válido grava **nonce + submissão + outbox** de forma atômica; após repetição, bloqueia replay. A F120 exercita essa mesma lógica **através de chamadas HTTP reais**, no PHP 8.3/8.4, mas exclusivamente em `127.0.0.1` no GitHub Actions.

**Este não é um endpoint do Laravel, do Portal publicado ou do CRM.** O código permanece restrito a `apps/portal/labs/commercial-intake-f120`. A rota `/__lab/comercial/internal-intake` só existe enquanto o teste executa `php -S 127.0.0.1:19119` e não deve ser adicionada ao servidor público.

## Exercício do fluxo

1. O workflow inicia PostgreSQL 18 temporário, executa os laboratórios F117, F118 e F119, e gera uma chave HMAC aleatória de 48 bytes somente em memória/processo do CI. Não há chaves de fornecedores ou acesso à VPS.
2. O servidor de teste exige o host local, o runner GitHub, a flag F120 e o banco `portal_commercial_lab`. Aceita apenas `POST /__lab/comercial/internal-intake`, `Content-Type: application/json` e corpo até 2 KiB.
3. A chamada passa metadados F78 (oito campos técnicos); cabeçalhos de requisição apresentam emissão, expiração, nonce e MAC, calculado sobre domínio sintético exclusivo (não é o domínio dos recibos do CRM).
4. O PHP confirma validade temporal/assinatura, compara empresa/vínculo/site/form/destino com a configuração **sintética fixa do receptor**, e executa via F119 a gravação atômica no banco e outbox, com RLS.
5. As respostas HTTP não incluem dados do envio, segredo nem qualquer recibo. Mesmo quando o servidor devolve `202 Accepted`, o corpo mantém `crmPersisted:false`, `canAcknowledgeVisitor:false` e `productionAuthorized:false`: significa somente observação de metadados fictícios.
6. Testes em PHP usam requisições HTTP verdadeiras para conferir falta de cabeçalhos, MAC falso, expiração, método/rota errados, tipo de conteúdo inválido, JSON truncado, limite de corpo, contato extra, tenant falso, primeira inserção, replay (HTTP 409), segunda tentativa com nonce diferente e a persistência de exatamente **um envio lógico, uma outbox e dois nonces**.

## Limites rigorosos

Não há formulário público, proteção de visitantes, cookies, usuários reais, consentimento legal, tráfego TLS, autenticação de serviço operacional/KMS, logging central, fila/worker, teste entre hosts, timeout real entre bases ou comunicação com o CRM. O armazenamento usa metadados sintéticos apenas, em PostgreSQL de container descartável, e superusuário do CI apenas para testar papéis RLS. A implementação **não foi preparada para deploy**; copiar o arquivo de roteador como API ativa seria violar as proteções da etapa.

O marco seguinte depende de transformar esse contrato, **mediante revisão SEG/DBA/LGPD e staging privado**, em receptor Laravel autenticado de forma independente, fonte servidora confiável de tenant, persistência criptografada e canais reais de recibo e reconciliação com o CRM.

A F70 do Imagine Comercial continua bloqueando a substituição da captação legada. Sem merge na `main`, DNS, produção, VPS, gastos ou uso de clientes reais.
