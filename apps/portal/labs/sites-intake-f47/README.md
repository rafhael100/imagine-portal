# PORTAL-F47 — Visualização editorial de Onepage e Landing Page no Portal

**09/11/2026 · PHP/Laravel Blade · etapa funcional de UI, dados totalmente fictícios.**

## O que agora aparece na prévia do Portal

A tela existente `portal.demo.requests`, antes limitada à fila de
solicitações, passou a exibir também a seção
**Onepage e Landing Page — briefing e revisão de conteúdo fictícios**.

O quadro reutiliza o componente acessível `x-portal.table` e mostra
referência DEMO, produto do catálogo, título ilustrativo do conteúdo,
revisão do briefing, revisão de conteúdo e situação editorial fictícia.

Dois exemplos são construídos pela classe
`App\Presentation\DemoSitesEditorialPreview`:

1. Onepage de demonstração, briefing versão 1 e conteúdo versão 2,
   cujo diário F43 registra a decisão `approved_demo` em memória,
   exibida como **Aprovação simulada — não é aceite do cliente**.
2. Landing Page separada, briefing versão 0 e conteúdo versão 1,
   exibida como **Aguardando revisão fictícia**.

A apresentação reaproveita os objetos de domínio F42/F43 e, assim,
não inventa um segundo catálogo, uma nova API de permissões nem
um suposto aceite comercial. A seção descreve que toda mudança de
briefing ou conteúdo exige nova revisão, preservando os gates F44/F45.

## Cobertura

`PortalSitesEditorialPreviewTest.php` acrescenta cinco testes
Feature Laravel sobre o **HTML renderizado** da tela:

- exibição das duas propostas com referências, produtos, títulos,
  versões e estados editoriais;
- objetos tipados, referências estritamente DEMO e métodos
  de publicação/cobrança permanentemente negados;
- escape HTML contra conteúdo malicioso nas células da tabela;
- invariância das rotas Laravel, ausência de formulário, input,
  botão, endpoint externo, checkout, sessão iniciada ou backend DB;
- resultados e HTML determinísticos em renderizações repetidas.

## Limites

O painel é uma prévia **somente leitura**, sem form,
controller, login de cliente, IAM/MFA, assinatura,
publicação, conexão ao Imagine Sites, hospedagem,
analytics, cobrança, PDF ou ferramenta de conteúdo IA.
Não apresenta dados reais e não grava qualquer informação.
O status `approved_demo` é um exemplo editorial em memória,
não consentimento ou aprovação válida.

**PR DRAFT, sem merge/deploy.** Não altera VPS Leaseweb,
Cloudflare, repositório do motor Sites, clientes ou
fornecedores pagos. P0/D02/D03, SEC-R03/Z4,
T3/OPEN e revisão AppSec/DBA continuam pendentes.
