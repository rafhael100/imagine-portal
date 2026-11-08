# PVIEW-03 — consolidação visual em Blade (não operacional)

Data: 08/11/2026. Escopo autorizado: consolidar o visual Blade a partir do protótipo HTML PVIEW-01 e validar renderização e navegação. Sem login, SQL, rota privada, deploy ou conexão com provedores.

## Mudanças

- Shell Blade com sidebar escura, cabeçalho, contexto fictício da Empresa Aurora, aviso destacado, cartões, indicadores e layout responsivo para oito áreas.
- PviewSection fornece títulos e descrições da apresentação; cada seção é exclusivamente fictícia. Não prova contratação, identidade, autorização, assinatura ou elegibilidade.
- Script inline mínimo alterna telas via #pview-*, atualiza aria-current, título e foco; sem cookies, API, storage ou dados reais. Se JavaScript estiver desabilitado, as seções permanecem visíveis, com navegação por âncoras.
- PHPUnit testa o HTML de apresentação e confirma rotas privadas 404. Renderização em CLI grava somente no RUNNER_TEMP, sem endpoint HTTP.
- CI dedicado exporta a view Blade para HTML descartável e executa Chrome headless/CDP: 3 tamanhos × 8 telas, teclado Enter e zero requisições HTTP externas. Imagens de prova, quando produzidas, têm retenção de 1 dia.

## Atenção: demo NÃO é produção

A CSP do mock aceita script-src 'unsafe-inline' exclusivamente para a prévia local; jamais copiar esta política para interface operacional sem nova revisão. Não foram implementados login, MFA, sessões reais, Eloquent, migration, RLS, finanças, CRM, n8n, Google/Meta ou operação de Sites, Performance e Universidade.

O CI no browser não substitui revisão humana, leitor de tela ou zoom nativo 200%. A validação funcional do Portal depende dos gates P0, D02-SEC, D03/RLS, SEC-R03/T3 e decisão de liberação. Nenhum deploy, merge para main/dev, custo, contratação ou VPS permitido nesta fase.

## Reprodução em ambiente sintético

1. Instalar somente as dependências já fixadas pelo Composer lock, em runner isolado.
2. Criar chave de testes local e executar PHPUnit. Nenhuma migration ou banco deve ser ativado.
3. Executar render_blade_preview.php com PVIEW_RENDER_FILE sob RUNNER_TEMP.
4. Executar o script de browser PVIEW-02 com PVIEW_HTML_FILE apontando para o HTML exportado e PVIEW_HASH_PREFIX=pview-. Inspecionar logs e screenshots do workflow PVIEW-03.

Este PR é filho do PR #74 e permanece em rascunho, sem merge automático.
