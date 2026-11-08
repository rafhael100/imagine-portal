# PORTAL-R07-F1-SEC-R02 — Inventário de escritores e fronteiras de restauração

**Data:** 08/11/2026. **Issue #63.** Estado: INVENTÁRIO PARCIAL E LACUNAS; não homologado. Governança de revisão interna [#58](https://github.com/rafhael100/imagine-portal/issues/58), sem consultorias externas.

## 1. Fonte e cobertura observada

Repositório \`rafhael100/imagine-portal\` na branch \`dev/fast-track-portal-2026-11-05\`, SHA \`47514f4eac64a3d7ab0a0924cc890fb5a124d8b8\`, examinado em 08/11/2026:
- \`apps/portal/routes/web.php\`: nenhuma rota de autenticação ou negócio, apenas o healthcheck \`/up\` via bootstrap;
- \`apps/portal/routes/console.php\`: comentário explícito sem comandos de negócio, filas ou integrações;
- \`apps/portal/tests/Feature/PortalAuthenticationRoutesClosedTest.php\`: rotas sensíveis permanecem 404;
- árvore da branch: **não identificadas** \`apps/portal/database/migrations\`, \`app/Jobs\`, \`app/Http\` ou \`app/Console\` operacionais;
- \`app/Domain\` contém modelos/domínios fictícios em memória, **não são escritores persistentes PostgreSQL**.

Este inventário cobre os caminhos observáveis nessa branch/commit. **Não equivale ao inventário de toda infraestrutura Imagine, de projetos CRM/Performance ou de serviços reais ainda não integrados.**

## 2. Matriz por classe de escritor

| Origem | Estado comprovável neste PR/branch | Permissão pretendida | Prova e lacuna |
| --- | --- | --- | --- |
| Cliente/Z1 web | Sem rotas operacionais; Z1 maliciosa sintética na PoC | Nenhum SQL privado; comandos fechados à Z2 | CI PR #51 prova rede/credential na topologia do laboratório, não produto real |
| Autoridade Z2 | PHP/PDO sintético \`authority/router.php\` | SELECT mínimo e INSERT tipado em \`r07.effect\`; revalida sessão/tenant/role | Laboratório prova acessos A/B e corrida **somente para writers envolvidos**; Z2 comprometida permanece risco |
| Scheduler/job de negócio | **Inexistente na dev**; role sintética \`r07_job_no_dml\` NOLOGIN no laboratório | Nenhum SELECT/DML privado direto; futuramente capacidade de serviço autenticamente emitida e escopada pela Z2 | SEC-R02 valida apenas ausência de privilégio SQL desse papel, NÃO autoriza jobs reais |
| Administração/atendimento | Nenhum console operacional Portal; runner \`lab_owner\` atua como administrador fictício | Writers privilegiados separados da Z1, controle de alçada, auditoria e mesmas barreiras de corte | SEC-R01 prova corrida de membership; SEC-R02 prova suspensão de conta/sessão sequencial; CLI real ainda não catalogado |
| Owner/migrator/DDL | \`lab_owner\` superuser no PG efêmero, **não** autoridade de HTTP | Acesso restrito off-path e governado; nunca credencial de borda | Pode DESABILITAR triggers, alterar grants e executar bypass: mitigação física e inventário de produção pendentes |
| n8n, CRM, Performance, webhooks | Sem integração operacional nesta branch | Identidades técnicas e intents fechadas; sistemas separados | Ainda não provado. Nenhum SSO ou DB compartilhado autorizado |
| Backup/restore | \`pg_dump\` / \`pg_restore\` exclusivamente do runner sintético | Restore bloqueado para acesso até reconciliar epochs, revogações, tokens, grants, migrações e auditoria | SEC-R02 reproduz risco de backup antigo; política de fencing externo ao snapshot e testes sob falhas **NÃO IMPLEMENTADOS** |
| Incidente, suporte de emergência | Não há fluxo operacional nesta branch | Dupla alçada quando aplicável, sem bypass silencioso de revogação | Sem implementação/teste |

## 3. Invariantes de segurança que precisam abranger TODO escritor futuro

1. Nenhum job, serviço, webhook, operador de suporte ou CLI pode atribuir ator, tenant, role, fator ou intenção humana por dados recebidos da borda Z1.
2. Cada mutação de dados privados precisa de autoridade no ponto do efeito, escopo tenant/capability, idempotência vinculada ao comando, logs mínimos e barreiras de revogação.
3. Todo writer **incluindo suporte e jobs** participa da mesma serialização/epochs/locks ou é explicitamente segregado em protocolo equivalente provado. Triggers sozinhos não impedem atuação de owner/superuser.
4. Migrations e restoring não podem reabrir estado antigo de autenticação. Uma fonte confiável monotônica **fora do domínio restaurado** (ou desenho alternativo comprovado) deve impedir serving até atualização/rotação/revogação reconciliada. A PoC atual **não possui** esse mecanismo.
5. Expiração da sessão ou revogação não pode ser burlada por cache de grants, conexão reciclada, retry, jobs ou operação externa após timeout.
6. A matriz de permissões deve conter privilégios SQL efetivos de todas as roles, inclusive herança, \`SET ROLE\`, \`SECURITY DEFINER\`, \`EXECUTE\`, \`BYPASSRLS\`, \`CREATE\` e ferramentas DBA.
7. Testar rejeição e ausência de efeitos com consultas DB, não apenas 403. Registrar versão PG, SHA, transações e tentativas negativas. Se falhar, manter gate BLOCKED.
8. Um sistema que não existe ainda na branch de desenvolvimento deve ser marcado **NÃO IMPLEMENTADO/NOT RUN**, nunca PASS.

## 4. Protocolos negativos desta etapa

- \`SEC-R02-J01/J02/J03\`: role sintética NOLOGIN sem acesso direto a \`r07.private_resource\`, \`r07.membership\`, \`r07.effect\` (SELECT/UPDATE/INSERT). São testes de GRANT, não de job real.
- \`SEC-R02-A01/A02\`: alterações administrativas de \`session.active\` e \`account.active\` são imediatamente reconhecidas pela Z2, negando leitura e efeito sob sessão A ainda não expirada. É sequencial, não nova corrida.
- \`SEC-R02-B01\`: dump anterior a revogação de \`session_epoch\` + restore em outro banco sintético. O sistema restaurado contém sessão que seria válida, enquanto o banco vivo nega. **KNOWN GAP**: ausência de reconciliação de restore fora do domínio restaurado.
- \`SEC-R02-B02\`: a base restaurada não é conectada à aplicação nem aberta a identidades Z1/Z2; containers e volumes descartados após CI.

## 5. Governaça / próximos gates

Apenas documentos e código de prova de conceito em PR #51, DRAFT, sem merge, VPS, serviços pagos ou dados reais. O usuário mantém aprovação final de evolução operacional. P0 R01-01/02/03/09/10, G-ID/G-BB/D02-SEC/D03/RLS e release ABERTOS. Revisão técnica interna AppSec #54 e DBA #55, **não independente**, e planos de restore/writer-map reais ainda faltam.

Não declarar “todos os escritores protegidos” até existir e ser testado o inventário de execução e credenciais de cada componente de produção.
