# P0-FECHAMENTO-DOCUMENTAL-01 — Bloqueios para liberação do desenvolvimento Imagine Sites

**Estado:** instrumento de trabalho documental; **P0 ainda NÃO fechados**.  
**Escopo:** Imagine Sites (Onepage/Landing Page), Portal Imagine e apenas as interfaces indispensáveis ao CRM.  
**Processo aprovado:** finalizar planejamento integral, consolidar/confirmar fontes no GitHub, aprovar pacote final por versão, **então** autorizar programação expressamente.  
**Não autorizado:** programação, testes, execução operacional, homologação, implantação, mudança de serviços e rollback automático AGI-11-RB01.

**Atualização RV01 (04/11/2026):** [revisão de consistência P0-DOC03](P0-DOC03-RV01-CONSOLIDACAO-AUT-P01-P10-2026-11-04.md) conferiu as dez decisões AUT-P01–P10 ratificadas e catalogou lacunas de contratos técnicos. **P0-DOC03 continua ABERTO**, assim como os demais gates já indicados; esta conferência não altera os estados da tabela, não encerra RF-PEND-01 e não homologa RF-PEND-04.

## P0 — Matriz de encerramento documental

| Código | Bloqueio/documento de saída | Critério de encerramento documental | Situação atual |
|---|---|---|---|
| P0-DOC01 | RF-PEND-01: corpus/precedência GitHub | Localizar fontes integrais, commits, branches e ratificações; saneamento de divergências; parecer final aprovado | PARCIAL, ABERTO |
| P0-DOC02 | AGI-RF01-R01 a R07 e AGI-P09..P18, CLD/P0/P1 | Conferir texto individual e aceite dos blocos relevantes sem inventar requisitos por título; completar rastreabilidade | ABERTO |
| P0-DOC03 | Contrato Sites / Portal / CRM e titularidade dos estados | Quem decide cadastro/vínculo/site, autorização do CRM, cobrança, publicação, lead, auditoria e incidentes; fronteiras de responsabilidade | ABERTO |
| P0-DOC04 | Release, prévia, aceite e publicação | Versão do artefato, identidade, prova de aceite expresso, mudanças pós-aceite, alçadas IA/humano, bloqueadores e critérios | PARCIAL, ABERTO |
| P0-DOC05 | Dados pessoais, captação e CRM | IN127-R01..R06 ratificadas; contratos finais API, estados, idempotência, integridade, descarte, trilhas e autorização do destino | DECISÕES RATIFICADAS, CONTRATOS ABERTOS |
| P0-DOC06 | Segurança multiempresa e autorização | Fronteiras de cliente/site/formulário/conta; RBAC/MFA, segredos, isolamento de dados, prevenção de abuso, logs minimizados | ABERTO |
| P0-DOC07 | Arquitetura inicial por fases | Topologia lógica e física escolhida o suficiente para codificar, responsabilidades Cloudflare/GitHub/API/n8n/armazenamento, ambientes, custos e risco | PARCIAL, ABERTO |
| P0-DOC08 | Backups e recuperação | O que é copiado, separação CRM/Sites, armazenamento, restauração, antirressurreição de lead, retenções, aprovações | ABERTO |
| P0-DOC09 | Domínios/SSL/DNS e publicação controlada | Modelo de configuração por cliente; DNS preferencialmente sob gestão do cliente; verificação de domínios, erros e rollback humano | PARCIAL, ABERTO |
| P0-DOC10 | Contratação/pagamentos essenciais | Regras Pix 5%, cartão 3×, Pix Automático 5%, primeira mensalidade padrão 90 dias, faturamento, tolerância, contratos de site/CRM | REGRAS APROVADAS, FONTES DIVERGENTES |
| P0-DOC11 | Fluxos Portal e suporte inicial | Pedido, briefing, materiais, produção, revisão, aceite, entrega, suporte, permissões e responsabilidade contratual | PARCIAL, ABERTO |
| P0-DOC12 | Contratos/integrações externas críticas | APIs, eventos, estados de resultado, erros, quotas e dependência de provedor para o escopo inicial; critérios futuros de homologação | ABERTO |
| P0-DOC13 | Escopo de primeira implementação | Sequência de módulos, dependências, backlog, entregáveis, exclusões explícitas, critérios de aceite de cada marco | A DEFINIR |
| P0-DOC14 | Pacote final congelado | Revisão global de decisões P0, versões/commits de referência, aprovação expressa e autorização separada para programação | NÃO INICIADO |

## Matérias já decididas e que não devem ser reabertas por rotina

- IN127-R01..R06: ratificadas uma a uma. O arquivo específico está no [CRM Imagine](https://github.com/rafhael100/imagine-crm/blob/main/docs/planejamento/IN-127-R01-R06-ratificacoes-2026-11-03.md).
- Aceite expresso do artefato de site; publicação não se faz com aprovação presumida por silêncio.
- Site estático pode existir sem CRM; formulário não recebe sem autorização do destino verificada no backend.
- Não gerenciar DNS autoritativo dos clientes como serviço padrão; respeitar registrador e preferência voluntária Cloudflare.
- Descontos atuais: implantação Pix à vista 5%, cartão até 3× sem desconto; mensalidade Pix Automático 5%, cartão recorrente sem desconto; sem Pix parcelado.
- GA4 apenas integração voluntária; informações GA4 são consultadas diretamente pelo cliente no Google, sem relatórios GA4 na Imagine.
- Rollback automático AGI-11-RB01 DESABILITADO, sem aprovação de reativação.

## Critérios P0 que NÃO exigem testes reais antes de iniciar desenvolvimento

Para início de código, documentar estratégia, mecanismos esperados, contratos, limites, evidências requeridas e plano de testes; executar os testes durante desenvolvimento/homologação posterior conforme autorização. Não confundir "critério de teste definido" com "teste realizado". Fornecedores e custos finais precisam de seleção suficiente e decisão por fase; homologação operacional ocorre no estágio pertinente, sem compromisso prematuro.

## Três frentes sugeridas para o próximo avanço

1. **Conferência de fontes**: finalizar RM de branches, arquivos canônicos e aprovações por requisito, com diferenças e prioridade.
2. **Arquitetura P0**: fechar autoridades, APIs/eventos, segurança multitenant, topologia de fase zero e controles de publicação/captação/backup.
3. **Especificação de entrega inicial**: estabelecer primeiros módulos, interfaces mínimas, riscos e critérios documentais de aceite.

A ordem é sugestão de execução documental, não nova aprovação comercial ou arquitetural.

## Regra de encerramento

Marcar `FECHADO` somente com fonte rastreada, decisão aplicável ratificada, documento versionado e verificado, dependências ou exceções justificadas e aceite do responsável. Uma linha concluída não fecha automaticamente os demais P0 nem RF-PEND-04.

**Status global:** ETAPA 1 EM ANDAMENTO; liberação de desenvolvimento NÃO CONCEDIDA.
