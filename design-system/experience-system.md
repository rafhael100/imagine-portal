# Imagine Experience System — UX e layout de todas as páginas (v1)

**Diretriz de produto aprovada em 12/11/2026:** manter a marca Imagine original de Node.js (logomarca real e cores) e apresentar **menus e páginas elegantes, modernos, leves, muito legíveis e fáceis de navegar**. Este documento orienta todas as interfaces humanas do Portal, CRM, Sites (painel), Comercial, Performance AI, Universidade, Agentes de IA e SEO Engine. O Design System visual canônico permanece em [README.md](README.md).

## Princípio único: clareza antes de decoração

- A interface deve ajudar a pessoa a responder: **onde estou**, **o que vejo**, **o que preciso fazer** e **qual o resultado**.
- Manter telas arejadas e consistentes: branco/cinza claro para superfícies, grafite para leitura, laranja Imagine somente para destaque e ações importantes; sem degradês ou excesso de efeitos como identidade principal.
- Usar **a logomarca original PNG** no cabeçalho/menu da aplicação; não improvisar marcas nem misturar linguagens gráficas. Texto de suporte pode identificar o produto (Portal, CRM etc.).
- Menos quadros e dados por tela; agrupar conteúdo por tarefa e revelar informação avançada conforme necessidade, sem esconder ações essenciais.
- Nunca sacrificar leitura, contraste, acessibilidade ou produtividade para tornar a tela “bonita”.

## Estrutura de cada página (ordem recomendada)

1. **Navegação persistente:** logo real, título do produto, menu agrupado por tarefas, indicação inconfundível da seção ativa. Mostrar só itens que o perfil pode acessar.
2. **Contexto:** breadcrumb conciso quando houver profundidade; nome da organização/conta sem confundir identidades ou empresas; título claro de página.
3. **Cabeçalho de conteúdo:** uma frase de contexto, uma ação principal quando houver; secundárias discretas e coerentes. Não apresentar ação sem funcionalidade/permissão.
4. **Resumo opcional:** 2–4 indicadores úteis, com rótulos e unidade, sem criar uma linha de métricas vazia em toda tela.
5. **Área de trabalho:** cartões, tabela, quadro Kanban, editor ou conteúdo didático, conforme tarefa. Respeitar hierarquia título → informações → ação/estado.
6. **Estados e feedback:** carregando, vazio, indisponível, erro, sucesso e aguardando; textos objetivos e próximo passo. Não usar apenas cor para comunicar estado.
7. **Ajuda contextual:** documentação curta ou orientação acessível onde houver decisão complexa; evitar rodapés e banners gigantes.

## Navegação e menus

- **Desktop:** shell consistente, menu lateral claro de ~240–250 px; itens com rótulos objetivos e grupos não excessivos; item ativo com fundo `#FFF1E0`, texto escuro e detalhe laranja; cabeçalho e conteúdo alinhados.
- **Tablet:** reduzir colunas e densidade; quando menu horizontal oferecer rolagem visível e feedback de seção atual; não truncar textos importantes.
- **Mobile:** preservar o logo, o título de cada tela e acesso a todas as áreas; menus compactos ou roláveis, sem depender apenas de ícones; alvos interativos com pelo menos 44×44 px.
- Se a aplicação tiver muitos módulos, agrupar por intenção (ex.: Operação, Resultados, Gestão, Configurações); evitar submenus de três ou mais níveis e evitar itens duplicados.
- Menus reais usam links/botões semânticos e `aria-current="page"`; protótipos sem rotas podem permanecer com rótulos não clicáveis, claramente demonstrativos.
- Breadcrumbs devem refletir o caminho real; preservar busca e filtros quando listas se tornarem longas. Não inserir botões que não funcionam.

## Design visual e componentes

- **Tipografia:** Arial/Helvetica/sans-serif da versão original até decisão global; texto principal 15–16 px e espaçamento generoso; títulos fortes, subtítulos concisos e largura confortável para leitura.
- **Espaçamento:** sistema 4/8/12/16/24/32/48 px. Entre seções, preferir 24–32 px; dentro de cartões, 16–24 px. Evitar blocos colados e vazios excessivos.
- **Superfícies:** fundo `#F7F7F8`, cartões brancos, borda `#E8E8E8`, sombra quase imperceptível e cantos suaves (~10–14 px); sem sombras pesadas, gradientes abundantes ou decoração desnecessária.
- **Ações:** no máximo uma ação primária por seção principal, cor laranja `#F9941D` com texto grafite. Links e ações secundárias discretos; ações destrutivas e estados semânticos usam cores próprias e confirmação adequada.
- **Tabelas:** cabeçalho legível, alinhamento de unidades, filtros pertinentes, vazios informativos, overflow horizontal em telas pequenas; paginação ou virtualização conforme volume real.
- **Kanban:** colunas legíveis, densidade controlada e deslocamento acessível; manter função própria do CRM, sem forçar layout de cards genéricos.
- **Formulários:** labels persistentes, validação próxima ao campo, erros úteis e botões de enviar claros; suporte a teclado e mensagens de sucesso/erro sem falsa confirmação.
- **Cartões e indicadores:** só informação que melhora decisão, títulos e contexto visíveis, números com unidade e origem; não inventar dados nem gráficos no frontend real.
- **Interações:** feedback de hover/foco onde a superfície realmente é clicável; transições discretas, respeitar `prefers-reduced-motion`; jamais animar por decoração em fluxos críticos.

## Acessibilidade e desempenho percebido

- Teclado completo, ordem de foco lógica, link para conteúdo e `focus-visible` evidente. Rótulos e `aria-current` em menus; status não depende apenas de cor.
- Contraste de texto normal ao menos 4,5:1 (WCAG AA), salvo exceções normativas; texto pequeno não deve usar laranja claro contra branco.
- QA em 360, 768 e 1440 px como referência inicial (além de zoom 200% e dispositivos reais); evitar rolagem horizontal da página fora de componentes que a exigem.
- Carregar logomarca local, otimizar assets, evitar fontes remotas desnecessárias; estados de carregamento sem saltos de layout e feedback imediato de ações reais.
- A consistência deve abranger **todas as páginas e estados**, incluindo login, vazio, erro, lista, detalhe, criação/edição, configurações, ajuda e versão mobile, quando essas páginas existirem no produto.

## Adaptações sem descaracterização

| Produto | Páginas/fluxos que precisam deste padrão | Particularidade preservada |
| --- | --- | --- |
| Imagine Portal | visão geral, serviços, solicitações, contratos, desempenho, financeiro, ajuda e avisos | identidade e permissões de organizações; Laravel independente |
| Imagine CRM | login, visão geral, leads, contatos, funis/Kanban, automações, relatórios, configurações | login próprio, multiempresa, permissões |
| Imagine Sites | intake/briefing, editor, revisões, aprovação, publicação, histórico | sites finais dos clientes mantêm identidade própria |
| Imagine Comercial | catálogo, contatos, pipeline, propostas, contratos, painel e acompanhamento | separação de site comercial público e operação interna |
| Imagine Performance AI | onboarding, campanhas, canais, orçamento, criativos, relatórios, aprovações | motor técnico separado do Portal |
| Universidade Imagine | catálogo de cursos, trilhas, aula, materiais, busca e tutor | benefício exclusivo de serviços elegíveis; foco em leitura/aprendizado |
| Imagine AI Agents | agentes, tarefas, permissões, aprovações, cotas, auditoria | segurança e operações existentes; só aplicar a telas humanas |
| Imagine SEO Engine | projetos, auditoria, oportunidades, palavras-chave, relatórios, evolução | dados auditáveis; relatórios de cliente respeitam marca própria |

## Critério de pronto por tela (obrigatório antes de concluir migração)

- [ ] Logomarca original usada corretamente (quando houver shell de marca) e paleta fiel ao `design-system/tokens.css`.
- [ ] Menu e título identificam posição atual, sem rotas falsas; ações principais claras.
- [ ] Layout limpo, sem ruído visual, com hierarquia e espaços coerentes; sem esconder conteúdo essencial.
- [ ] Revisados todos os estados (vazio, erro, carregamento, sucesso, permissão negada quando aplicável).
- [ ] Responsivo em desktop, tablet, celular e zoom 200%; toque/teclado/contraste considerados.
- [ ] Funcionalidades e separações de segurança/tenancy permanecem iguais; testes de produto passam.
- [ ] Mudanças mostradas em PR de revisão com capturas comparativas; nada de merge/deploy automático.

**Implementação incremental:** a base Laravel do Portal recebeu melhorias no shell e no menu neste PR; demais páginas/produtos exigem implementação própria e validação visual. Uma issue de acompanhamento não comprova tela migrada.
