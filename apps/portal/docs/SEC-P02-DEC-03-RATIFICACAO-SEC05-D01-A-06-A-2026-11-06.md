# SEC-P02-DEC-03 — Ratificação preliminar das alternativas A de SEC05-D01 a D06

**Data:** 06/11/2026 (America/Sao_Paulo)
**Manifestação:** “aprovado” em resposta à recomendação expressa de ratificar as alternativas A de SEC05-D01 a SEC05-D06, apenas como diretrizes documentais preliminares.
**Estado:** RATIFICADAS DOCUMENTALMENTE; NÃO HOMOLOGADAS; SEM AUTORIZAÇÃO DE CÓDIGO.

As alternativas A seguem a SEC-P02-D05, sem alterar seu significado:

| ID | Diretriz A documental ratificada | Critério ainda pendente |
| --- | --- | --- |
| SEC05-D01 | Formalizar G-ID01 a G-ID09 como checklist de entrada para laboratório; liberar cada gate somente por evidência verificável | Contratos, evidências, revisão independente |
| SEC05-D02 | Fonte de identidade exclusivamente servidora do Portal, com revisão vigente por operação privada | Emissor, estados, versões e origem verificável |
| SEC05-D03 | Sessões opacas, rotacionadas e revogáveis, MFA separado da recuperação de senha e recuperação de alta garantia | Política e controles reais de sessão/MFA/reset |
| SEC05-D04 | Rastrear requisito, ameaça, teste adversarial, evidência e parecer antes de fechar qualquer gate | Execução e validação de provas |
| SEC05-D05 | Alinhar G-ID05/06 com D05 do PR #19, mantendo separados os gates RLS, tenant/pool e revogação | Revisão de PostgreSQL, políticas e concorrência |
| SEC05-D06 | Primeiro laboratório apenas sintético, isolado e descartável, se autorizado em decisão própria, sem painel público | Ambiente, custos, escopo, testes e autorização específica |

**Não homologar números propostos:** 30 min de inatividade, 12 h de sessão, step-up de 5 min e token de recuperação de 15 min permanecem CANDIDATOS. Nenhuma biblioteca de autenticação ou fator MFA foi selecionada para produção.

As ratificações anteriores SEC-P02-DEC-01 (SEC02-01..09) e DEC-02 (IDN-D01..05 e REC-D01..05) continuam APENAS DOCUMENTAIS. A decisão atual não fecha D02-SEC01..06, D03 físico/RLS, G-BB01..08, G-ID01..09, P0 e demais gates de Segurança, Dados, Privacidade e Operação.

PR #24 continua draft não mesclado. PR #19 continua documental, draft não mesclado. A identidade humana do CRM é independente da identidade humana do Portal; nenhum SSO ou associação automática foi autorizado com Performance AI. Proibido inferir autorização para login real, MFA, token, cookies, recovery, API, migrations, SQL, RLS, ambiente de laboratório, custos, integração, main, VPS, deploy ou merge.

**Próxima entrega candidata:** SEC-P02-D06, plano verificável de evidências e elegibilidade para solicitar autorização futura de laboratório, sem executar programação. Qualquer autorização de laboratório, implementação funcional, testes de segurança, merge e produção é uma decisão separada.
