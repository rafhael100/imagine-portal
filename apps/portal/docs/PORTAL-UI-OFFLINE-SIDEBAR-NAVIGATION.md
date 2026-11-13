# Imagine Portal — navegação lateral funcional na prévia offline

## Entrega

Na interface Laravel demonstrativa, o menu lateral permanece **não clicável**: sem sessão autenticada, não existem rotas de Portal abertas ao público. Cada item possui um identificador de seção conhecido, mas é renderizado como `<span>`, sem `href`, execução ou ação.

Durante a **exportação offline** da prévia, o `PortablePortalStaticPreview` agora transforma **exatamente oito itens de menu** em links locais `*.html`, validados contra a enum `ClientSection` e o inventário do ZIP. O processo recusa falta, repetição ou destino inesperado; não inclui URLs remotas, formulários, scripts ou permissões de negócio. O aviso de navegação é atualizado apenas no arquivo exportado para explicar que se trata de páginas locais.

Os links preservam o estilo claro da barra lateral, a cor laranja original `#F9941D`, o logo PNG oficial e o destaque `aria-current="page"` da seção. CSS acrescentado mantém foco visível e alto contraste; no mobile o menu horizontal recebe foco pelo teclado e rola até o último item. O menu superior de prévia permanece como alternativa, e ajuda/avisos não recebem um `aria-current` falso.

## Validação

`PortalOfflineSidebarNavigationTest` verifica que as views Laravel cruas seguem sem links/rotas, mas que cada uma das dez páginas offline (oito seções + Ajuda/Avisos) inclui oito destinos locais exclusivos e legíveis por teclado; o Índice é separado. A QA Chromium de **11 páginas × 7 larguras** valida a existência dos links, a seção selecionada correta, o foco e o deslocamento lateral em 320px, além das tabelas roláveis, ausência de overflow e integridade dos hashes de manifesto.

**A prévia offline não concede acesso a clientes**, não faz login, não abre nenhuma rota Laravel, não cadastra solicitações e não permite compra, publicação ou assinatura. A navegação operacional de verdade será integrada a autorização first-party + RLS após resolver a issue #64, aprovação AppSec/DBA e homologação. Nenhuma alteração de `dev`, `main`, VPS ou terceiros nesta PR.
