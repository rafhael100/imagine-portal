# Portal F129 — Prévia visual da revisão de entregas

Entrega de produto visível para revisão de experiência, sem depender da VPS.

O comando `php artisan portal:demo-export`, já limitado aos ambientes `local` e `testing`, passa a gerar também `delivery-review.html` com um painel de revisão inteiramente fictício. O índice e a navegação das demais telas incluem a nova página, sem criar `ClientSection`, permissão de serviço ou rota autenticada.

A interface apresenta três situações: resultado desconhecido, envio potencialmente iniciado com lease vencido e limite de reservas atingido. Ela explica a necessidade de reconciliação e não possui botões, formulários, scripts ou conexões externas. Os dados vêm exclusivamente de `DemoCommercialDeliveryReview::examples()`, uma lista fixa e sintética sem banco nem CPF/nome/e-mail de clientes.

Os testes passam a exigir nove arquivos HTML exportados, navegação, proibições de rotas/actions, ausência de `http://` e `https://`, e quatro estados de segurança: impossibilidade de operação, reenvio e confirmação de CRM. A PR incorpora a atualização do CI Laravel para validar a prévia.

Não existe painel real, consulta ao PostgreSQL do tenant ou automação de envio. O login e a autorização operacional ainda dependem de fonte de identidade Portal, MFA, RLS, AppSec/DBA independente, LGPD e homologação VPS. O mock não valida um CRM real. Nenhuma alteração em `main`, DNS, produção ou gastos.
