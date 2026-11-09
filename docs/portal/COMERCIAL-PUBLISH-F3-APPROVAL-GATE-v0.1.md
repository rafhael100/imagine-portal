# Portal Comercial F3 — Aprovação editorial em laboratório, com segregação de funções

## Integração implementada
O emissor do Portal agora possui um caminho de emissão **que exige passar antes por uma política de aprovação**, usando dados sintéticos no CI:

1. Um editor identifica o mesmo `tenantId`, `requestId`, `releaseId` e hash SHA-256 da carga.
2. Um aprovador distinto registra decisão positiva e o mesmo escopo e hash, com papel `content_approver`.
3. Um publicador distinto registra a intenção pendente, dentro de janela de até 24h.
4. A política rejeita diferença de escopo/tenant, conteúdo alterado, execução com tempos incoerentes, atores repetidos, papéis inválidos e IDs já consumidos **quando o histórico confiável é fornecido**.
5. Só então o emissor sintético gera o envelope Ed25519, consumido pelo Astro no workflow PHP → build real.

## Arquivos
- `apps/portal/app/Domain/CommercialPublishing/PublicationApprovalGate.php`: validação funcional pura.
- `apps/portal/app/Domain/CommercialPublishing/ApprovedPublicCatalogIssuer.php`: orquestra política + assinatura, sem HTTP nem DB.
- `apps/portal/tests/Unit/PublicationApprovalGateTest.php`: testes positivos e negativos de separação de funções, temporização, escopo, integridade e replay.
- `apps/portal/tests/Contract/emit-signed-commercial-fixture.php`: passa pelo gate de aprovação no laboratório antes de assinar.

## Limites críticos
Os papéis/IDs são **dados de entrada declarados** e não claims autenticadas pelo Portal. Isto **não é segurança operacional**: para ligar em produção, a aplicação precisará obter identidade e permissões de sessões MFA confiáveis e registradas no banco (com tenancy/RLS), registrar aprovação e consumo em transação atômica, impedir replay/restauração e proteger chaves em cofre com rotação/revogação. Falta um serviço real de aprovação e publicação. O emissor baixo nível permanece um componente criptográfico e não deve ser exposto diretamente.

Não altera dados, DNS, produção, checkout, notas fiscais, pagamentos ou chaves reais. PR draft, gates mantidos.
