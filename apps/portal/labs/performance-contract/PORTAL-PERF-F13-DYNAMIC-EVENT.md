# PORTAL-PERF-F13 — Exportação real do evento fictício MFA/outbox do Portal

**Issue #108, branch filha do PR Portal #107.**

O objetivo desta etapa é substituir o evento de demonstração com ID fixo por um **evento criado de verdade durante o teste** por `LabPerformanceRevocationPublisher::revokeForLab`, mantendo todas as validações de sessão MFA fictícia, associação à empresa, serviço e transação outbox F11.

`test-perf-f11-revocation.php` só exporta o objeto depois de uma revogação validada quando `PORTAL_F13_EVENT_EXPORT` aponta estritamente a `RUNNER_TEMP/portal-perf-f13-event.json`. O ID `evt-demo-...` é gerado pelo Portal em cada execução. Nenhum segredo, e-mail, token, conta ou dado pessoal integra o evento.

`export-signed-perf-event.php` usa o `SyntheticWireEnvelope` de F12 com chave HMAC aleatória do job para assinar **os bytes exatos do evento exportado**, e salva apenas headers de teste em `RUNNER_TEMP/portal-perf-f13-wire-headers.json`. A chave não é gravada no repositório e o CI usa somente um processo PHP CLI. Em seguida a assinatura é conferida ainda no próprio Portal.

O motor Performance F1-14 fará checkout da versão **fixa por SHA** desta branch pública para repetir o teste e entregar exatamente esse evento e esses headers ao receptor HTTP local F1-13. Isso prova um fluxo completo **em um runner isolado**, não conexão operacional entre servidores.

**Limites:** apenas fixture dinâmica fictícia, PostgreSQL descartável, sem API operacional, TLS/mTLS, segredo real, usuário/contrato comercial, serviço em produção ou conexão externa de aplicações. Ainda faltam identidade de workload e autoridade independente a backup/restore. Os gates SEC-R03/T3, P0 e HT-R01 permanecem abertos, e PR não deve ser mesclado automaticamente.
