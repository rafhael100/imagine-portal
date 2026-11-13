# Portal Imagine — preparação dos serviços (tela 8, prévia offline 11 páginas)

O Portal ganhou a seção **Preparação dos serviços**, posicionada no menu entre "Meus serviços Imagine" e "Serviços disponíveis". Ela organiza requisitos de projeto para Onepage, Landing Page e Performance AI, com as etapas e referências dos mesmos dados consistentes de serviços/solicitações/contratos do painel. A seção evita percentuais de conclusão falsos, datas inventadas ou botões operacionais sem autenticação.

## Experiência e escopo
- Onepage: roteiro, conteúdo, identidade visual e revisão; domínio e publicação permanecem não configurados.
- Landing Page: objetivo de conversão, texto, formulário e consentimento; sem captação real.
- Performance AI: métricas, contas Google Ads/Meta, criativos por peça e orçamento; sem conectar ou investir em mídia.
- CRM Imagine: login, identidade e sessão próprios, sem SSO.
- Universidade Imagine: benefício elegível apenas com serviço ativo, sem produto de venda avulso.

`DemoServicePreparation` reutiliza a atividade reconciliada de `DemoActivityFeed` (PR #622); se referências, tenant ou status estiverem incorretos, a tela apresenta um estado seguro em vez de dados parciais. A apresentação permanece totalmente fictícia, usando logo original Imagine, paleta `#F9941D`/`#171B19`, HTML responsivo, tabelas com legendas e região rolável focável, sem scripts externos, formulários, login ou publicação.

O exportador offline inclui agora **11 páginas HTML** (oito seções, Ajuda, Avisos e Índice) e lista a nova tela no menu local. A QA usa **77 visualizações** (11 páginas x 7 larguras) em Chromium; exige **10 testes de menu mobile**, **11 navegações offline** e **8 tabelas com scroll via teclado**. Evidências são geradas na CI sem dados privados ou navegação a rede.

**Sem endpoint de cliente nem dados reais.** Um próximo incremento operacional exigirá autenticação/RLS/isolamento por tenant, aprovação de entrega de convite/SMTP, segurança de revogação #64, auditoria AppSec/DBA e homologação. PR DRAFT, sem merge, deploy ou VPS.
