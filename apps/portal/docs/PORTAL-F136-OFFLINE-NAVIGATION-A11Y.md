# Portal F136 — Verificador de navegação e estrutura semântica da prévia interna

A F135 passou a validar o inventário e os hashes das nove páginas HTML internas. Isso ainda não garantia que um link de revisão apontava para página existente ou que a navegação por teclado continha um destino válido.

A F136 introduz `DemoPortalNavigationAudit` (PHP DOMDocument com `LIBXML_NONET`) e exige, antes de publicar o diretório de demonstração via `rename`:

- HTML em `pt-BR`, uma tag `h1` e um marco `main id="pi-content"` por página;
- `meta robots=noindex,nofollow`, navegação visível e link `#pi-content` para saltar ao conteúdo;
- links exclusivamente para os nove nomes HTML locais da prévia ou para `#pi-content`; sem `http(s)`, `javascript:`, `mailto:`, paths relativos com `../`, querystrings ou destinos externos;
- link de retorno ao índice em cada página interna.

O índice offline passa a ter landmark `main` e o mesmo skip-link. A validação é executada depois do render Blade e **antes** do manifesto F135 e do `rename` final. O comando `portal:demo-verify` valida tanto os hashes quanto as regras de navegação, sempre em `local/testing`, sem aceitar URL ou caminho do operador.

Testes Laravel incluem navegação íntegra, caminho relativo de fuga, falta do skip-link e remoção da diretiva de não indexação.

**Limites:** estas são regras estruturais automatizadas, **não** uma auditoria WCAG completa ou teste com tecnologias assistivas. A apresentação é somente demo, sem autenticação, API, pagamento, CRM, dados reais, deploy ou acesso público. Revisão independente e homologação operacional continuam abertas.
