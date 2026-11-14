# PORTAL-PERF-CHATGPT-P0 — integração comercial do terceiro canal (14/11/2026)

**Origem:** titular autorizou incluir ChatGPT Ads no Imagine Performance AI como canal comercial e desenvolver a integração.
**PR de desenvolvimento dependente:** https://github.com/rafhael100/imagine-performance-ai/pull/351 (DRAFT).
**Base comercial deste recorte:** Portal PR #737 — catálogo versão 4, duas plataformas, 20% sobre mídia real, mínimo anterior R$700 por canal.

## Status

DRAFT / NO-MERGE / NO-DEPLOY / NO-CHARGE / NO-ADS-WRITE.
Este documento NÃO altera o JSON canônico approved-commercial-catalog.json, o checkout, a cobrança Asaas ou contratos já firmados. O usuário pediu uma recomendação de valores, sem ratificar os pisos exatos propostos.

## Regra de catálogo candidato: preços por canal

Metadados candidatos:
- meta_ads: mínimo Imagine de mídia mensal R$700, investimento recomendado R$1000;
- google_ads: mínimo Imagine de mídia mensal R$1000, recomendado R$1500;
- chatgpt_ads: mínimo Imagine de mídia mensal R$1200, recomendado R$1800.

Não interpretar o piso mensal Imagine como limite técnico universal da plataforma. OpenAI exige orçamento diário mínimo de BRL 40 por campanha para contas BRL com orçamento diário, não mensalidade geral de R$1.200; orçamento total tem semântica própria. Os demais pisos são critérios comerciais candidatos, não mínimos universais de provedores.

Honorários aprovados permanecem **20% da mídia efetivamente investida**, com estimativa antecipada pelo orçamento contratado e acerto mensal revisado. Cliente paga mídia DIRETAMENTE à Meta/Google/OpenAI. A Imagine cobra somente gestão via Portal, sujeita à reconciliação e autorização. Nunca faturar a mídia pela Imagine por confusão com o serviço.

## Contratos necessários (versão seguinte)

1. Catálogo versionado deve usar um mapa por canal de media_minimum_cents, em vez de mínimo universal. A versão 4 não suporta este formato; migração requer PR independente com testes de retrocompatibilidade.
2. Regras devem validar cada canal individualmente (sem utilizar excesso de outro canal para compensar piso insuficiente).
3. Identificadores de canal estáveis: meta_ads, google_ads, chatgpt_ads; Facebook/Instagram juntos.
4. UI deve exibir nomes, disponibilidade real, orçamento mínimo Imagine, recomendação, status API, prévia de gestão e separação inequívoca dos pagamentos.
5. Contract signing Portal -> Performance deve carregar três canais somente com catálogo novo e aceites validados, tenant, account IDs, orçamento/ciclo e nonce anti-replay; nunca confiar em parâmetros de URL do navegador.
6. Ativação do ChatGPT Ads deve permanecer "indisponível para contratação" até conector real autenticado, permissões delegadas, LGPD, testes de API, segurança, financeiro e aprovação de GO.
7. Usuário sem conta ads autorizada pode visualizar "Em preparação" e solicitar interesse; não aceitar pagamento nem prometer veiculação.
8. Cancelamento de gestão deve gerar instruções explícitas de status das campanhas; não assumir que suspender o contrato Imagine pausa automaticamente gasto no provedor.
9. ROI, custo, clique, conversões e dados de mídia devem manter proveniência e última atualização, sem somar dados sintéticos a dados reais.

## Aceite de engenharia

- Testes fail-closed dos três canais, legado v4, troca de tenant e account, moeda/timezone, limite de mídia e arredondamento 20%.
- Teste crossrepo Portal+Performance PR #351 com catálogo futuro, antes de qualquer merge.
- Todos os comandos de publicação/cobrança desabilitados no laboratório.
- Bloquear preço público e contratação até aprovação explícita dos três pisos e verificação de conta OpenAI.
- Registrar trilha de consentimento/autorização do anunciante para API e rastreamento.

Fontes oficiais:
- https://help.openai.com/pt-br/articles/20001210-creating-campaigns-for-chatgpt-ads
- https://developers.openai.com/ads/api-overview
- https://developers.openai.com/ads/api-partner-setup
