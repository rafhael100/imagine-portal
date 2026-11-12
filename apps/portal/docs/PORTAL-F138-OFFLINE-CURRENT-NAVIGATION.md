# Portal F138 — Indicação da página atual na navegação offline

A prévia exportada já tinha nove páginas HTML e um menu local navegável. Até agora, porém, o menu repetia o mesmo texto em todas as páginas e não indicava a seção corrente para leitor de tela.

F138 adiciona `aria-current="page"` ao único link do menu que aponta para a página efetivamente aberta, com distinção visual (negrito/sublinhado) em todas as sete seções, no painel de revisão e no índice. O menu do cliente Blade continua apenas ilustrativo e sem hiperlinks de operação. A sinalização é produzida no HTML estático, sem JavaScript, rota HTTP, sessão, cookie ou I/O externo.

O verificador `DemoPortalNavigationAudit` exige exatamente **um** link atual por página e verifica que `href` coincide com o nome do HTML em análise. Os testes confirmam os nove estados corretos, e também rejeitam marcador ausente ou apontando para outra página. Os controles de CSP, manifesto SHA-256, `noindex`, navegação local, exportação atômica e arquivos privados continuam ativos.

**Limitações:** isto aprimora orientação na prévia, mas não implementa autenticação, autorização, entrega CRM, Sites, serviço ao cliente, nem auditoria manual WCAG completa. Os conteúdos permanecem estritamente fictícios, sem deploy ou liberação de produção.
