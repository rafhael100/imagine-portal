# GSC Slice O — permissão registrada por empresa/operador/propriedade exata

Este lote adiciona **consulta somente leitura** a um registro de grant Google Search Console previamente provisionado por um futuro serviço autenticado. Não permite criar nem aprovar um grant através de dados enviados pelo cliente.

## Modelo

A migração PostgreSQL `2026_11_12_000002_create_portal_gsc_property_grants.php`, ainda **não aplicada em produção**, define um registro por `tenant_ref + actor_ref + property_fingerprint` com escopo `webmasters.readonly`, status ativo/revogado, instante de verificação, expiração e revogação.

`GoogleSearchConsoleRecordedPropertyGrant::inspect(tenant, actor, exactGoogleSiteUrl, now)`:
- Valida sintaxe de empresa e operador e usa `GoogleSearchConsolePropertyIdentifier::fingerprint` sobre o **siteUrl exato**, diferenciando `sc-domain:example.com` de `https://example.com/` e rejeitando variantes malformadas.
- Consulta por SQL preparado e filtrado por empresa, operador, fingerprint, status `active`, escopo readonly, não revogado, prazo válido e **verificação registrada há no máximo 15 minutos**.
- Não busca nenhuma URL na rede; não aceita um domínio informado pelo navegador como comprovação de propriedade.
- Retorna somente um relatório de *registro recente encontrado*, com `googleApiCheckedNow=false`, `sessionMfaVerified=false`, `tenantMembershipVerified=false`, `propertyAuthorizationVerified=false`, `customerDisplayAuthorized=false`. O registro, por si, **não autoriza exibir dados ou chamar o Google**.
- O código é somente leitura e não define função de provisionamento. Esse ato exigirá autenticação real Portal, consentimento e prova atual de permissão Google em serviço distinto com papel de banco dedicado.

## Testes

A migração Laravel é executada e revertida em PostgreSQL descartável na CI; o laboratório `verify_recorded_grants.php` cria somente registros artificiais e prova isolamento por tenant/ator, propriedade URL-prefix versus domínio, prazo, revogação, injeção, status e impedimento de escopo de escrita pelo banco. Nenhuma chave, token ou dado de cliente é usado.

## Próximos bloqueios de produto

O Portal continua sem fluxo autenticado MFA/RBAC/membership operacional nesta integração, e o Google Cloud OAuth/cliente HTTP ainda não está conectado. A origem real de grants e sua revogação precisam ser implementadas e auditadas antes de qualquer uso em produção. A janela de 15 minutos é **somente uma condição de frescor do registro**, não prova de acesso atual; o Google pode revogar acesso antes disso.

**DRAFT/NO-GO:** sem merge em `main/dev`, migração real, deploy, DNS/301, chamada Google ou autorização de cliente. Requer revisão independente AppSec/DBA/LGPD e staging.
