# PVIEW-01 — primeira prévia navegável offline do Imagine Portal

**Estado:** demonstração isolada, não aplicativo operacional, não homologada para clientes.
**Autorização:** aprovação da Direção em 08/11/2026 ao reequilíbrio da arquitetura para obter rapidamente um Portal navegável e seguro sem descartar trabalho validado. Branch de UI independente, criada do SHA `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8` de `dev/fast-track-portal-2026-11-05`.

## Visualização

Abrir o arquivo `pview-01.html` **localmente no navegador**. Os links da barra lateral e de cards navegam via fragmentos `#` entre 8 seções de demonstração: Visão geral, Meus serviços, Serviços disponíveis, Solicitações, Contratos, Performance AI, Universidade e Avisos.

**Esta página é totalmente estática e autocontida:** HTML/CSS/JS locais, sem dependências, web fonts, storage, cookies, API, chamadas de rede, bancos, login, Google/Meta, CRM ou PSP. CSP limita `connect-src 'none'`. Não há formulário ou contratação/cobrança. Nenhuma informação exibida é real: todos os dados e a Empresa Aurora são fictícios. Esta prévia não configura rotas Laravel, serviços, sessões, memberships ou direitos de acesso.

## Verificações necessárias

- HTML válido com um `main`, títulos e navegação semântica; links de hash conhecidos, título e `aria-current` atualizados.
- Acesso pelo teclado, skip link, foco, layout responsivo em 390/768/1440 e ausência de overflow ou navegação quebrada.
- Zero HTTP fetch, XMLHttpRequest, WebSocket, `form`, `localStorage`, fonte externa ou `iframe`; sem efetuar chamadas de serviço.
- Nenhum status de assinatura real nem permissão inferida da tela ou do hash.
- O teste Laravel existente `PortalAuthenticationRoutesClosedTest` deve continuar impondo 404 para rotas privadas; não expor PVIEW por `routes/web.php`.
- Não declarar testes PASS sem executar verificação e obter evidência; CI Github pode executar testes gerais na branch, porém não valida a UX sem teste visual/teclado.

## Escopo e limites

Preservadas a raiz legada, `apps/portal/app`, `apps/portal/routes`, `composer.lock`, PRs #51/#67/#69, dev, main e VPS. O mock **não deve ser servido como área privada real**, nem coletar informação. Converter em produto dependerá de AUTH-01, DB-01, CLIENT-01 e gates P0/D02/D03. O PVIEW-01 será revisado por PR draft sem merge automático.

**Próxima entrega candidata:** validar a navegação e preparar uma integração futura da apresentação Blade **somente após** decidir quais componentes compor, sem liberar login, checkout ou persistência por consequência.
