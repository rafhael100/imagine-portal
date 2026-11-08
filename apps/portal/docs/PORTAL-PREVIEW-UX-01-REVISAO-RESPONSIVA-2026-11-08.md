# PORTAL-PREVIEW-UX-01 — Revisão de navegação e responsividade da prévia Blade

**Data:** 08/11/2026. **Estado:** incremento visual/técnico **em branch filha isolada**, autorizado somente para a prévia demonstrativa e CI. **NÃO é homologação de experiência de usuário, acessibilidade profissional, segurança da aplicação, autorização de login, merge ou deploy**.

## Origem, isolamento e dependências

- Branch `dev/portal-preview-ux-responsive-2026-11-08`, filha da HEAD `5d333fdca9a2e1da07e51a91ed2346877a12705c` do [PR #61](https://github.com/rafhael100/imagine-portal/pull/61) (composição Blade), derivado do [PR #59](https://github.com/rafhael100/imagine-portal/pull/59) (HTML estático offline).
- Base do PR deste recorte: `dev/fast-track-portal-2026-11-05` **exclusivamente para CI, em DRAFT/NO MERGE**; o diff acumulado contém #59 e #61. **Não integrar a DEV como atalho para PRs anteriores.**
- `main`, DEV original, todas as PRs preexistentes, rotas, auth, banco, billing, CRM, Performance AI, Universidade, n8n, VPS e produção seguem sem alterações.

## Revisão de código e ajustes

| Ponto analisado | Observação estática antes | Mudança neste recorte |
|---|---|---|
| Barra de navegação em páginas longas | Menu desktop rolava para fora da tela junto à página | Barra lateral `position:sticky` com altura de viewport e rolagem própria no desktop |
| Navegação em telas médias/celulares | Menu quebrava linhas ou rolava horizontalmente sem instruções | Cabeçalho fixo por CSS até tablet, menu horizontal com `overflow-x:auto`, scroll snapping, dica textual de deslizar e `aria-describedby` |
| Alvo de âncora com cabeçalho móvel | Destino de link podia ficar sob menu fixo | `scroll-margin-top` adaptativo e foco de seção `tabindex="-1"` |
| Qual seção está selecionada | Não havia indicação visual de destino de fragmento | Borda na seção `:target` e indicação visual de link por `:has` em navegadores compatíveis, **sem forjar `aria-current` sem estado real** |
| Semântica dos títulos | Seis títulos `h1` em um único documento | Um `h1` principal de página e seis `h2` para seções vinculadas por `aria-labelledby` |
| Telas estreitas | Quatro métricas em duas colunas até o limite mínimo | Uma coluna sob 360px e `overflow-wrap:anywhere` em cartões |
| Movimento e teclado | Já havia suporte parcial | Mantido `prefers-reduced-motion`; foco visível para links e seções |
| Revisão visual reproduzível | Testes de DOM/CSS, mas sem evidência de renderização em navegador | Workflow adicional renderiza a view Blade **offline** e executa Chromium headless em 1440x900, 390x844 e 320x740, gravando PNGs temporários em GitHub Actions (7 dias) |

**Regra:** os destaques `:has/:target` são cosméticos; não são estados de negócio, autenticação ou prova de autorização. A navegação usa somente fragmentos locais (`#pv-*`), nunca URL de área real.

## Testes e limites

- `tests/Feature/PortalPreviewBladeTest.php`: renderização Laravel, seis âncoras, um título principal e seis subtítulos, Escape de XSS, ausência de API, scripts, formulários, sessão/DB e rotas, verificações estruturais de CSS em breakpoints.
- `tests/Visual/PreviewBrowserSmoke.mjs`: Chrome DevTools Protocol local via Node 22 sem pacote terceiro. Abre **somente um snapshot HTML sintético** da view, confere dimensões reais, ausência de overflow horizontal, menu sticky, seis links/seções, ausência de form/script, navegação por `#pv-financeiro` e capturas visuais nos três tamanhos.
- `.github/workflows/portal-preview-browser.yml`: GitHub-hosted runner `ubuntu-24.04`, leitura de repositório, PHP 8.4 com Composer travado, sem segredos, sem banco, sem requests externas da página. Instalação das dependências é apenas no runner descartável, não na VPS. Capturas `imagine-portal-preview-desktop-celular` são artefato efêmero.
- As capturas não comprovam, por si, boas práticas completas de acessibilidade, contraste AA, uso assistivo real nem fluxos interativos autenticados. O resultado final deve ser baseado nos logs concretos da HEAD exata; não declarar verde antecipadamente.
- O navegador Chromium executa em CI sem dados reais. O renderer Laravel usa somente a view `portal.preview.showcase`, sem criar rota no Portal ou iniciar sessão de cliente.

## Exclusões e próximos gates

**Não** implantar essa prévia em site público, integrar URLs reais, ativar login, billing/Asaas, emissão de Pix/boleto, campanha Google/Meta, CRM, dados reais, tenant, autorização de organização, Universidade ou direitos automáticos. A segurança de autorização Portal é tema independente e permanece bloqueada. O CRM mantém a [Issue #27 de revisão SEG/DBA](https://github.com/rafhael100/imagine-crm/issues/27) aberta. Nenhum merge na DEV ou main sem autorização própria.

**Próxima decisão possível após validação:** avaliação visual da Direção dos screenshots, ajustes pontuais de interface, definição de critérios para demonstrar internamente a view em ambiente autorizado. Acesso a clientes e integração autenticada requerem homologação técnica separada.
