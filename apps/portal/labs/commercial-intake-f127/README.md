# Portal F127 — Leitura de revisão com privilégios mínimos

**Somente GitHub Actions / PostgreSQL 18 descartável / PHP 8.3 e 8.4.** Sem login operacional, endpoint HTTP, credenciais de cliente, CRM real, dados pessoais, VPS ou deploy.

## Problema

A VIEW F125 `delivery_review_queue` tem RLS por empresa e retorna somente metadados de submissões incertas. Contudo, F125 ainda concedia `SELECT` direto a papéis de worker do laboratório. Isso é insuficiente como demonstração do futuro fluxo de revisão humana, que deve depender de **permissão explícita** e recusar consultas após revogação de sessão, associação ou MFA.

## Alterações em F127

1. Revoga o `SELECT` direto de `delivery_review_queue` e da tabela `delivery_gate` dos workers F122, sem alterar as funções guardadas de escrita.
2. Cria apenas no banco descartável a tabela `review_authority_lab`, sem e-mail, bearer, token ou payload; o dado de autorização é sintético e começa vazio. São verificadas permissão exata `commercial.delivery-review.view`, vínculo ativo, sessão ativa, MFA verificado, expiração e igualdade das revisões de segurança.
3. Cria `review_items_lab_a()` em papel exclusivo `portal_f127_review_owner` que é `NOLOGIN/NOSUPERUSER/NOBYPASSRLS`. O proprietário possui `SELECT` mínimo e policies FOR SELECT com `FORCE RLS` nas tabelas subjacentes. O retorno usa a VIEW `security_invoker=true`, preservando a delimitação da empresa A.
4. Somente `portal_f127_reviewer_a` pode executar a função. O papel B não tem EXECUTE nem SELECT. O operador A não recebe privilégios de INSERT/UPDATE/DELETE, não pode mexer na própria concessão nem no estado da entrega.
5. Todos os itens revisáveis mantêm `automatic_retry_allowed=false`, `real_crm_persisted_verified=false` e `can_acknowledge_visitor=false`. Nenhum ACK é fabricado.

## Testes

A suíte inicia as fixtures F117–F125, instala F127 e verifica consulta vazia **sem** concessão, negação com grant `services.view`, acesso sintético com grant exato, isolamento de empresa, papéis mínimos, tentativa de alterar a ledger e a `delivery_gate`, revisão obsoleta, sessão revogada, vínculo revogado, MFA ausente e sessão vencida.

## Bloqueios para operação

Este papel de CI não autentica um ser humano nem prova propriedade operacional de um recurso. Seu ator e empresa são fixos e fictícios no SQL. A ponte real entre sessão Laravel autenticada, identidade em PostgreSQL, emissão transacional, MFA, RLS, revogação e revisão humana ainda precisa ser projetada e revisada por AppSec/DBA independentes. Não converter este `SECURITY DEFINER` de laboratório em migration de produção.

Permanecem bloqueados: publicações, captação de leads real, CRM real, painéis de clientes e merge na `main` até aprovação específica e homologação. A revisão está rastreada em [#315](https://github.com/rafhael100/imagine-portal/issues/315).
