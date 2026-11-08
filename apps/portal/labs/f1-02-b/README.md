# PORTAL-F1-02-B — Laboratório sintético de identidade e sessão (PG18/PDO)

**Autorização:** Direção aprovou F1-02-B após Issue #45 e PR #46, exclusivamente como laboratório isolado, fictício e sem merge.
**Não é componente operacional do Portal.** Não adicionar ao autoload nem conectar middleware, API, login, cookie, MFA real, CRM, VPS ou dados de clientes.

## Isolamento e execução
- PostgreSQL 18 descartável em service container do GitHub Actions, PHP 8.4 com PDO_PGSQL. Nunca executar a migration deste laboratório em outros ambientes.
- O script bloqueia DSN diferente de localhost:5432 com banco portal_f1_02_lab, exige variável de aprovação PORTAL_LAB_ALLOW=1 e confere servidor PG18 e PHP/PDO.
- O usuário administrador do container e a role SQL limitada usam senhas sintéticas efêmeras. Nunca reutilizar em sistemas reais.
- Schema portal_lab isolado; tabelas para contas, desafios, sessões, memberships multiempresa, recursos, efeitos e FK composta. Roles RLS exemplificam defesas e limites, não a identidade Portal real.
- Os dados A/B são inteiramente fictícios. A execução não habilita qualquer endpoint; testes não chegam ao ambiente operacional.

## Matriz de experimentos
| ID | Cenário | Limitação |
| --- | --- | --- |
| LAB-T01 | Token de CRM e ID textual não constituem verificador de sessão Portal | Sem autenticador real ou assinatura |
| LAB-T02 | Challenge de login de uso único, propósito incorreto e expirado | Sem OTP, fator real ou proteção antiabuso |
| LAB-T03 | Sessão sintética vencida, sem fator ou epoch/revisão obsoleta | Não comprova MFA real |
| LAB-T04 | Lock PostgreSQL de conta em concorrência com suspensão; efeito anterior ao corte | Não cobre todos os writers nem todo grafo P→A→S→O→M→R |
| LAB-T05 | FK composta recusa recurso de organização B em efeito de A | Não testa canais laterais de erro |
| LAB-T06 | Runtime sem privilégios elevados, RLS omite tenant sem contexto e limita org por SET LOCAL | Não autentica ator no SQL |
| LAB-T07 | Contexto tenant forjado via SQL dá acesso indevido: contraexemplo intencional | R01-10 permanece ABERTO |
| LAB-T08 | Incremento de epoch invalida sessão sintética anterior | Não testa restore ou sessões reais |
| LAB-T09 | Insuficiência de grants SQL e policy para efeito fora da org corrente | Não equivale a autorização ponta a ponta |

Um workflow verde comprova somente que o laboratório rodou. O script emite o resultado **KNOWN GAP R01-10** se comprovar que SQL pode falsificar o contexto RLS — isso não é aprovação da segurança. Casos de restore, sessão real, MFA, autorização por HTTP e todos cinco P0 continuam **ABERTOS**.

**Governança:** PR draft contra dev, main intocada, sem merge, VPS, PostgreSQL operacional, testes com pessoas reais, alterações de custos ou deploy.
