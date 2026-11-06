# PORTAL-TEC-D04 — Aprovação da Fase A do domínio comercial do Portal

**Data:** 05/11/2026  
**Situação:** **D04-FASE-A APROVADA COMO DIRETRIZ TÉCNICA DOCUMENTAL**, sem autorização de programação.
**Manifestação do responsável:** resposta "ok" à pergunta expressa "Você aprova essa Fase A para avançarmos na preparação do desenvolvimento?" no contexto da proposta D04 v0.1. Na mesma mensagem, esclareceu que o sistema anterior era protótipo em desenvolvimento e seus dados eram apenas de teste.

## 1. Fase A ratificada

Adotar como **escopo inicial direcional futuro** do domínio comercial:
1. Catálogo administrável de produtos e variantes, começando por produtos de sites elegíveis (Onepage/Landing Page) e prevendo extensão a outros serviços Imagine sem acoplamento de banco entre produtos.
2. Preços-base de implantação/mensalidade por produto, campanhas com regras e limites de tempo/quantidade, ofertas "De/Por" e preço fechado por versão.
3. Motor **determinístico** de cálculo de oferta, descontos elegíveis, condições de pagamento, primeiro vencimento, promoções, reajuste e total; não deixar IA decidir ou modificar valores oficiais.
4. Snapshot/versionamento de propostas e termos para **apresentação/simulação fictícia**; não converter aceite simulado em contrato real.
5. Painel administrativo e visualização pelo cliente **apenas com dados fictícios em ambiente isolado** quando programação vier a ser expressamente autorizada.
6. Estrutura de dados nova em **Laravel + PostgreSQL**, coerente com D01, D02 e D03; ideias e fluxos de negócio do sistema Node/SQLite anterior poderão servir de referência, sem portar dados de teste.

## 2. Regras comerciais anteriores preservadas

- **Implantação:** Pix à vista 5% sobre base elegível OU cartão de crédito em até 3× sem esse desconto. **Sem Pix parcelado.**
- **Mensalidades:** Pix Automático com desconto elegível de 5%; cartão recorrente sem desconto Pix.
- **Padrão de primeira mensalidade:** 90 dias, alterável por produto/campanha e conforme condição aceita; contagem de N mensalidades promocionais desde a primeira cobrança devida.
- **Reajuste:** somente anual, após 12 meses, conforme índice e condições contratuais efetivas, não fixados por esta decisão.
- **Adicionais:** até R$270 à vista; acima de R$270 pagamento à vista ou até 3× sem juros por cartão, condicionando o início à aprovação do orçamento e confirmação do recebimento exigido; política específica e diferenças de desconto não presumidas.
- **Inadimplência e benefício CRM:** regras já ratificadas de site/cortesia continuam; contas pagas CRM seguem independentes. Não transformar documentação parcial ou propostas de cancelamento em decisão vigente.
- A incidência/cumulatividade de promoções com desconto Pix e demais exceções segue sujeita a saneamento preciso no contrato e nas regras de cálculo **antes da implementação definitiva**.

## 3. Exclusões obrigatórias desta aprovação

**NÃO AUTORIZADOS:** programação/Codex, criação de branch de código, testes executados, banco real/migração de dados de teste, qualquer operação do PSP, cobrança, cartão/Pix reais, emissão fiscal, assinatura contratual real, concessão de benefício CRM, publicação de site, deploy, infraestrutura, gastos, merge na `main` e SSO CRM.

D04-FASE-B e D04-FASE-C **NÃO** estão aprovadas; a futura autorização de A não libera B ou C.

## 4. Gate de programação e sequência documental

- **D04-FASE-A:** diretriz aprovada. Próximo passo possível: preparar contrato de escopo técnico F1-01 para o Codex, com lista de arquivos-alvo, critério de aceitação, limitações, testes futuros seguros e política de PR.
- **D02-SEC, D03 físico/multiempresa, preço promocional + desconto Pix, P0 Sites/Portal/CRM e gates de segurança:** abertos.
- **Antes de F1-01** deverá haver autorização de programação **expressa e específica**; não considerar esse "ok" autorização de escrever código.
- **Protótipo legado:** conforme [D03-R02](PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md), principal reaproveitamento é **estrutura de negócio e ideias de fluxo**, não dados/testes/infra legados.
- **AGI-11-RB01** permanece desabilitado; `main`, VPS e integrações reais intactas.

## 5. Referências

- [Proposta D04 v0.1](PORTAL-TEC-D04-CONTRATACAO-PRECOS-FINANCEIRO-PROPOSTA-v0.1-2026-11-05.md)
- [D03-MIG-B — decisão](PORTAL-TEC-D03-APROVACAO-D03-MIG-B-MODELO-LOGICO-2026-11-05.md)
- [D03-R02 — legado é protótipo](PORTAL-TEC-D03-R02-LEGADO-PROTOTIPO-FLUXOS-NEGOCIO-DADOS-TESTE-2026-11-05.md)
- [Política de Pix/cartão no CRM Imagine](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/PORTAL-COM-POLITICA-VIGENTE-PIX-05-CARTAO-3X-2026-11-03.md)

**Situação final:** aprovação documental D04-FASE-A registrada; demais fases/gates e execução continuam pendentes.
