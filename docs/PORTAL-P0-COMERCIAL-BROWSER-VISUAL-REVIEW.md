# P0 — Capturas de revisão visual da contratação fictícia (NO-GO)

## Por que esta entrega existe

O teste de contrato Comercial → Portal → Sites → CRM já executa código de ambos os sistemas e bancos PostgreSQL18 descartáveis. Para avaliação de UX, o mesmo navegador Chromium gera um **pacote com seis capturas reais** do Astro compilado e dos formulários Blade Laravel, sem depender de um deploy na VPS.

As capturas ocorrem em `127.0.0.1` no runner GitHub:

| Captura | Tela | Viewport |
| --- | --- | --- |
| `01-comercial-desktop.png` | Página inicial real do Comercial antes de clicar Onepage | 1280 × 900 |
| `02-comercial-mobile.png` | Mesma página Comercial em celular | 390 × 844 |
| `03-portal-choice-desktop.png` | Oferta Onepage destacada no Laravel após o clique | 1280 × 900 |
| `04-portal-choice-mobile.png` | Mesma página Portal em celular | 390 × 844 |
| `05-payment-blocked.png` | Pedido com pagamento **fictício pendente**, sem formulário de briefing | 1280 × 900 |
| `06-briefing-saved.png` | Formulário salvo no PostgreSQL e exibido pelo Portal | 1280 × 900 |

O teste verifica que a página não apresente **rolagem horizontal** nos dois estados mobile. Os identificadores de pedidos em tags `code` são mascarados nas capturas do status e do briefing, evitando exibir IDs temporários de revisão. Todas as informações de exemplo foram criadas pelo laboratório, não importadas de clientes.

## Como revisar

Abra a execução GitHub Actions da PR visual e procure o artefato `imagine-p0-comercial-portal-visual-browser-review`, disponível por sete dias. Ele contém as seis imagens e `manifest.json` com comprimento de cada PNG e SHA256 individual. O workflow recusa artefatos incompletos, conteúdo em formato inválido ou hashes divergentes.

São capturas dos HTML e formulários efetivamente renderizados pelo navegador de teste, **não mockups produzidos por IA**, mas nenhum ambiente público foi colocado no ar. Um screenshot só evidencia aparência; o checkout continua fictício e sem login oficial.

## Gates antes de permitir acesso de cliente

Faltam identidade MFA + organização/ownership duráveis, fence de revogação anti-rollback, serviços Comercial e Portal publicados sob autorização privada, mapeamento de preço/contrato/PSP, implementação de Sites e captação CRM com consentimento. Este artefato NÃO autoriza ingressos no Sites, exportação de leads, criação de clientes ou deploy.

**Status: somente visual review / NO-GO. Nenhuma alteração de VPS, DNS, banco de produção ou captura de PII.**
