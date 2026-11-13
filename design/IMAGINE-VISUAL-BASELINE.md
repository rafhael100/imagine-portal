# Imagine — padrão visual original do ecossistema (baseline 2026-11-12)

**Fonte aprovada:** primeira versão do Portal Imagine em Node.js, preservada em `main/portal.css`, `main/portal.html` e `main/assets/logo-imagine.png` do repositório `rafhael100/imagine-portal`. **Não utilizar a identidade provisória azul adotada em alguns protótipos Laravel.** Preservar a identidade como base, sem redesenhar a marca.

## Marca oficial e integridade

- Original: `main/assets/logo-imagine.png`, PNG de **5.594 bytes**, Git blob SHA-1 `678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77`.
- Laravel demo: **o mesmo blob**, copiado para `apps/portal/public/assets/logo-imagine.png`; a imagem é servida pela própria aplicação, sem buscar logo em CDN, GitHub ou outro fornecedor durante a navegação.
- A marca deve ter descrição alternativa `Imagine Comunicação` e ser visível sobre o fundo branco. Não substituir por letras geradas ou SVG/arte reinventada; nunca misturar logomarcas de clientes com a identidade do Portal.
- Teste: calcular `sha1('blob '.strlen($png)."\\0".$png)` e comparar com o SHA imutável acima; também verificar PNG válido.

## Design tokens canônicos

| Papel | Cor | Aplicação |
| --- | --- | --- |
| Laranja Imagine | `#F9941D` | destaque de marca, barra de navegação ativa, progresso e elementos decorativos |
| Grafite | `#171B19` | títulos, textos fortes e tema do navegador |
| Fundo claro | `#F7F7F8` | área de conteúdo e respiro da interface |
| Branco | `#FFFFFF` | sidebar, painéis e superfície principal |
| Linha neutra | `#E8E8E8` | contornos discretos e separadores |
| Laranja suave | `#FFF1E0` | estado selecionado e informação de marca |
| Laranja escuro | `#994B00` | links/foco/textos sobre superfícies claras, para contraste |

**Não usar `#F9941D` como texto pequeno diretamente sobre branco**: aplicar o tom escuro para manter leitura. Estados de sucesso/erro podem conservar seus tons semânticos, desde que contraste e significado sejam claros.

## Composição e navegação

- Interface leve e elegante: cantos de raio moderado, painéis brancos sem excesso de sombras, margens generosas, tipografia legível, títulos diretos, sem sobrecarregar os usuários.
- Desktop: sidebar branca compacta (referência `main/portal.css`), logomarca no alto, grupos de páginas bem identificados, estado ativo com laranja suave e filete laranja; conteúdo principal fluido.
- Tablet: sidebar adaptada ao topo, grades de duas colunas quando couber.
- Celular: logomarca legível, **menu demonstrativo em faixa horizontal rolável**, evitando sete linhas de navegação antes do conteúdo; cartões e tabelas responsivos, tabelas com scroll acessível pelo teclado.
- Foco de teclado visível, link “Pular para o conteúdo”, labels/títulos claros, alto contraste, tabelas com `caption`, `scope="col"` e regiões `tabindex="0"`.
- No protótipo interno sem rotas reais, itens podem ser exclusivamente ilustrativos e devem dizer isso expressamente. Quando houver rotas autenticadas aprovadas, substituir por links sem perder segurança, navegação por teclado ou isolamento por empresa.

## Aplicação pelos demais produtos

**Diretriz para implementação futura**, não certificação de que já foi concluída: Imagine Sites, CRM, Performance AI, Comercial, SEO, AI Agents e Universidade devem usar o mesmo DNA visual (logomarca oficial, laranja/grafite, espaços claros e navegação leve), com menus e necessidades específicas de cada sistema. Reutilizar valores e padrões de interface, **sem unificar credenciais/sessões por aparência**, nem transferir acesso/entitlements entre serviços.

Cada repositório deve ter sua própria PR, testes de acessibilidade, revisão visual real nas larguras móveis e desktop e aprovação operacional. Adoção visual não autoriza deploy, contrato, pagamentos nem publicação de sites.

## Segurança do protótipo

A implementação Laravel desta PR é apenas a interface demonstrativa com dados sintéticos, sem autenticação operacional. CSP só permite imagem da **mesma origem** para o PNG oficial; scripts, formulários e conexões de rede continuam negados. Revisão AppSec/DBA, QA visual humano, anti-rollback #64, integração #341 e aprovação específica de merge/deploy continuam bloqueantes.
