# PORTAL-R07-F2 — Protocolo de revisão INTERNA AppSec/DBA com IA e Direção

**Data da revisão de governança:** 08/11/2026.
**Precedência:** [GOV-SEC-INT-01 / Issue #58](https://github.com/rafhael100/imagine-portal/issues/58), **aprovada pela Direção**: revisões de fornecedores externos **não serão contratadas na fase atual**, sem novos gastos desse tipo. Este arquivo **substitui** a versão anterior do pacote que solicitava revisão humana independente obrigatória como gate do fluxo interno. A versão anterior permanece no histórico Git.
**Estado técnico:** revisão interna em preparação/executável; **nenhum P0 homologado**. IA não é parecer independente, e aprovação gerencial de modelo não equivale a prova de segurança operacional.

## 1. Responsabilidades

- **ChatGPT/Codex — análise e execução técnica auxiliar:** examinar o código, realizar threat modeling, identificar falhas, propor testes de segurança, criar branch/PR de laboratório, executar CI, registrar resultados PASS/FAIL/BLOCKED com SHA e logs; também registrar limitações e vulnerabilidades que os testes não cobrem.
- **Direção Imagine — aprovação gerencial:** definir níveis de risco aceitáveis, aprovar escopo/merge/deploy, revisar evidências, decidir prioridades e assumir conscientemente riscos residuais documentados. Evitar aprovações automáticas por silêncio.
- **Revisão cruzada interna:** separar, quando possível, autor da implementação de quem faz verificação; IA pode auxiliar a ambos, mas isso **não cria independência formal**. Para avaliações sensíveis, usar adversarial tests, fuzzing, análise estática, cenários negativos e logs do sistema real/sandbox como evidência.
- **Custos:** R$ 0 em consultorias terceirizadas; não contratar, contatar ou liberar credenciais a Conviso, Application Security Solutions, 4Linux, PostDB, EDB ou outros fornecedores. Usar infraestrutura/ferramentas já autorizadas, sem novos serviços pagos. Uma obrigação externa legal/contratual específica, se identificada, exige avaliação expressa e não é anulada por esta decisão.

## 2. Evidências já existentes e o que não provam

| Evidência verificada no projeto | Resultados | Limite relevante |
| --- | --- | --- |
| [PR #47](https://github.com/rafhael100/imagine-portal/pull/47), [PG18 Actions 42496521968](https://github.com/rafhael100/imagine-portal/actions/runs/42496521968) | Nove casos de laboratório sintético; LAB-T07 demonstra falsificação de GUC/RLS | R01-10 ABERTO, não declarar RLS resistente a SQL arbitrário na mesma role |
| [PR #51](https://github.com/rafhael100/imagine-portal/pull/51), [Actions 42503351851](https://github.com/rafhael100/imagine-portal/actions/runs/42503351851) | 20 verificações em três fases, Z1 separada de Z3 e chamadas de B negadas | Z1 com sessão A válida consegue ações permitidas A: T3 ABERTO |
| [PR #57](https://github.com/rafhael100/imagine-portal/pull/57), [Actions 42507392711](https://github.com/rafhael100/imagine-portal/actions/runs/42507392711) | 27 testes offline de intenção, revogação e replay com Python | HMAC/confirmador local e lock em memória são mock; NÃO provam MFA real, canal confiável nem transações distribuídas |
| [PR #49](https://github.com/rafhael100/imagine-portal/pull/49) | Modelo B escolhido como direção de planejamento | Arquitetura física, limites T3, custos e implementação continuam pendentes |

Todos esses PRs estão em draft e sem merge no momento da revisão. Revalidar status/SHAs antes de qualquer ação.

## 3. Revisão interna AppSec — [Issue #54](https://github.com/rafhael100/imagine-portal/issues/54)

- [ ] Inspecionar toda a origem de identidade Portal, sessão, cookie, MFA, fator/step-up, recovery e conta do último gestor; jamais aceitar actor/tenant/role vinda da Z1 ou do CRM como prova.
- [ ] Definir classificação C0..C3 de operações sensíveis e quais transações exigem confirmação em canal controlado por autoridade distinta da borda comprometida.
- [ ] Desenhar protocolo de intenção/digest/challenge/nonce ligado a ator, sessão, tenant, recurso, montante/destinatário e revisões; recusar mudança material, replay, desvio de propósito e cancelamentos incertos.
- [ ] Analisar WebAuthn/passkeys, RP ID/origin/challenge/UV sem confundir user verification com exibição confiável de detalhes da transação.
- [ ] Avaliar CSRF, SQL injection, cookies, brute force, limites de dados, autorizações de APIs, logs e segredo em repositório público.
- [ ] Explorar T3: borda com sessão válida pode agir em nome do ator, mesmo quando controles SQL Z1→Z2 estão funcionando; documentar residual e eventuais canais independentes.
- [ ] Reexecutar ataques negativos com dados sintéticos; registrar reprodução da falha e ausência/presença real de efeito. Não marcar TX-N01..N20 como executados sem prova operacional.

**Gate AppSec atual: BLOCKED PARA PRODUÇÃO.** Falta autenticação Portal e canal independente reais; nenhum parecer de auditoria independente será alegado.

## 4. Revisão interna DBA/PostgreSQL — [Issue #55](https://github.com/rafhael100/imagine-portal/issues/55)

- [ ] Inventariar todos os escritores e credenciais Z1/Z2/Z3, including job/n8n/CLI/migração/suporte/restore, e verificar falta de acesso SQL privado para Z1.
- [ ] Exercitar GRANT, role inheritance, EXECUTE/SECURITY DEFINER, owner, BYPASSRLS, SET ROLE, search_path, views, triggers e policies RLS de todos os tipos DML; GUC não é origem confiável.
- [ ] Confirmar FK composta/tenant, invariantes de ownership e não-enumeração sob erros de integridade.
- [ ] Definir e provar serialização de sessão, revogação, grant/owner/entitlement, lock order P→A→S→O→M→R, timeout/rollback/deadlock/write skew.
- [ ] Testar consumo único de confirmação junto a efeito/idempotência na unidade de autoridade, e outbox/fencing para serviços externos; não reutilizar allow enviado à borda.
- [ ] Simular quedas e backup/restore com epochs, recovery e logs; negar se houver dúvida, não ressuscitar sessão.
- [ ] Registrar versões PG/PHP, matriz de roles, comandos, commit SHA, consultas antes/depois, resultados e FAIL/BLOCKED em testes não executados.

**Gate DBA atual: BLOCKED PARA PRODUÇÃO.** LAB-T07 demonstra risco na role SQL controlável. A PoC Z1/Z2 é ilustração de fronteira, não prova de implantação real.

## 5. Ficha de evidência por teste interno

- Requisito/Gate, ameaça e escopo (T0/T1/T2/T3/T4);
- Branch, commit SHA, run/job GitHub Actions e ferramentas/versões;
- Fixture fictícia A/B, privilégios/roles, precondições e etapas reproduzíveis;
- Resultado observado no estado real (DB/auditoria), não só erro HTTP; PASS / FAIL / BLOCKED;
- Vulnerabilidades encontradas, correção e reteste;
- Hipóteses não cobertas, risco residual e manifestação expressa da Direção se houver decisão de aceitação;
- Não compartilhar credenciais, dados pessoais ou diagramas confidenciais em repositório público.

## 6. Critérios de saída

**Para documentação e laboratório:** governança interna aprovada, testes executados, PR draft sem merge e relatório com limitações, sem fechar P0 automaticamente.

**Para merge de código:** autorização expressa da Direção, SHA verificado, diff, ausência de conflito, CI, riscos e escopo aceitos para a branch de destino; gates preservados se ainda não operacionais.

**Para ativar cliente/produção:** comprovar implementação operacional das autoridades, testes adversariais e de regressão, proteção de dados e isolamento de tenants, cenários de falhas/restore e T3 conforme exigências; decisão gerencial documentada sobre riscos residuais. Se houver obrigação externa independente efetivamente aplicável, tratá-la antes da liberação.

**Cinco P0 e demais gates: permanecem ABERTOS até evidência e decisão específica. Não há permissão implícita de deploy.**
