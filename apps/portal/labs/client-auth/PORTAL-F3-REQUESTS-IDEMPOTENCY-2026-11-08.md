# PORTAL-F3 — Idempotência de solicitações por empresa (laboratório PostgreSQL 18)

**Data:** 08/11/2026. **Escopo aprovado para desenvolvimento autônomo:** implementação e testes sintéticos, sem acesso a clientes, VPS, produção ou segredos reais. Branch filha do PR #81.

## Contrato de gravação experimental

- Tabela `lab_portal_requests`: novos campos anuláveis `idempotency_digest` e `payload_digest`, com restrição única **(org_id, idempotency_digest)**. Campos anuláveis preservam a regressão dos pedidos F1 antigos, sem obrigar alteração retroativa.
- Nova operação `submitIdempotentRequest`, utilizável **somente** após verificação da sessão MFA F2. O gateway verifica membership ativo e empresa ativa, define contexto transacional de tenant/ator, comprova que o serviço pertence à mesma empresa e submete ao RLS `WITH CHECK`.
- A chave de idempotência tem de ser fornecida (8–128 caracteres alfanuméricos e hífens) e é armazenada somente pelo digest SHA-256. O conteúdo da solicitação é identificado por digest de `[service_id,title]`.
- Primeira submissão autorizada: **201** com identificador e `replayed=false`; reenvio com chave e payload iguais: **200** com mesmo identificador e `replayed=true`; mesma chave com payload diferente: **409** sem recriar; empresa, vínculo ou serviço incorretos: **404**; sessão inexistente/revogada: **401**; chave ausente/formato inválido: **422**.
- A chave é escopada por empresa. Duas empresas diferentes podem usar o mesmo valor de chave sem colisão; não autoriza consulta cruzada.
- Não há integração com CRM, n8n, sites, atendimento, pagamento ou notificação: todo pedido é exclusivamente fictício, em banco descartável no runner.

## Garantias e limitações distintas

**Evidências pretendidas:** unicidade e replay sequencial, isolamento A/B, não reabrir pedido após revogação e rejeição de payload alterado. A UNIQUE composta no PostgreSQL protege contra duplicação de chave no armazenamento.

**AINDA NÃO COMPROVADOS:** teste simultâneo com múltiplas conexões (concorrência real), linearizabilidade de gravação versus revogação, replay cross-worker/outbox, lock externo, geração de chave pelo browser, integridade de dados de clientes, recuperação de backup, CORS/CSRF HTTP real, antiabuso distribuído e segurança de Z2 comprometida. Uma checagem de vínculo antes da escrita **não é suficiente** para garantir corte linearizável frente à revogação concorrente; esse é um gate específico futuro.

A F3 mantém a política de dados fictícios e a inclusão restrita de rotas no Laravel somente com `APP_ENV=testing`, `PHP_SAPI=cli` e `PORTAL_CLIENT_LAB_ENABLED=1`. Os testes anteriores F1/F2 devem continuar passando. Sem migrations em ambiente real, merge, deploy ou contratação. P0, D02, D03/RLS, SEC-R03/T3, privacidade e release **ABERTOS**.
