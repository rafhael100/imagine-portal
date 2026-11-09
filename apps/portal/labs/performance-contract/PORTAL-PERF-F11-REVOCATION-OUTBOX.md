# PORTAL-PERF-F11 — autorização de serviço e outbox fictícia compatível com Performance F1-11

**Data de referência:** 08/11/2026. Issue #103. PR draft filho do Portal #98, em paralelo ao Performance F1-11 PR #35. Todos os testes usam contas inventadas, sem efeitos externos.

## Objetivo e construção
O Portal é tratado como autoridade conceitual de identidade, vínculo e estado do serviço. No laboratório `portal_lab` PostgreSQL 18:
- `LabSecureLogin` e `LabPerformanceMfaIssuer` são reutilizados, incluindo TOTP fictício, sessão validada no banco e RLS por empresa.
- `lab_portal_perf_authority` mapeia `org-b/service-b` do Portal para `org-demo-b/perf-demo-b/svc-demo-b` do Performance. Estado `active` e `min_revision` são autoritativos **apenas nesse teste**; nenhuma cobrança, contratação ou pagamento.
- `LabPerformanceRevocationPublisher` verifica sessão MFA fictícia, organização e perfil de laboratório, adquire lock exclusivo F4, revalida associação, usuário e empresa ativos, bloqueia a linha do serviço e faz na **mesma transação SQL** (i) `active=false`, `revision=2`, (ii) insert do evento na `lab_portal_perf_revocation_outbox`.
- O antigo `LabPerformanceMfaIssuer` ganha uma checagem extra de concessão e revisão no banco **somente quando `PORTAL_PERF_F11_LAB_ENABLED=1`**. Os testes F1-F10 não usam a nova tabela e continuam intactos.
- O evento possui, nesta ordem, exatamente `event_id, service_ref, organization_ref, tenant_ref, source_revision, event_type, status, attempts` conforme o consumidor Performance F1-11. `perf-revocation-contract-v1.json` fixa o contrato de comparação; o evento não inclui sessão, e-mail, senha ou valor.
- O script `test-perf-f11-revocation.php` executa **48 asserções**: MFA, isolamento, permissões reais SQL, rollback por conflito de `UNIQUE(service_ref, source_revision)`, idempotência da revogação e bloqueio de emissão após a revogação, além da conformidade do evento.
- CI no runner público padrão, PHP 8.4, PostgreSQL 18 descartável; roda F1-F10 e depois F11. Sem ação de rede, webhook, fila, deploy ou alteração em produção.

## Limites explícitos
Esta é uma prova de **formato/contrato e fonte fictícia**, não uma ligação operacional de bancos ou rede Portal ↔ Performance. O Performance F1-11 roda em outros bancos efêmeros, com `perf_external_lab.mock_revocation_outbox` e `perf_external_lab.mock_authority_fences`; a fonte de teste do Portal possui o **mesmo formato exposto**, mas uma origem de armazenamento diferente. Nenhum processo transmite eventos entre os repositórios, nenhum certificado/mTLS/workload identity, segredo operacional ou contrato real foi criado.

O papel de publicação fictício usa a conexão administradora de CI. Não é um modelo autorizado de privilégios de produção. Lock F4 cobre apenas escritores participantes do mesmo PostgreSQL; revogação direta, restore do cluster completo, falhas transacionais distribuídas, eventual entrega exactly-once e compensação de anúncios/cobranças **continuam pendentes**.

O teste não substitui a outbox F7 de solicitações do Portal: ela permanece isolada e com suas garantias e limitações próprias. A outbox F11 é de **autorização de serviço**, com revisão monotônica; nenhuma entrega remota/ACK foi implementada neste recorte.

**Gates:** P0, HT-R01, SEC-R03/T3 e homologação de produção abertos. Sem merge, alteração em `main`, fast-track, VPS, Cloudflare, contas reais, dados pessoais, custos extras nem ativação do Performance.
