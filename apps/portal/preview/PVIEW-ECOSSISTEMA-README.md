# PVIEW-03 — Prévia offline dos seis projetos Imagine

**Data:** 08/11/2026. **Vínculo:** Issue transversal #77. **Base:** prévia PVIEW-01 do PR #72. **Estado:** demonstração isolada, sem merge/deploy.

## Visualização
Abrir localmente no navegador o arquivo pview-ecossistema-06.html. A tela Visão geral oferece seis cartões de navegação: Portal, Sites, CRM, Performance AI, Universidade e Agentes de IA. As seções auxiliares do Portal permanecem disponíveis no menu.

## Restrições
- HTML/CSS/JS estático autocontido: nenhuma dependência ou conexão externa; CSP com connect-src none.
- Sem login, contas, banco, API, formulário, armazenamento, pagamento, publicação, lead, anúncios, serviços de IA ou dados pessoais reais.
- Todo conteúdo é ilustrativo/fictício. Não equivale a funcionalidades implementadas nos outros cinco produtos, nem a autorização de acesso.
- Não altera o backend do Portal, outras branches, a main, VPS, Cloudflare, serviços ou produção.
- Gates CORE/SEG/AUT, identidade/MFA/RLS/SEC, P0, Gate C e aprovações por competência permanecem abertos.

## Verificação
- Verificação estática executada na criação: 11 destinos de menu únicos = 11 seções = 11 rótulos conhecidos; ausência de chamadas de rede/formulário; CSP presente.
- Teste reproduzível: executar node apps/portal/preview/tests/pview_ecossistema_static_test.mjs na raiz do repositório.
- Não houve execução de Chromium, teste manual, WCAG, integração nem homologação. A etapa de QA em navegador precisa ser executada e documentada separadamente.

## Próxima etapa
Revisar responsividade, navegação por teclado e fidelidade visual em Chrome/Firefox/Safari; manter PR draft até avaliação independente. Não expor rota Laravel autenticada ou alterar o PVIEW-01.