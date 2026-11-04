# DEC-INF-DEV-04 — Aprovação da base documental de dimensionamento financeiro de backups

**Data do aceite:** 04/11/2026
**Estado:** **APROVADA COMO BASE DOCUMENTAL DE PLANEJAMENTO FINANCEIRO**, exclusivamente.
**Evidência do aceite:** resposta expressa “ok” à pergunta: “Você aprova essa base documental, sem autorizar contratações ou alterações nos servidores?”, referente à **INF-DEV-04 — Dimensionamento financeiro e implantação controlada**.
**Documento ratificado:** [INF-DEV-04 — Projeção de 30 dias e governança de custos](INF-DEV-04-DIMENSIONAMENTO-FINANCEIRO-BACKUP-R2-2026-11-04.md).

## 1. Escopo da aprovação

A direção aprova **como método e base para planejamento**, sem fixar valores reais:
1. A distinção entre volume único inicial, dados novos diários, ocupação de fim de janela e **GB-mês médio faturado**; cenários de 20/50/100/200 GB com hipótese didática de 2%/dia, sem medições reais.
2. Separar custos de armazenamento R2 Standard, **operações Classes A/B**, eventual segundo ponto protegido, impostos e câmbio, custo operacional e mensalidade da VPS Leaseweb.
3. **Não presumir franquia gratuita R2** integralmente disponível quando a conta Cloudflare servir a outros produtos.
4. Exigir **inventário e medições**, definição de responsáveis, permissões mínimas, custódia externa de chaves, restauração testada e política segura de proteção contra exclusão antes de depender dos backups.
5. Estabelecer **teto de gastos e alçada financeira apenas após a estimativa real**, com alertas de consumo próprios; alertas financeiros da Cloudflare são informativos e **não suspendem a cobrança**.
6. Preservar a escolha já ratificada de **R2 Standard com jurisdição UE** como destino, e a política-base documental **Restic + backup diário + retenção inicial 30 dias + testes de restauração**.

## 2. O que NÃO foi autorizado

Esta aprovação **NÃO homologa** valores de cenário como custo real, gasto máximo, contrato, cobrança ou orçamento final.

**Não estão autorizados:**
- compra/assinatura da Leaseweb VPS 3, compra/ativação de serviços R2 ou qualquer storage adicional;
- criação de bucket, tokens, contas, chaves, conexões, configuração, alteração de servidor, instalações, scripts, timers, backups ou testes de restauração reais;
- publicação, programação ou execução de qualquer projeto Imagine, inclusive a Universidade (Gate C permanece não autorizado);
- políticas definitivas de retenção/lifecycle/lock ou exclusão automática antes de homologação;
- uso ou migração de dados reais de clientes para ambientes de desenvolvimento;
- aplicar a política de backup da VPS de **desenvolvimento** aos ambientes de **produção** do CRM, Portal, Sites, Performance AI, Universidade ou servidores de IA/n8n, que permanecem independentes.

## 3. Condições para autorizações futuras

| Próxima dependência | Estado |
|---|---|
| Medir dados e crescimento real dos ambientes de desenvolvimento quando existirem | **PENDENTE** |
| Validar Cloudflare R2 EU, disponibilidade de conta, papéis e cobrança total | **PENDENTE** |
| Definir segregação dos buckets, chaves, proteção contra deleção e rotina de recuperação | **PENDENTE** |
| Dimensionar orçamento total, impostos, câmbio e operações; estabelecer **teto financeiro** | **SEM APROVAÇÃO** |
| Obter aprovação explícita de contratação Leaseweb/R2 e autorizar implantação dos backups | **SEM APROVAÇÃO** |
| Homologar rotinas com dados fictícios e comprovar restauração | **NÃO EXECUTADO** |

**Conclusão normativa:** INF-DEV-04 ratificada **apenas como base de planejamento e cálculo**, subordinada às decisões anteriores [DEC-INF-DEV-02](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md) e [DEC-INF-DEV-03](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md). Sem autorização operacional, financeira ou de desenvolvimento.
