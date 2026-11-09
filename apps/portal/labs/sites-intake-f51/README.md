# PORTAL-F51 — Offline CreateProject v1 candidate shape check

Data: 2026-11-09. Base: PORTAL-F50 (PR #180), sem merge/deploy.

## Fonte do contrato
Schema candidato existente no Imagine Sites:
`contracts/portal-sites/v1/create-project.schema.json` na branch
`test/sites-f1-66-readiness-cli-contract-2026-11-09`.
Identificador do blob observado: `042f4ba31776b486ced757e9624bc42757c46e74`.

## Escopo
`SiteEngineCreateProjectCandidateValidator::inspect($json)` verifica somente a forma sintética do comando:
- exatamente oito propriedades requeridas, sem propriedades extras;
- `schemaVersion` string `1`;
- cinco referências ASCII com padrão `^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$`;
- `contractRevision` inteiro positivo;
- `productCode` exclusivamente `onepage` ou `landing_page`;
- JSON objeto parseável, até 4096 bytes (limite local adicional).

A checagem **não** é um validador JSON Schema completo; tamanho máximo é restrição
adicional do Portal e não do Sites. `json_decode` usa a última ocorrência
de uma chave duplicada; portanto o verificador não pode ser usado como segurança
de transporte ou autenticação e a futura implementação deve adotar parsing
rigoroso anti-duplicatas e assinaturas verificáveis. Não transportar objetos
apenas porque `valid=true`.

O retorno `valid=true, reason=candidate_shape_valid_only` demonstra somente
conformidade sintática parcial. Os métodos `mayTransmit`,
`isAuthenticated`, `isCustomerApprovalVerified`, `mayPublish`
retornam incondicionalmente `false`.

## Verificação
`cd apps/portal && php artisan test --filter=SiteEngineCreateProjectCandidateValidatorTest`
e CI PHP 8.3 / 8.4 na HEAD exata. Status de CI ainda precisa ser verificado.

## Gates abertos
IAM/MFA, autoridade de owner/tenant, contrato ativo, aceite expresso,
LGPD, AppSec/DBA, prevenção de replay/idempotência persistente, revisão
de assinatura e nonce, SEC-R03/Z4 e P0/D02/D03/T3/OPEN.
Nenhuma rota, API, SQL, rede, cliente real, transporte ou publicação foi criada.
