# Portal F63 — painel visual estático do Imagine Performance AI

Data: 10/11/2026. Base F62, sem endpoints HTTP novos e sem identidade operacional.

A ferramenta CLI `apps/portal/tools/f63-render-credential-workbench.php` recebe exclusivamente um arquivo local JSON limitado a 48 KB, sem links simbólicos, e valida o conteúdo com a classe **real** `CredentialBoundPerformanceWorkbenchPreview` do Portal F62. O contrato vem do leitor unificado PostgreSQL F100.

A tela gerada em HTML puro mostra:
- seis indicadores de contas candidatas, campanhas vinculadas e revisões;
- tabelas de contas DEMO Google/Meta, campanhas e suas observações tipadas;
- histórico de revisão DEMO com avisos explícitos de **sem execução**;
- banner permanente `DEMONSTRAÇÃO NÃO HOMOLOGADA` e avisos de ausência de login, Ads e cobrança.

É **HTML estático, sem scripts, CSS externo, links, formulário, botão, imagens, vídeo ou chamadas de rede**. A política CSP bloqueia conexões e carregamentos externos, o conteúdo variável é escapado e o validador rejeita dados extras/enganosos, contas reais, estados de veiculação, labels indevidos, revisões inconsistentes e alteração das flags de segurança.

## Evidência

O workflow `.github/workflows/portal-f63-static-credential-workbench-ci.yml` executa PHP 8.4 e Node.js para comprovar renderização e recusas de JSON hostil, desativação fora do laboratório e ausência de exposição da ferramenta em rotas do Portal. A integração com o PostgreSQL ocorre no workflow Performance F102, utilizando JSON F100 criado por SQL e não uma fixture copiada.

**Importante:** a tela não está conectada a usuários do Portal, Google Ads, Meta, fornecedores ou clientes reais. O login PostgreSQL demonstrativo não autentica o usuário humano. P0, AppSec/DBA, S2S/Portal, LGPD, OAuth, regras de orçamento, VPS e homologação continuam pendentes; `main`, produção, Ads e billing inalterados.
