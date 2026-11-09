# PERF-F10 — Vínculo de sessão MFA e serviço para emissão sintética de Performance AI

**Data-base:** 08/11/2026. Issue #97. Branch `feat/portal-perf-f10-mfa-grant-issuer-lab-2026-11-08` filha de F7 (PR #92), PR **draft**.

## Objetivo
Comprovar num laboratório de PostgreSQL 18 descartável que o Portal não emite nem mesmo um envelope **fictício** para Performance apenas porque um cliente forneceu organização, tenant e serviço. O Portal é autoridade de identidade: sessão MFA validada no banco, associação à organização, estado da empresa, serviço correto e revisão vinculada são verificações obrigatórias antes da assinatura de teste.

## Implementação
- `labs/client-auth/LabPerformanceMfaIssuer.php` usa o já existente `LabSecureLogin::actor(Request)`: sessão de laboratório com TOTP, hash de token em PostgreSQL, expiração, revogação e revisão de conta. Nada é decidido por ID de ator enviado no corpo da requisição.
- Verifica conexão `portal_lab_runtime` de privilégio restrito. Executa transação e o lock compartilhado `LabMembershipFence::lockWriter` (F4), revalida a sessão e membership, status da empresa e da conta, revisão ligada à oferta demonstrativa.
- Consulta `lab_portal_services` sob RLS e `set_config(..., true)` local à transação. Usa allowlist estritamente fictícia `org-b/service-b → org-demo-b/perf-demo-b/svc-demo-b`. Não confunde Onepage (`service-a`) com Performance.
- O envelope é assinado exclusivamente com `random_bytes(32)` fornecido em teste e contém SHA-256 de JSON estático. Não transporta senha, e-mail, cookie nem ID de usuário; **não é um token de serviço real**.
- O protocolo F1-07 do motor e a fixture F1-06 foram copiados **sem alterações de bytes**. SHA dos blobs origem: protocolo `6ff4d86fcc127a2081239de0fd45147b87ff9bad`; fixture `9248b6681022b710e6db934add56ddcca48b52d0`.
- Testes no `LabPortalPgFeatureTest`: permitem uma única emissão com MFA/serviço válido, validam assinatura/replay e negam cruzamento de empresas, serviço estranho, sessão ausente/revogada/expirada, revisão de conta, organização suspensa e membership revogada. Reativação sem rehomologação de revisão não reabre grant antigo.
- Workflow F10 roda suite PostgreSQL antiga + novos cenários; não liga rotas nem publica API.

## Limites críticos
**Este código não entrega SSO, S2S autenticado, certificado, OAuth, webhook nem autorização para clientes.** A role, a sessão e o serviço são **fictícios**. A allowlist não representa plano, preço, assinatura nem contrato; o protocolo de assinatura tem chave efêmera e grants de laboratório.

A coordenação com `LabMembershipFence` só protege escritores participantes na mesma instância PostgreSQL. Atualização de conta por ator privilegiado sem lock, sistemas distribuídos e restore de backup podem retroceder ou ignorar barreiras. O Performance não possui confirmação transacional desta sessão, nem mecanismo de autenticação de workload real. Não implantar nem usar no `main`, VPS ou serviço operacional; revisar AppSec e recuperação antes de avançar.

**Gates preservados:** PR draft filho de #92, em paralelo à prévia #96; sem merge automático, despesas, pagamentos, contas publicitárias, dados pessoais ou produção. Mantêm-se os 91 P0, 15 HT-R01 e SEC-R03/T3 em aberto.
