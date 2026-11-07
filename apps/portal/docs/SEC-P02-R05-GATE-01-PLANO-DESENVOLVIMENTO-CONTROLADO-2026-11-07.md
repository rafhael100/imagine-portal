# SEC-P02-R05-GATE-01 — Sequência de eventual desenvolvimento controlado e política GO/NO-GO

**Data:** 07/11/2026. **Estado:** PLANO DOCUMENTAL PARA AVALIAÇÃO DA DIREÇÃO; NÃO É ORDEM DE PROGRAMAÇÃO OU IMPLANTAÇÃO.
**Dependências:** R05-IMPL-01 e R05-QA-01 desta entrega; R04-DEC-02 ratificada documentalmente; R03-HIER-01/PG-01, PR #19 (PostgreSQL), PR #24 (identidade), PR #33 (filha de documentação). Todos abertos/sem merge na verificação preparatória.

## 1. Requisitos inegociáveis de qualquer futuro recorte

- **Escopo e ambiente próprios:** branch filha isolada, commit SHA, plano de rollback, fixtures fictícias, CI/provas registráveis, nenhuma alteração na main ou produção por implicação. Não reutilizar credenciais, sessões ou dados CRM.
- **Poderes:** o responsável aprova cada autorização. Parecer AppSec/DBA é evidência consultiva — pode apontar impedimento — e não delegação da decisão do Produto.
- **Bloqueios:** todos os cinco P0, G-ID01..09, G-BB01..08, D02-SEC01..06, D03 físico/RLS, H/C/P e release seguem ABERTOS até prova e sign-off separado. Escolha documental ≠ implementação ≠ evidência ≠ homologação ≠ autorização de deploy.
- **Sem funcionamento parcial inseguro:** demonstrativos e domínio puro podem continuar isolados, mas não transformar demo/membership em middleware autenticador, não habilitar endpoints com token falso, não liberar DML tenant sem RLS/testes, não incluir usuário real antes de autorização.
- **Não misturar a árvore de dependências:** PR #33 é filho documental de PR #24; PR #19 é paralelo. A migração entre branches e a integração física exigirão plano específico de precedência/rebase e revisão do diff, jamais merge silencioso.

## 2. Marcos técnicos sugeridos — todos requerem autorização futura explícita

| Marco | Ação futura isolada | Evidência de aceite/NO-GO |
| --- | --- | --- |
| F0 — Fechar fonte de identidade/ameaça | Especificar issuer/sessão/MFA e validar ameaça SQL/risco ainda não aceito, além de bootstrap/último gestor | NO-GO se identidade puder vir de CRM/DTO; se o modelo ameaçado exigir resistência a runtime hostil e a opção A não a fornecer |
| F1 — Inventariar autoridade e dados | Mapa fechado de writers, ERD tenant, roles/GRANTs/RLS por DML, locks e invariantes por ação | NO-GO se um writer contornar barreira, houver caminho privilegiado ou FK não carregar tenant |
| F2 — Laboratório sintético exclusivamente após novo aceite | Implementar contratos mínimos em branch isolada com DB descartável, migrações de teste, papel SQL runtime, dados de empresas A/B fictícios | NO-GO se não há autorização expressa de laboratório, recursos, custos e isolamento; não integrar VPS nem CRM |
| F3 — QA de identidade, concorrência e isolamento | Executar R05-01..22, ID-A/REV/H-C-P e evidências de negative cases/carga; corrigir falhas documentadas | NO-GO se não há prova de ausência de efeito, restauração ressuscita acesso, role bypass ou contexto forjado fora da ameaça aceita |
| F4 — Revisão independente, reconciliação e autorização de merge | Registro de riscos e pareceres por SHA, CI, revisão humana dos caminhos críticos, decisão explícita da Direção sobre gates e merge específico | NO-GO se conflitar com ADR aprovado, persistir P0 sem tratamento ou confiar só no CI verde |
| F5 — Pré-produção/prod somente por ordem futura distinta | Backups testados, observabilidade/retention, segredos, incidentes, restore, rollout e rollback, carga/privacidade | NÃO AUTORIZADO pelo presente plano; release jamais automático |

**Sugestão de primeiro recorte futuro:** F0 + F1 documentais e revisão das lacunas antes de construir banco ou auth real. Separar eventual autorização para fixtures e DDL do laboratório de qualquer deploy na VPS. Nenhum marco acima foi executado nesta entrega.

## 3. Padrão do relatório que deve acompanhar qualquer implementação posterior

Título e SHA/branch exatos; dependência de PR e origem da autorização; ambiente e versões verificadas, listagem de arquivos/PR/diff, comandos/testes realmente executados e suas saídas, falhas e bloqueios, evidência negativa por cada caso R05 e histórico ID-A/REV, resultados reproduzíveis e risco residual. Um teste que não rodou deve estar como NOT RUN/BLOCKED, nunca PASS.

**Aprovação da R05:** aceita apenas que este caderno sirva de material para a decisão futura. Não fecha gates, não escolhe especialistas, não aceita risco residual e não autoriza código/SQL/testes reais/banco/VPS/merge/produção.
