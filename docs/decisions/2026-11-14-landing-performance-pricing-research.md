# Atualização de precedência — 14/11/2026

**Divergência de tabela resolvida:** após esta pesquisa, o titular definiu nova regra: 20% da mídia investida com investimento mínimo de R$ 700 por canal. Consulte [a decisão vigente](2026-11-14-performance-20-percent-media.md). As tabelas e pendências de identificação abaixo são histórico da pesquisa anterior e não impedem registrar o modelo comercial agora aprovado. As condições operacionais ainda não decididas constam da nova decisão.

# Pesquisa histórica — Landing Page e Performance

Data: 14/11/2026. O titular informou que ambos os serviços já possuem definições e solicitou pesquisa no GitHub/versão anterior. A informação do titular motivou busca além da main; este relatório não revoga aprovações externas aos documentos localizados e não escolhe silenciosamente entre tabelas divergentes.

## Escopo e resultado

Consultados histórico de commits e branches dos repositórios Portal, Imagine Performance AI e Comercial. A busca inicial da PR #737 na main do Portal não cobriu a oferta Landing Page preservada na documentação Node. Correção: Landing Page possui preço comercial aprovado no planejamento IN-115.

### Landing Page — recuperada

Fonte: [OFERTA-LANDING-PAGE.md](https://github.com/rafhael100/imagine-portal/blob/76ce533da42bf5d195ebffcdc8a110d1bc70f9b8/docs/OFERTA-LANDING-PAGE.md), branch histórica `docs/producao-inteligente-v01` fixada no commit `76ce533da42bf5d195ebffcdc8a110d1bc70f9b8`. Oferta criada no commit `6dd240574e7583fb3280797c48164161cfbcbda3`; complementos posteriores presentes na fonte fixada.

- Implantação R$ 1.290: três cobranças R$ 430 em D0/D30/D60.
- Assinatura R$ 79,90/mês com início D90 da contratação, independentemente da publicação.
- Sete dias úteis após validar briefing/materiais; duas rodadas de ajustes; aprovação documentada da versão exata antes da publicação.
- Conteúdo em blocos existentes incluído na mensalidade; mudanças estruturais/funcionalidades sob orçamento. Sem teto mensal fixo de solicitações sob uso justo; atualização simples em até três dias úteis para revisão após materiais completos. Complemento também aplicável às novas ofertas Onepage; contratos legados preservados.
- Extras: até R$ 270 inclusive à vista; acima disso à vista ou até três parcelas opcionais, com condições explícitas da proposta, sem criar juros/datas padrão.
- CRM Básico elegível sem segunda mensalidade CRM. Ausência de implementação/homologação não apaga a aprovação comercial, mas impede afirmar operação já disponível.

### Performance — valores encontrados e divergência de aprovação

Fonte fixada do motor: `rafhael100/imagine-performance-ai@b152687b7e3c06543ccad8c938d74a9bc612b12f`.

| Família de referência | Valores encontrados | Classificação textual da fonte |
| --- | --- | --- |
| Gestão por canais e verba | 1 canal R$ 290/mês; 2 canais R$ 390/mês; gestão = maior entre piso e 15% da mídia elegível | Roadmap seção 3: proposta em análise, NÃO aprovada |
| Expansão futura | 3 canais R$ 490; 4 canais R$ 590; percentual 15% | Referência futura; TikTok/LinkedIn adiados; não oferta de lançamento |
| Outra simulação de pacotes | R$ 397 / R$ 697 / R$ 1.197 | Revisão econômica: nenhuma dessas tabelas é preço oficial; não existe mapeamento final recuperado de pacote/canal |
| Adicionais da maquete | R$ 179/mês por +1 campanha/+3 grupos; R$ 79/mês por +3 grupos; R$ 479/mês por +3 campanhas/+9 grupos | AD01 seção 5 e BL01 seção 10: simulados, não aprovados como tabela definitiva |
| Ativação da maquete | R$ 797 / R$ 1.497 | BL01 seção 10: simulação; aplicação por plano/canal não confirmada |

Referências:

1. [Roadmap, seção 3](https://github.com/rafhael100/imagine-performance-ai/blob/b152687b7e3c06543ccad8c938d74a9bc612b12f/docs/performance-ai/PERF-ROADMAP-CANAIS-LANCAMENTO-EXPANSAO.md) — fórmula usa **máximo**, não piso mais percentual.
2. [BL01, seção 10](https://github.com/rafhael100/imagine-performance-ai/blob/b152687b7e3c06543ccad8c938d74a9bc612b12f/docs/performance-ai/PERF-P0-BL01-OFERTA-PLANOS-FRANQUIAS.md) — adicionais/ativação e ressalva de simulação.
3. [AD01, seção 5](https://github.com/rafhael100/imagine-performance-ai/blob/b152687b7e3c06543ccad8c938d74a9bc612b12f/docs/performance-ai/PERF-P0-AD01-CONTAGEM-CAMPANHAS-GRUPOS-ADICIONAIS-v0.1.md) — adicionais e capacidades ilustrativas.
4. [Revisão econômica, seção 2](https://github.com/rafhael100/imagine-performance-ai/blob/b152687b7e3c06543ccad8c938d74a9bc612b12f/docs/performance-ai/PERF-P0-BL04-REVISAO-ECONOMICA-PRELIMINAR-2026-11-05.md) — registra explicitamente as duas famílias e impede misturá-las como oferta homologada.
5. [BL02](https://github.com/rafhael100/imagine-performance-ai/blob/b152687b7e3c06543ccad8c938d74a9bc612b12f/docs/performance-ai/PERF-P0-BL02-COBRANCA-DESCONTOS-MIDIA-ALCADAS.md) e [RES01-D13](https://github.com/rafhael100/imagine-performance-ai/blob/b152687b7e3c06543ccad8c938d74a9bc612b12f/docs/performance-ai/PERF-P0-RES01-OFERTA-FRANQUIAS-CUSTOS-DC01-DC03-DC10.md) — distinguem aprovação funcional da estrutura e definição monetária.

## Regras funcionais Performance recuperadas

- Assinatura por empresa; canais iniciais Google Ads e Meta (Instagram e/ou Facebook); seleção conjunta com nome explícito. Meta é um canal, não duas mensalidades por Instagram/Facebook.
- Mídia paga diretamente pelo cliente aos provedores, separada dos honorários Imagine.
- Campanhas ativas ou pausadas sob gestão ocupam capacidade; pausa não libera capacidade automaticamente.
- Trocas ordinárias recomendadas/validadas pela Imagine dentro do escopo não têm franquia de substituições nem taxa por troca.
- Adicionais exigem simulação e aceite; proporcional por dias corridos reais do ciclo, início na disponibilidade efetiva, primeiro dia fracionado e arredondamento final a centavos; pagamento confirmado antes da liberação.
- Mensalidade integral do adicional incorporada ao ciclo seguinte, com vencimento unificado. Cancelamento/redução de adicional sem multa específica, capacidade mantida até fim do ciclo pago e redução da cobrança no seguinte.
- Trabalho extraordinário depende de proposta versionada, validação Imagine e aceite expresso; recusar orçamento não gera taxa ou suspensão do plano-base.
- Performance não herda automaticamente D90, descontos Pix nem outras condições dos sites. Landing Page nova não está automaticamente incluída no Performance.

## Pendência concreta e alcance

Há valores, mas não foi localizado registro que reconcilie a afirmação atual do titular com as ressalvas explícitas e selecione uma única tabela final de Performance. Recuperar tal registro ou confirmar a tabela pretendida é necessário para preencher o preço operacional. Não transformar toda a precificação em indefinida: Landing Page já foi recuperada e incorporada ao catálogo JSON da PR.

Nenhuma credencial, cobrança real, publicação ou migração de contratos existentes é efetuada por estes arquivos. O catálogo é referência de migração, não checkout ativo. Conferidos 3 × 43000 = 129000 e 3 × 33000 = 99000 centavos.
