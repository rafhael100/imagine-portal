# PORTAL-F48 — contrato canônico de prévia Portal ↔ Sites Engine (v1)

**09/11/2026 · PHP puro · nenhum envio, chamada HTTP, servidor ou publicação.**

## Objetivo

Formalizar um envelope estável **somente de metadados**
para a futura separação de responsabilidades entre
Imagine Portal (briefing, acompanhamento e aceite do cliente)
e Imagine Sites Engine (produção e publicação técnica).

O repositório `imagine-sites` permanece motor independente.
F48 NÃO cria um endpoint ou cliente para esse motor;
apenas especifica um contrato de dados compartilhável
após o alinhamento entre repositórios e validações de segurança.

## Envelope de prévia `imagine.portal.sites-handoff-preview.v1`

O conteúdo canônico é JSON, com ordem de campos explícita e
serialização `JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES`.
Todas as propriedades são imutáveis no DTO.

| Campo | Conteúdo do ensaio |
|---|---|
| `schema` | `imagine.portal.sites-handoff-preview.v1` |
| `kind` | `site_editorial_consistency_preview` |
| `source` | `imagine-portal` |
| `target` | `imagine-sites-engine` |
| `organization_demo` | `org-a` ou `org-b` |
| `site_reference_demo` | `DEMO-SITE-REQ-*` |
| `product` | `sites_onepage` ou `sites_landing_page` |
| `brief_revision` | Revisão atual do briefing DEMO |
| `content_revision` | Revisão exata do conteúdo DEMO |
| `content_sha256_demo` | Fingerprint do candidato F43 |
| `review_command_demo` | ID do comando fictício de revisão |
| `review_revision` | 1, revisão fictícia concluída |
| `review_status` | `approved_demo` |
| `authenticated_customer_approval` | `false` |
| `external_delivery_enabled` | `false` |
| `publishing_enabled` | `false` |

**Não existem campos de texto livre ou PII** no envelope:
nem nome, email, telefone, documento, proposta financeira,
código HTML, assets, credenciais ou corpo do briefing.

`SiteEngineHandoffPreviewEnvelope::fingerprint()` retorna SHA-256
da serialização canônica, que serve para comparação
determinística de dados e NÃO para assinatura de
mensagem ou autorização.

## Regras para preparar a prévia

`SiteEngineHandoffPreviewBuilder` requer candidato de
conteúdo F43 e diário de revisão F43 compatíveis. O builder
confere hash, empresa, referência, versão do briefing e
versão do conteúdo, exige **um único evento editorial
sintético**, decisão `approve` e status `approved_demo`.
Pendência, rejeição, pedido de ajuste, troca de empresa,
mudança de produto ou revisão devolvem motivo de recusa.

`SiteEngineHandoffPreviewResult` informa `preview_ready`
ou razão de bloqueio. Mesmo com `preview_ready`,
`isTransportAuthorized()`, `isCustomerApprovalVerified()`
e `mayPublishSite()` permanecem **false**, assim
como as permissões do próprio envelope.

## Testes

`SiteEngineHandoffPreviewTest.php` contém 11 testes sem SQL,
dados reais ou rede: envelope JSON exato e campos proibidos,
deduplicação determinística, ausência de review, pedido de
alterações/rejeição, troca de revisão do briefing/conteúdo,
mudança de empresa ou produto, Landing Page em empresa B,
sem PII/texto livre, entradas inválidas, imutabilidade e
proibição de alegar aceite de cliente.

## Limites antes de integração real

O F48 não comprova a autenticidade da decisão: o
`DEMO-REVIEWER-A/B` foi criado pelo próprio código
fictício e não representa cliente identificado.

O contrato operacional futuro exigirá origem de identidade
Portal-only, membership/owner autorizados, MFA conforme
política, prova de que a versão efetivamente apresentada
foi aceita, persistência de aceite em storage seguro,
assinatura/autenticidade do envelope, isolamento de
empresas, antifraude/anti-replay, trilha de auditoria,
consentimento LGPD, schema negociado com o Sites Engine,
entrega com idempotência/retry e confirmação separada.
Somente então poderá nascer um endpoint publicador.

**PR DRAFT sem merge ou deploy.** Nenhum gasto,
credencial, VPS Leaseweb, Cloudflare, webhook, CRM,
n8n, Sites Engine ou cliente real foi alterado.
SEC-R03/Z4, P0/D02/D03/T3/OPEN e AppSec/DBA
continuam abertos.
