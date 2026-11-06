# F1-01-B-B-DEC-06 — Condições documentais do futuro laboratório

**Data:** 06/11/2026, America/Sao_Paulo. **Estado:** condições documentais aprovadas parcialmente; teto financeiro pendente. Não autoriza execução de laboratório.

**Manifestação do responsável:** “custo, podemos ter custo sim desde que controlado e baixos nessa etapa incial / sobre os demais pontos, ok”, em resposta à proposta de ambiente GitHub Actions temporário, limite de 30 minutos por perfil PHP e descarte/guarda de evidências por 30 dias, exclusivamente como condições documentais do futuro laboratório.

## Decisão registrada

| Item | Aceite do responsável | Pendência preservada |
|---|---|---|
| Ambiente | GitHub Actions temporário do repositório privado como ambiente proposto, sem VPS/produção | Comprovar disponibilidade, quota, isolamento e configuração planejada antes da autorização de execução |
| Duração | Até 30 minutos por perfil, PHP8.3 e PHP8.4 separadamente, com cancelamento ao atingir limite | Detalhar timeout/limpeza; não autoriza repetição ilimitada de jobs nem substitui teto agregado |
| Dados/descarte | Somente dados fictícios; volumes e fixtures descartados ao encerrar | Procedimento de limpeza, responsável e prova permanecem necessários |
| Provas | Relatórios sanitizados guardados por 30 dias após resultado final, sem credenciais ou dados de clientes | Prazo aprovado apenas para provas sintéticas do laboratório; não define retenção LGPD de produção |
| Custo | Admite custo inicial **baixo e controlado**, substituindo a proposta de R$0 adicional | Nenhum valor/teto numérico aprovado. Estimativa, limite agregado, alertas e regra de parada devem ser apresentados antes de executar |

Não interpretar “baixo e controlado” como orçamento ilimitado, contratação ou autorização imediata de gasto. Antes do primeiro job pago, o pacote deverá registrar teto monetário total da etapa, estimativa fundamentada por executor/armazenamento/retentativas, monitoramento, responsável e parada preventiva. Limite por job não controla sozinho a soma de repetições e artefatos. Se a plataforma não oferecer bloqueio financeiro automático, explicitar esse limite e propor controle alternativo antes do aceite de execução.

## Precedência e bloqueios

Complementa a DEC-05 e o [D07](PORTAL-F1-01-B-B-D07-CONSOLIDACAO-PARECERES-PRE-LAB-PG18-2026-11-06.md), no [PR #25](https://github.com/rafhael100/imagine-portal/pull/25). PG18, Laravel13, PHP8.3/8.4 e PDO direto não persistente permanecem baseline documental; PgBouncer continua adiado.

O aceite de condições pelo responsável não emite parecer independente de Identidade/DBA/Segurança/Privacidade/QA, não comprova quota, não escolhe imagens/digests e não encerra G-BB ou demais gates. Mantêm-se os pareceres P01–P06 da Issue #26, o teto financeiro e a ordem específica de código/testes pendentes. O registro anterior de NO-GO do D07 continua aplicável até completar essas condições e obter autorização separada.

**Não autorizados:** provisionamento, despesa executada, código funcional, SQL/migrations, banco/testes PG/RLS, login/MFA, CRM, dados reais, merge em qualquer branch, alteração dev/main/VPS ou deploy. PR #25 permanece draft; PRs paralelos e Issue #17 preservados.
