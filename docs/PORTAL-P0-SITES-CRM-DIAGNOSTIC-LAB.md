# P0 — Diagnóstico Sites → CRM após checkout comercial (somente CI, NO-GO)

## O que o teste executa

Esta etapa dá continuidade **na mesma execução de CI e nas mesmas bases PostgreSQL 18 descartáveis** às quatro compras fictícias do Imagine Comercial → Portal → Sites da PR #683:

1. Chromium parte do **build real Astro** do Comercial e cria quatro pedidos fictícios no Laravel do Portal, gravando briefings no PostgreSQL18 sob RLS/CSRF.
2. Dois pedidos Site elegíveis (Onepage e Landing Page) são convertidos com contratos e HMAC **sintéticos** em duas admissões PostgreSQL18 no Sites; os dois Performance ficam fora desse motor.
3. O trabalhador de laboratório do Sites adquire e confirma exatamente dois eventos outbox por token de lease. **Este ACK não publica site nem entrega lead.**
4. O novo teste lê **os dois eventos e suas admissões reais** na mesma base Sites, gera somente **dois rascunhos de metadados** de lead com UUIDs fictícios e usa as classes de integração reais, com pins SHA exatos:
   - Sites lead draft / preflight: `057b6f859df5050c842a86d18676e6332a79d1f9`
   - CRM Core `SyntheticLeadEnvelopeV1Inspector`: `58ac8ee32483c9f6b70af95eac7207a7a9163200`
5. Para ambos, o CRM confirma apenas `STRUCTURE_ONLY_UNVERIFIED`; a preflight Sites retorna `AUTHORITY_UNAVAILABLE` ou, mesmo com fonte artificial correspondente, `AUTHORITY_MATCH_UNVERIFIED`. Ambas recusam aceite/ACK/entrega. Testes negativos cobrem empresa forjada, binding inativo, campos pessoais extras, versão divergente e destino modificado.

**Resultado obrigatório:** dois eventos Sites processados, dois diagnósticos metadata-only, **zero lead criado, zero submissão gravada, zero autorização CRM**, nenhum conteúdo de formulário ou dado de visitante.

## Evidência para auditoria

O job produz `p0-sites-crm-deny-evidence.json` com contagens, SHAs e flags `false` / `NO_GO`, sem dados de clientes, identificadores brutos dos pedidos/tenants ou credenciais. O campo SHA256 protege a **integridade**, não é assinatura nem comprova aprovação. O artefato de sete dias se chama `imagine-p0-sites-crm-deny-diagnostic`.

## O que esta integração NÃO faz

Não há dados de leads, consentimento LGPD, T0 durável, autenticação service-to-service, vínculo oficial CRM/empresa/usuário, recebimento API, deduplicação operacional do CRM, fila real, recibo de entrega ou notificação de visitante. A autoridade fictícia usada apenas para teste **nunca autoriza**. A aprovação fictícia do pagamento é cenário de laboratório. Os testes não executam na VPS, não criam tabelas do CRM, não enviam dados e não liberam publicação.

**Gates antes da integração operacional:** principal MFA e ownership reais no Portal; witness de revogação resistente a rollback, contrato e pagamento verificados, escopo de Site publicado com vínculo CRM assinado, consentimento explícito, limites de retenção/LGPD, durabilidade idempotente do endpoint CRM, reenvio/ACK pós-commit, homologação privada na VPS e revisão independente AppSec/DBA.

**DRAFT/NO-GO. Não realizar merge/deploy automático nem habilitar captação real.**
