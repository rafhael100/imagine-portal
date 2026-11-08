# PORTAL-F1-01-B-B-DEC-04 — Ratificação preliminar das alternativas A de BBD04-01 a BBD04-06

**Data:** 06/11/2026 (America/Sao_Paulo)  
**Manifestação do responsável:** “aprovado”, em resposta à solicitação expressa de aprovação das alternativas A de **BBD04-01 a BBD04-06 somente como diretrizes documentais preliminares**, preservando gates de segurança e sem autorizar programação, merge ou deploy.  
**Estado:** **APROVADO PARA PLANEJAMENTO DOCUMENTAL; NÃO HOMOLOGADO**. A presente decisão não autoriza a criação ou execução de nenhum laboratório, SQL, migrations, RLS, código funcional, integração ou implantação.

## 1. Decisões ratificadas exclusivamente como diretrizes

As alternativas A são as descritas na [F1-01-B-B-D04 — Matriz de homologação, evidências e gates](PORTAL-F1-01-B-B-D04-MATRIZ-HOMOLOGACAO-EVIDENCIAS-GATES-PROPOSTA-2026-11-06.md), sem alterar os limites de [DEC-01](PORTAL-F1-01-B-B-DEC-01-RATIFICACAO-DIRETRIZES-BB01-BB08-2026-11-06.md), [DEC-02](PORTAL-F1-01-B-B-DEC-02-RATIFICACAO-DIRETRIZES-BBD02-01-A-06-A-2026-11-06.md) ou [DEC-03](PORTAL-F1-01-B-B-DEC-03-RATIFICACAO-BBD03-01-A-06-A-2026-11-06.md).

| ID | Diretriz A ratificada documentalmente | Pendência obrigatória |
|---|---|---|
| **BBD04-01** | Separar decisão documental, contrato, autorização de código de laboratório, homologação, merge e deploy | Ordem explícita para cada uma, sem fechamento automático |
| **BBD04-02** | Vincular G-BB01..08 e H/C/P a responsáveis, provas negativas e critérios de aceitação antes de programar | Definir donos reais, fontes, versões e evidências antes dos gates |
| **BBD04-03** | Autoridade de identidade exclusivamente Portal, vínculo/grants/recurso verificados no servidor; RLS apenas segunda barreira | D02-SEC, autoridade confiável de contexto, impossibilidade de ID CRM gerar acesso |
| **BBD04-04** | Revisão independente de roles, RLS, revogação e transações/pool antes de operações com dados privados | Modelagem física, desenho da função mínima, limites de confiança e revisão DBA/Segurança |
| **BBD04-05** | Quando autorizado, laboratório PG isolado/descartável com fixtures fictícias, controle de custos e segregação | Ambiente, testes e instalação **ainda não autorizados** |
| **BBD04-06** | CI negativo e revisão Segurança/DBA + aceite formal antes de merge; gate distinto para `main` e VPS | Ensaios executados e registrados, autorização de cada promoção |

As alternativas B/C permanecem apenas comparativos; a escolha documental A não as torna proibidas para reavaliação futura.

## 2. Gates e limitações preservados

- **ABERTOS:** D02-SEC01..06, D03 físico/RLS, P0-DOC01..14 e P0-DOC06/G-04, G-BB01–08, H01–H12, C01–C10, P01–P12 e homologação independente.
- **Não autorizados:** migrations, SQL executável, DDL, tabelas, RLS instalado, Eloquent persistente, login/MFA, banco real, fixtures em laboratório, integração CRM, dados reais, acesso à VPS, deploy ou merge em qualquer branch.
- O [PR #19](https://github.com/rafhael100/imagine-portal/pull/19) continua exclusivamente documental, em rascunho e **sem merge**. Não inferir integração por CI ou por assinatura deste documento. PR #11, PR #10, `main` e infraestrutura permanecem sob governança distinta.
- Nenhum teste PostgreSQL de isolamento, concorrência, revogação ou pooling foi executado como resultado desta aprovação.

## 3. Próxima entrega documental permitida

Elaborar o **D05 — Pacote de pré-implementação: decisões faltantes, critérios de fechamento por gate e menor recorte futuro possível**, voltado a reduzir novas rodadas genéricas de aprovação. O D05 deve distinguir: (a) decisões de arquitetura ainda não fixadas; (b) contratos de segurança não homologados; (c) proposta de laboratório sem execução; e (d) autorização funcional futura separada. O pacote **não** poderá recomendar código como já autorizado, fechar gates pela mera existência da documentação ou executar testes.

**B-B funcional permanece BLOQUEADA.**
