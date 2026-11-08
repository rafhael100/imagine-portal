# SEC-P02-D06 — Evidências necessárias e recorte candidato de laboratório

**Data:** 06/11/2026
**Estado:** PROPOSTA DOCUMENTAL A AVALIAR, NÃO RATIFICADA.
**Referências:** SEC-P02-DEC-01 a DEC-03; SEC-P02-D05; PR #19 F1-01-B-B D01 a D05. Todos os gates de segurança permanecem ABERTOS.

## 1. Objetivo

Converter sucessivas diretrizes preliminares em entregáveis rastreáveis para decidir, FUTURAMENTE, se um laboratório descartável com dados inteiramente sintéticos poderia ser autorizado. Não é pedido de acesso à VPS, execução SQL, laboratório, programação ou merge. O painel Performance P02-D permanece não roteado e sem autenticação.

## 2. Pacote mínimo de evidências antes de qualquer ordem de programação

| Prioridade | Gates correlatos | Evidência objetiva pendente | Parecer necessário |
| --- | --- | --- | --- |
| 1 | G-ID01, G-BB03 | ADR do emissor Portal, estados e revisão de identidade; demonstrar impossibilidade de ID CRM coincidente gerar sessão/vínculo Portal | Identidade, Arquitetura e Segurança |
| 2 | G-ID02/03/04 | Contratos de MFA, sessão, rotação, CSRF, reset de uso único, recuperação de fator, revogação e limites antiabuso; valores de tempo submetidos a avaliação de risco | Segurança, Backend e QA |
| 3 | G-ID05, G-BB04 | Fonte oficial de membership e grants, papéis versionados, alçadas/último gestor, barreira de revogação e ownership | Produto, Dados e Segurança |
| 4 | G-ID06, G-BB01/02/05/06 | Versão PG, drivers, ERD com FKs compostas, inventário de roles/GRANTs, RLS por operação, modelo de pooling, SET LOCAL apenas como contexto e análise de SQL injection | DBA, Segurança e Plataforma |
| 5 | G-ID07 | Fronteiras Portal/CRM/Performance e identidade separada de pessoas versus serviços; sem credenciais compartilhadas | Arquitetura e Segurança de Integrações |
| 6 | G-ID08, G-BB07 | Finalidades, LGPD, retenção a determinar, auditoria minimizada, restore que não ressuscita direitos revogados | Privacidade e Segurança |
| 7 | G-ID09, G-BB08 | Rastrear AUT-01..15, IDN-01..10, REC-01..10 e H/C/P do PR #19 a cenário, ameaça, negação esperada, coletor de evidências e revisor | QA, Segurança e DBA |
| 8 | Autorização de ambiente | Custo e local do laboratório, branch e SHA, escopo exato, fixtures, rollback e condições de parada | Direção após pareceres técnicos |

**Fato atual:** não há evidências completas de todos esses itens nem pareceres independentes. Aprovação documental de diretriz A NÃO transforma linha de checklist em gate fechado.

## 3. Menor experimento futuro — somente se explicitamente autorizado depois

Um único recorte no repositório Portal, em branch filha isolada e base SHA validada, com duas organizações artificiais, usuários de identidade própria Portal fictícios, memberships e recursos privados demonstrativos. Se a frente de PostgreSQL for autorizada separadamente, runtime de laboratório sem superuser, BYPASSRLS ou DDL; demonstrar FKs tenant, restrições de leitura/escrita, revogação sob concorrência e ausência de vazamento na reutilização de conexão. O experimento inicial não precisa criar login ou MFA operacionais e jamais publica rotas para clientes.

**Critérios de parada:** uso acidental de dados reais, custos não autorizados, credenciais compartilhadas, role runtime com BYPASSRLS/DDL, fonte de identidade forjável como se real, vazamento entre organizações, cache de permissão revogada, falta de rollback, logs insuficientes ou resultado negativo inesperado. Falhar fechado e parar, sem promover para outras branches.

## 4. Portões independentes

- Diretriz documental D06: em avaliação.
- Contratos e revisão de Segurança/Arquitetura/DBA/Privacidade: pendentes.
- Autorização de laboratório/SQL/migrations/ambiente: NÃO concedida.
- Execução de ensaios negativos PostgreSQL e autenticação reais: NÃO realizada.
- Merge de PR #24 e PR #19: NÃO autorizado.
- Implementação de login real, MFA, sessão e navegação para cliente: NÃO autorizada.
- Merge na main, VPS e produção: NÃO autorizados.

## 5. Decisões novas para avaliação, não ratificadas

- **SEC06-D01 — A recomendada:** adotar a ordem de evidências 1–8 como checklist único, sem exigir aprovação genérica a cada novo texto. B: reordenar com justificativa de risco.
- **SEC06-D02 — A recomendada:** primeiro demonstrar emissor/membership/tenant em laboratório sintético sem login/MFA reais. B: autenticação e banco juntos, maior superfície.
- **SEC06-D03 — A recomendada:** manter o PR #19 documental separado, reconciliar dependências por referência, sem merge implícito. B: consolidar após homologação específica.
- **SEC06-D04 — A recomendada:** não expor painel/rotas de cliente até segurança de identidade, isolamento e E2E negativo homologados. B: piloto privado sujeito a autorização e avaliação distintas.

**Próxima ação produtiva:** completar os contratos de emissor Portal e membership canônico com parecer técnico, e conciliar os requisitos G-BB03/04 do PR #19. Não iniciar programação por inferência.
