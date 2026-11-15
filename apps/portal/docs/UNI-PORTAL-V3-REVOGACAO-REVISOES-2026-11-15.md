# PORTAL-UNI V3 — revisão independente e revogação de serviços

**15/11/2026 · mecanismo candidato, não autorizador · DRAFT / NO-MERGE / NO-DEPLOY**

## Problema resolvido no contrato técnico

Uma confirmação antiga de serviço ativo não pode continuar valendo depois que o contrato é cancelado ou suspenso. O [Portal UNI V2](https://github.com/rafhael100/imagine-portal/pull/869) define os dados de contrato candidato, mas ainda não compara a revisão da fonte de contratos com uma leitura independente da situação atual.

Esta etapa acrescenta **dupla reconciliação sem concessão de acesso**:
- `UniversityServiceRevisionAuthority`: interface de futura fonte autenticada para últimas revisões e referências revogadas por empresa; sem adaptador de produção.
- `UnavailableUniversityServiceRevisionAuthority`: padrão **sempre indisponível**, sem conexões nem segredos.
- `UniversityServiceRevisionSnapshot`: organização, mapa de versões, referências revogadas e vigência limitada; um objeto criado por chamador **não é evidência de origem oficial**.
- `UniversityRevocationPreflight`: lê cada fonte uma única vez, congela o lote para evitar duas leituras divergentes, exige a mesma empresa, TTL fresco, mesma lista de contratos e revisões exatas, recusa serviço ativo marcado revogado. O encerramento do último serviço reduz candidatos a zero. Erros e fontes indisponíveis bloqueiam.
- `UniversityRevocationPreflightResult`: diagnóstico com contagem de candidatos; **mayIssuePortalGrant() e provesContractCurrent() sempre false**.

## Testes

O arquivo `UniversityRevocationPreflightTest` adiciona dez testes Unit: fontes ausentes, revisões iguais, último serviço cancelado, revisão posterior que veta contrato antigo, serviços múltiplos, troca de empresa, fontes incompletas/vencidas, lote inválido e exceções.

## Limites de implementação

A validação de revision **não é autorização de usuário, pagamento, contrato ou sessão**. A política principal `OrganizationAuthorizationService` continua recusando `university:view/manage`, e a Universidade usa provider `UnavailablePortalAuthority` deny-all.

Os dados de `portal_service_resource_claims` seguem apenas candidatos e **não são usados** nesta reconciliação. A origem oficial de serviços ativos (e sua fonte de revogação independente), contrato, plano/serviço elegível e identidade/autorização precisam ser ligados a backends oficiais, testados com dados reais autorizados e auditados antes de disponibilizar a Universidade.

Não há migração, webhook, contrato real, rota, chave ou grant, nenhum merge e nenhum deploy.

**Próximo gate:** CI Laravel do Portal; revisão conjunta AppSec/DBA/LGPD e definição da fonte oficial de contratos e revogações.


## Resultado da homologação e reforço anti-TOCTOU

Dois testes adicionais garantem que cada fonte é consultada **uma única vez** durante a decisão sintética e que exceções da fonte independente de revogação provocam recusa integral. Isso evita que uma segunda leitura com estado alterado seja usada silenciosamente para declarar a empresa elegível.

[GitHub Actions PHP/Laravel #43766807673](https://github.com/rafhael100/imagine-portal/actions/runs/43766807673): **success**, PHP 8.3 e 8.4, **641 testes aprovados, 10.057 asserções**, quatro ignorados e um arriscado. Os quatro workflows da HEAD `d543807545a66202bdf8ffa1dc95d81d27200c0c` terminaram com conclusão success, inclusive container privado. Esses testes não verificam contratos reais e não habilitam o Portal.
