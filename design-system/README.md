# Identidade visual Imagine — contrato canônico v1

**Origem verificada (12/11/2026):** primeira implementação do Imagine Portal em Node.js, com arquivos `portal.css`, `portal.html` e logotipo real `assets/logo-imagine.png` na raiz do repositório. A aprovação da Direção nesta data é **preservar a identidade desta versão Node** na migração para Laravel e padronizar os demais produtos do ecossistema Imagine.

## Fonte de verdade e precedência

1. **Referência visual:** CSS e logotipo da versão Node (não migrar o servidor Node/SQLite para produção).
2. **Tokens compartilhados:** `design-system/tokens.css` deste repositório.
3. **Implementação Laravel:** `apps/portal/resources/views/components/portal/styles.blade.php` com aliases `--pi-*` equivalentes.
4. As prévias experimentais PVIEW-01/02/03 e outras que usam azul/verde são evidências de UX/fluxos, **não substituem o padrão de marca laranja/grafite/branco**. Migrá-las em entregas isoladas; não realizar troca forçada de backend.
5. Não tratar o uso de uma identidade comum como autorização para autenticação única, API, autorização entre empresas, pagamentos, deploy ou merge.

## Padrão de marca

| Papel | Token | Hex |
| --- | --- | --- |
| Destaque principal | `--imagine-orange` | `#F9941D` |
| Texto escuro / hero | `--imagine-ink` | `#171B19` |
| Superfície de cartões / menu | `--imagine-white` | `#FFFFFF` |
| Plano de fundo | `--imagine-surface` | `#F7F7F8` |
| Texto secundário | `--imagine-muted` | `#737373` |
| Bordas | `--imagine-border` | `#E8E8E8` |
| Navegação selecionada | `--imagine-orange-soft` | `#FFF1E0` |
| Texto em laranja escuro para legibilidade | `--imagine-orange-text` | `#9B4D00` |
| Foco visível | `--imagine-focus` | `#B75C00` |

### Estrutura visual

- **Marca:** usar o logotipo Imagine original, `assets/logo-imagine.png`, migrado como asset local para o frontend de cada app quando apropriado; não inventar logo ou introduzir gradiente azul/roxo.
- **Logotipo oficial aplicado no Portal Laravel:** `apps/portal/public/assets/logo-imagine.png`, uma cópia **idêntica em bytes** do original (Git blob `678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77`), referenciada pelo componente `x-portal.brand` via caminho local `/assets/logo-imagine.png`. A CSP da demonstração permite apenas imagem local (`img-src 'self' data:`), sem recursos de terceiros. A versão Node permanece intocada.
- **Outros produtos:** replicar o mesmo arquivo PNG original e a grafia da marca sem redesenhos; usar a versão original em cor para fundos claros. A aplicação exige QA de contraste e dimensões por produto.
- **Desktop:** menu lateral branco (cerca de 240px), item selecionado em laranja suave, fundo geral cinza bem claro, cabeçalho branco com borda fina, cartões brancos e bordas discretas.
- **Indicadores e ações:** destaques em laranja; para texto pequeno, usar grafite ou `--imagine-orange-text` sobre fundos claros; não usar `#F9941D` para texto pequeno em branco.
- **Tipografia base do Portal legado:** Arial / Helvetica / sans-serif, com títulos fortes, espaçamento consistente, botões com cantos discretos; outras fontes só mediante decisão de atualização **global**.
- **Mobile/tablet:** menu adaptativo, controles de toque e tabelas com rolagem segura; não perder a identidade nas quebras de layout.
- **Acessibilidade:** contraste de estados e texto, foco visível, redução de movimento, rótulos legíveis e QA responsivo; cores semânticas de alerta, sucesso e erro permanecem independentes da cor de marca.

## UX e layout de todas as páginas

Além das cores e da logomarca, a Direção aprovou **menus elegantes, páginas modernas, leves, visualmente claras e fáceis de navegar**. Os requisitos verificáveis de navegação, hierarquia, componentes, espaçamento, responsividade, acessibilidade e critérios de pronto por tela estão em [experience-system.md](experience-system.md). Aplicar a cada tela real, sem confundir uma issue com código implementado.

## Aplicação aos produtos

O Portal será a **referência visual central**. Todos os módulos com interface humana (CRM, Sites, Comercial, Performance AI, Universidade, Agentes de IA e SEO Engine) devem usar os mesmos tokens, navegação e linguagem de componentes, com adaptações funcionais:

- **CRM:** login independente, interface Kanban própria, sem SSO obrigatório.
- **Sites:** painel de produção/publicação pode ter mesmo shell; sites/landing pages entregues a clientes não precisam repetir a identidade da Imagine.
- **Comercial:** funil, propostas e contratos com mesmo conjunto de cartões, botões, estados e menus.
- **Performance AI:** painéis e relatórios consistentes sem copiar regras de outros motores.
- **Universidade:** interface alinhada, mantendo condição de benefício por serviço elegível ativo.
- **Agentes de IA e SEO Engine:** interfaces operacionais, quando existirem, usam o padrão; saídas exportáveis para clientes preservam marca/regras próprias.

## Política de migração e validação

1. Mapear telas e componentes em cada repositório antes de mudar CSS.
2. Introduzir aliases locais dos tokens e migrar shell / menu / cabeçalho / cartões / botões sem alterar contratos de domínio.
3. Validar desktop/tablet/mobile e acessibilidade; conferir logotipo real. Preservar funcionalidades, tests e permissões.
4. Abrir PR por produto, sem deploy/merge automático. A aprovação visual **não** elimina os gates de segurança, tenancy, autenticação, CI ou release.

**Estado nesta entrega:** definido contrato + alinhamento das cores/elementos da interface demonstrativa Laravel em branch isolada. Nem todos os protótipos anteriores ou demais produtos estão migrados; acompanhar por PRs/issues específicas.
