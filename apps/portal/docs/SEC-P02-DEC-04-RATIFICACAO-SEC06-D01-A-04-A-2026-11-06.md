# SEC-P02-DEC-04 — Ratificação documental de SEC06-D01 a SEC06-D04

**Data:** 06/11/2026. **Manifestação:** “aprovado” à recomendação expressa de ratificar exclusivamente as alternativas A de SEC06-D01 a SEC06-D04 como diretrizes documentais preliminares.
**Estado:** RATIFICADAS DOCUMENTALMENTE; NÃO HOMOLOGADAS; SEM AUTORIZAÇÃO DE CÓDIGO OU LABORATÓRIO.

| ID | Diretriz A aprovada para planejamento | O que NÃO foi autorizado |
| --- | --- | --- |
| SEC06-D01 | Checklist único e ordenado das evidências 1–8 em SEC-P02-D06; cada gate depende de evidência verificável | Documentos não equivalem a prova operacional ou gate fechado |
| SEC06-D02 | Primeiro laboratório futuro sintético de emissor/membership/tenant, SEM login/MFA reais, condicionado a ordem posterior específica | Não autoriza criar, instalar, executar laboratório, SQL ou migrations |
| SEC06-D03 | PR #19 segue documental separado e dependências são conciliadas por referências; sem merge implícito | Não autoriza homologação PostgreSQL/RLS, alteração ou integração do PR #19 |
| SEC06-D04 | Sem rotas, painel Performance nem acesso de clientes até homologação de identidade, isolamento e testes negativos | Não autoriza piloto privado, autenticação operacional, main, VPS ou deploy |

As alternativas B continuam comparativos não selecionados. Permanecem preliminares as decisões SEC-P02-DEC-01, DEC-02 e DEC-03. Prazos candidatos de sessão (30 minutos inatividade, 12 horas máximo, 5 minutos step-up) e reset (15 minutos) NÃO estão homologados.

**Gates continuam ABERTOS:** D02-SEC01–06, D03 físico/RLS, G-ID01–09, G-BB01–08, P0-DOC01–14, Privacidade, Segurança, Arquitetura, QA e DBA. Nenhuma origem real de identidade, store de sessão, MFA, banco, RLS, revisão independente ou teste negativo com PostgreSQL foi validado por esta decisão. O SEC-P01 testou exclusivamente domínio fictício sem rotas.

**Proibições:** programação de login real, cookies, MFA, recuperação, SQL, migrations, banco conectado, laboratório, integrações, dados pessoais, merge dos PRs #19 e #24, main, VPS, despesas e produção.

**Próxima entrega autorizada:** levantamento DOCUMENTAL de evidências e lacunas reais, começando por emissor Portal e membership/revogação do PR #19. Pareceres técnicos e execução de laboratório continuam pedidos de autorização posteriores e independentes.