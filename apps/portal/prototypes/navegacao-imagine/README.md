# Imagine Portal — Prévia navegável, offline e sem integrações (08/11/2026)

**Objetivo:** permitir à Direção avaliar a organização visual e a navegação do futuro Portal enquanto a homologação SEG/DBA do CRM acontece em paralelo. **Isto NÃO é Portal liberado, protótipo autenticado, integração funcional ou deploy.**

## Como visualizar

Abra o arquivo `apps/portal/prototypes/navegacao-imagine/index.html` em um navegador no computador, sem servidor, banco ou credenciais. Navegue pelo menu lateral/superior ou use `#inicio`, `#servicos`, `#performance`, `#solicitacoes`, `#universidade` e `#financeiro` após o nome do arquivo. A prévia usa apenas HTML/CSS/JS embutidos, não requer npm, Composer, conexão de internet ou instalação.

**Áreas ilustrativas:** visão geral, serviços Imagine, painel Performance AI, solicitações, Universidade e financeiro. Empresa Aurora, números, campanhas, solicitações e histórico são 100% fictícios. Nenhum estado comprova serviço ativo, contrato, anúncio, lead, elegibilidade ou pagamento.

## Limites e decisões preservados

- A prévia é **um arquivo estático separado**, não tem rotas de cliente no Laravel. O arquivo não deve ser usado como autorização para criar login, SSO, permissões, banco, RLS, cobrança, boleto, Pix, anúncios Google/Meta, conexão ao CRM ou à Universidade.
- Não altera `apps/portal/routes/web.php`, domínio de identidade/autorização, Blade das POCs existentes, `main`, DEV original nem projetos CRM/Performance/Agents/Universidade.
- **Performance AI** tem domínio motor técnico separado do Portal; a interface aqui é apenas demonstrativa. A denominação comercial de canais é **Google Ads + Meta (Instagram e/ou Facebook)**, não um produto chamado “Ambos”.
- **CRM** continua produto e autenticação independentes; nenhum SSO é implícito.
- **Universidade Imagine** é benefício condicionado a serviço elegível ativo, não vendida isoladamente; sem provas, notas ou certificações.
- **Sites** Onepage e Landing Page com contratos próprios; nenhum pedido público é ativado por esta prévia.
- **Financeiro** não contém preços, faturas ou processamento; nenhuma cobrança real, dados pessoais, logs, tracking, storage, formulários ou chamadas HTTP.
- CSP local proíbe conexões externas `connect-src 'none'`, formulários `form-action 'none'`, imagens e recursos remotos, embora `style-src/script-src 'unsafe-inline'` sejam usados somente neste arquivo autocontido offline e **NÃO** constituam recomendação CSP de produção.
- Esta prévia não demonstra interação com usuários autenticados ou sessão. A integração futura com o Portal real é decisão técnica separada e depende de revisão de permissões.

## Testes CI

`node --test apps/portal/prototypes/navegacao-imagine/preview.test.mjs` usa apenas Node.js 22, sem dependências externas. Confere seis rotas de fragmento, rótulos fictícios, ausência de APIs e formulários, CSP e elementos semânticos responsivos. Workflow isolado `.github/workflows/portal-preview-offline.yml` executa somente em PR para DEV, sem secrets e sem deploy. Os testes são **estáticos**; não substituem inspeção de navegador, revisão de acessibilidade ou homologação técnica.

**Branch:** `dev/portal-ui-preview-isolada-2026-11-08` derivada do SHA `47514f4eac64a3d7ab0a0924cc890fb5a124d8b8` de `dev/fast-track-portal-2026-11-05`.

**Governança:** PR DRAFT/NO MERGE. O SEG/DBA do CRM continua na [Issue #27](https://github.com/rafhael100/imagine-crm/issues/27); as PRs CRM não são alteradas. Não abrir acesso na VPS nem publicar via domínio sem autorização específica.
