# PORTAL-F46 — Gate que impede publicar aprovações fictícias pela aplicação real

**09/11/2026 · PHPUnit Feature no Laravel · sem PostgreSQL adicional.**

As etapas F42–F45 prepararam um briefing, um conteúdo
versionado, uma decisão editorial de laboratório e uma
verificação SQL de sua consistência. Nenhuma dessas
etapas representa aprovação autenticada do cliente.

O teste `PortalSiteReviewOperationalBoundaryTest.php`
impõe uma **barreira negativa de regressão** para evitar
que desenvolvimentos futuros exponham por engano
esses módulos não homologados.

## Cobertura

- Varredura de pontos de entrada PHP/JSON em `routes`,
  `bootstrap`, `config`, `app/Providers`,
  `app/Http`, `app/Console`, `app/Jobs`,
  `app/Listeners`, `app/Observers` e
  `database/{migrations,seeders}`, além de
  `public/index.php` e `composer.json`.
  Falha se classes F44/F45, comandos de revisão,
  tabelas f44 ou flags de execução aparecerem
  no código de inicialização operacional.
- URLs GET/POST de aprovação, revisão, publicação,
  prévia de site e liberação nos prefixos
  `/portal`, `/api/portal` e `/labs`
  devem permanecer em **404**, inclusive com
  payload que finge ser `DEMO-REVIEWER-A`.
- Retornos positivos `approved_demo` e
  `consistent_approved_demo` de F43–F45
  continuam obrigatoriamente com métodos
  `mayPublishSite/authorizesPublishing=false`,
  `isVerifiedClientApproval=false` e
  nenhuma autorização de contrato ou cobrança.
- Mesmo o candidato Onepage/Landing Page gerado
  em memória não pode ser enviado, publicado
  ou cobrado a partir destes objetos.

## Limites

Esta varredura cobre apenas os arquivos e URLs
conhecidos atualmente; não é prova formal de
não alcançabilidade sob reflexão, extensão de
framework, provedores externos, novas rotas
dinâmicas ou novas bibliotecas. Nenhuma autenticação
de cliente, assinatura, aprovação documentada real,
consentimento LGPD, publicação via Cloudflare,
contratação ou integração com Imagine Sites existe
neste estágio.

**PR DRAFT/SEM MERGE/DEPLOY, nenhuma alteração em
VPS Leaseweb, contas externas ou dados de clientes.
P0/D02/D03, SEC-R03/Z4, T3/OPEN, AppSec/DBA e IAM/MFA
permanecem pendentes.**
