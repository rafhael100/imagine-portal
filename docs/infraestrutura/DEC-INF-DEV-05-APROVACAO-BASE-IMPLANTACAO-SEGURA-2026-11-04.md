# DEC-INF-DEV-05 — Aprovação da base documental de implantação segura dos backups Imagine

**Data do aceite:** 04/11/2026.
**Estado:** **INF-DEV-05 APROVADA COMO BASE DOCUMENTAL DE GOVERNANÇA E SEGURANÇA**, sem autorização de compra, instalação, teste, ativação ou programação.
**Manifestação expressa:** “ok” em resposta à pergunta: “Você aprova a INF-DEV-05 como base documental, incluindo a proposta de cinco buckets individuais e uma área de recuperação protegida, mantendo orçamento, permissões definitivas, instalação e ativação pendentes de aprovação posterior?”.
**Documento técnico ratificado como base:** [INF-DEV-05 — Preparação da implantação segura](INF-DEV-05-PREPARACAO-IMPLANTACAO-SEGURA-BACKUPS-v0.1-2026-11-04.md).
**Precedentes:** [DEC-INF-DEV-02 — R2 Standard UE](DEC-INF-DEV-02-DESTINO-R2-EU-APROVADO-2026-11-04.md), [DEC-INF-DEV-03 — Restic e política-base](DEC-INF-DEV-03-POLITICA-BACKUP-RESTIC-R2-APROVADA-2026-11-04.md), [DEC-INF-DEV-04 — Método financeiro](DEC-INF-DEV-04-APROVACAO-BASE-DIMENSIONAMENTO-BACKUP-R2-2026-11-04.md).

## 1. Escopo documental aprovado

1. **Isolamento por projeto como padrão:** um bucket Cloudflare R2 Standard **privado e com jurisdição EU** para cada ambiente de desenvolvimento **Portal Imagine, CRM Imagine, Imagine Sites, Performance AI e Universidade Imagine**. São **cinco buckets previstos**, não criados. Nomes e contas ficam pendentes.
2. **Recuperação protegida separada:** prever área/bucket adicional para cópias/pontos de recuperação protegidos contra exclusão indevida, administrada com identidade e chaves **fora da VPS de desenvolvimento**. A solução concreta, custo, forma de cópia e regras de imutabilidade continuam dependentes de homologação.
3. **Credenciais e papéis segregados:** tokens de serviço restritos aos buckets e operações necessários, segregação entre backup diário, manutenção, administração, restauração e custódia de chaves. Definir responsáveis e substitutos antes de operar.
4. **Segurança da conta e do host:** MFA administrativo, segredos não versionados no GitHub, sem bucket público, sem senhas no chat e sem capacidade do token da VPS de alterar políticas globais de conta ou bucket.
5. **Política-base herdada:** Restic, backup diário, retenção inicialmente proposta de 30 dias, alertas, verificação de integridade, restaurações e simulação de perda da VPS, com testes previamente especificados. Frequência exata, custos e resultados técnicos permanecem dependentes de avaliação antes da operação.
6. **Validação antes de produção dos backups:** a matriz de testes DEV05-T01 a T15 da INF-DEV-05 está **aprovada como cobertura de planejamento**, mas nenhum teste foi realizado, aprovado ou autorizado para execução por este ato.
7. **Escopo restrito:** VPS central de **desenvolvimento**, com dados fictícios. Não substitui os backups independentes de produção ou dos agentes/n8n.

## 2. Pendências que permanecem ABERTAS

| Código | Pendência | Estado após aprovação |
|---|---|---|
| DEV05-D01 | Arquitetura de cinco buckets por projeto e área protegida separada | **APROVADA DOCUMENTALMENTE; nenhum bucket criado** |
| DEV05-D02 | Decidir conta Cloudflare existente segregada x conta exclusiva de backup | **ABERTO** |
| DEV05-D03 | Definir responsáveis, substitutos, custódia e auditoria | **ABERTO** |
| DEV05-D04 | Especificar permissões mínimas e validar compatibilidade Restic/R2 | **ABERTO; homologação não executada** |
| DEV05-D05 | Definir retenção/imutabilidade, formato e restauração da cópia protegida | **ABERTO; homologação não executada** |
| DEV05-D06 | Medir ocupação/operacões e aprovar orçamento/teto por projeto e no total | **ABERTO; sem autorização financeira** |
| DEV05-D07 | Autorizar contratação de Leaseweb e ativação Cloudflare R2 | **NÃO AUTORIZADO** |
| DEV05-D08 | Autorizar instalação, scripts, hardening, testes reais e operação | **NÃO AUTORIZADO** |
| DEV05-D09 | Detalhar plano de incidentes, perda de conta/credenciais/chave | **ABERTO** |
| DEV05-D10 | Definir alçadas de restauração, manutenção e exclusão | **ABERTO** |

A aceitação documental de uma área protegida **não homologa Bucket Locks sobre o repositório Restic ativo**: lock pode impedir a manutenção/prune. É obrigatório validar mecanismo separado e a restauração em dados sintéticos antes de uma ativação futura.

## 3. Ações expressamente NÃO autorizadas

Não contratar, pagar, provisionar VPS, ativar R2, criar buckets/tokens, configurar servidores ou interfaces Cloudflare, instalar Restic, montar scripts/timers, alterar políticas de retenção/lifecycle, executar testes, backups ou restaurações, importar dados reais ou publicar aplicações.

**Gate C da Universidade permanece NÃO AUTORIZADO**, assim como as demais restrições de programação e produção vigentes no CRM, Portal, Sites e Performance AI. Esta decisão não aprova cronograma, custo, RPO/RTO ou resultados de homologação.

## 4. Próxima etapa documental recomendada


> **INF-DEV-06 elaborada em 04/11/2026:** [Proposta de identidades, segredos e incidentes](INF-DEV-06-GESTAO-IDENTIDADES-SEGREDOS-E-INCIDENTES-v0.1-2026-11-04.md), apenas documental **e ainda não ratificada**. O texto não libera contratação, emissão de tokens, operações ou programação.

**INF-DEV-06 — Gestão de identidades, permissões mínimas, custódia e recuperação de chaves e plano de resposta a incidentes:** definir separação de contas Cloudflare, modelo de responsabilidade por projeto, identidade de backup/limpeza/restore, proteção administrativa, critérios de auditoria, fluxos de perda de credenciais e autorização de restauração. Tratar os itens financeiros, contratação e execução como **gates futuros independentes**, sem pressupor cobrança ou acesso a contas.

**Resultado:** INF-DEV-05 ratificada **somente como base de planejamento e segurança**; cinco buckets por projeto e área protegida são padrões documentais; detalhes, despesas, servidores e operação permanecem pendentes.
