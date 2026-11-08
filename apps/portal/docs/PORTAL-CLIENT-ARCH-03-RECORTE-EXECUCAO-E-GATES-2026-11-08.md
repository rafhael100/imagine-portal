# PORTAL-CLIENT-ARCH-03 — Ordem executiva futura para primeira área de cliente

**Status em 08/11/2026:** plano para aprovação por recorte técnico. A Direção aprovou seguir do visual PVIEW-03 para a preparação do Portal real. **Não houve autorização para implementar login, SQL funcional, dados reais, deploy, merge ou liberar P0.**

## 1. Resultado esperado, com definição verificável

Um cliente poderá entrar com uma conta **Portal Imagine própria**, escolher somente organizações em que possui vínculo atual e acessar uma visão de serviços vinculados a essa organização. Nenhum produto CRM/Performance/Sites pode ser acessado por login compartilhado, headers de cliente ou afirmação do navegador. A Universidade deve testar elegibilidade real pela fonte autorizada; nenhuma tela de preview concede acesso.

## 2. Fatias progressivas

| Ordem | Recorte | Produto verificável | Bloqueio atual |
|---|---|---|---|
| C01 | Cadastro/conexão de identidade Portal, política MFA e sessão | Contrato e testes de emissão/revogação independente do CRM | D02-SEC01, privacidade e antiabuso |
| C02 | Organizations/memberships/grants/ownership em PostgreSQL laboratório | DDL e roles de laboratório, PK/FKs compostas, RLS, fixtures A/B | D03/RLS, gate de execução SQL |
| C03 | Adaptadores confiáveis das interfaces já existentes | SessionAuthority, MembershipAuthority, ResourceAuthority conectadas a fontes autoritativas somente em lab | P0, identidade, isolamento e concorrência |
| C04 | Middleware e transações com boundary de autenticação real | Nenhuma rota privada é liberada sem sessão, org e resource ownership, CSRF | C01–C03 e revisão de segurança |
| C05 | Lista de organizações e serviços autenticados | Blade com view-model mínimo somente de dados autorizados, teste A/B e mudança de org | C04 e regras de titularidade |
| C06 | Solicitações, contratos, produto e integrações | Contratos e APIs versionados por produto, idempotência, auditoria, rate limits | C05 e autorizações próprias de serviço |
| C07 | Backup/recovery/operacionalização | Restore sem ressuscitar sessão/grant, workers e outbox auditados | SEC-R03, revisão e aprovação de release |

**Não vincular C05 a billing antes do C06.** Sempre que possível, liberar primeiro telas informativas sem efeitos, mas apenas após um autenticador real, tenancy e gates próprios. Não criar atalho com cookie mock ou autorizações de PVIEW.

## 3. Subconjunto de contratos que precisa ser decidido antes do código real

1. Cadastro por convite proveniente de vínculo contratual verificado, alternativa de cadastro público e verificação de domínio/contato; recomendação inicial: **convite verificado** para reduzir risco de associação incorreta.
2. MFA: estratégia para operadores e clientes (recomendação preliminar: MFA obrigatório para administradores e ações sensíveis; perfil dos clientes a deliberar); recuperação não poderá ser um bypass silencioso.
3. Revisão de sessões e revogação global/dispositivos, timeouts e restrição de tentativas/abuso; decisão coordenada com backup.
4. Organizações múltiplas, administradores, delegações, remoções, auditoria, suspensão e versão de concessões.
5. Escopo RLS/roles e serialização de revogação/commit; transação correta com connection pools e workers.
6. Outbox, pagamento, CRM, agentes, Universidade, Performance e Sites como integrações independentes, sem compartilhamento de identidade.
7. Plano de recuperação independente do snapshot, com autoridade de geração/revogação confiável ou alternativa explicitamente aprovada e demonstrada; jamais promover o gate temporário da PoC como solução real.

## 4. Matrizes de QA e critérios objetivos

- **CI puro (autorizado neste PR):** negar em 8 seções de preview com fontes padrão; mesmo candidato sintético positivo **canAuthorizeHttp=false**; troca org A/B, titular B, sessão e membership revogados, permissões não concedidas, wildcard, referência CRM e erro de autoridade => resultado bloqueado. Esses testes não usam SQL e não comprovam autenticação real.
- **Próximo CI de laboratório (não autorizado neste PR):** testes PG18 roles + RLS + FK composta, pool de conexões, revogação/commit e isolamento concorrente com dados fictícios.
- **QA de produto (não autorizado neste PR):** login real de teste com identidade verificada, MFA/step-up, CSRF/fixation, segurança de armazenamento, reset/logout, acessibilidade e troca de empresa. Nenhum usuário real.
- **QA de continuidade (não autorizado neste PR):** snapshots em momentos distintos, Z4 independente, revogação tardia, workers/cache/outbox, rollback do host, falhas externas, gates OPEN/deny.
- **Go/no-go:** evidências com SHA/log em CI, inventário de writers, gates D02/D03/P0, revisão interna documentada, aprovação gerencial específica. Ninguém declarará homologação geral por PHPUnit ou preview funcionando.

## 5. Regras de governança

- PVIEW-03 foi aprovado como base visual; o responsável **não declarou inspeção visual de todas as capturas, leitor de tela ou zoom 200%**. Esse QA permanece distinto e pode ocorrer em paralelo à fundação.
- Preserve PR #75 e PRs técnicos anteriores como histórico sem merge implícito. Uma aprovação para estudar contrato não promove a main, dev nem habilita rota privada.
- Nenhuma exclusão/reescrita de código legado/branches nesta fase. Se reconstrução for recomendada, registrar evidência de incompatibilidade e substituir sob PR controlado.
- VPS de desenvolvimento, Cloudflare, banco real, usuários, credenciais, backup e fornecedores não foram acessados/alterados por este lote.
- Gates D02-SEC01–06, D03/RLS, P0 R01-01/02/03/09/10, G-ID/G-BB, SEC-R03/T3 e release: **ABERTOS**.
