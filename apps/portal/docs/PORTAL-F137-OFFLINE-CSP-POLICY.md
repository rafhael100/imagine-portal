# Portal F137 — Política de conteúdo segura para a demonstração HTML local

A exportação da prévia HTML agora contém uma Content Security Policy inserida por meta em todas as páginas, incluindo o índice, além de `referrer=no-referrer`. A política não permite JavaScript ou conexões de rede, formulário, objeto embutido, fontes remotas ou navegação para recursos externos. Como o protótipo usa estilos CSS inline, `style-src 'unsafe-inline'` permanece necessário e não deve ser tratado como CSP equivalente à recomendada para produção.

A `DemoPortalNavigationAudit` verifica a presença exata da CSP e da diretiva de referrer em todas as nove páginas. Também recusa tags com comportamento ativo (`script`, `form`, `iframe`, `object`, `embed`, `link`, `base`, `input`, `button`, `audio`, `video`, `source`), redirecionamento via `meta refresh` e atributos de eventos `on*`. Imagens, se futuramente adicionadas, precisarão ser dados raster embutidos em base64 e com descrição alternativa; SVG data não é liberado.

Os testes Laravel cobrem remoção da política, introdução de evento inline e `iframe` remoto, além das verificações F135–F136 para manifesto, acessibilidade básica, segurança de links e ausência de arquivos parciais.

**Limites:** uma CSP fornecida dentro do próprio HTML não prova integridade/autenticidade do pacote, e determinadas diretivas como `frame-ancestors` não são suportadas via meta. Não transforma o protótipo em aplicação autenticada nem substitui revisão WCAG/manual, testes de navegador ou auditoria de segurança independente. Integrações/produção continuam fechadas; não há deploy, VPS, domínios, dados reais ou novas despesas.
