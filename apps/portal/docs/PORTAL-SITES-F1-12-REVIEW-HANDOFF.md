# PORTAL-SITES-F1-12 — Consumidor de propostas de revisão v2 (laboratório)

Implementa apenas um **consumidor candidato** da mensagem HMAC assinada de `imagine-sites` para `imagine-portal`, conforme a versão experimental F1-50 do motor Sites (PR #71). O envelope inclui `authorizationRevision` e candidato com empresa, site, workflow, release e SHA-256 exatos.

`SyntheticSitesReviewEnvelopeConsumer::consume` valida nome, ordem e tipo de cada campo, emissor/destinatário, TTL, nonce, HMAC SHA-256 canônico e fonte de autorização atual independente. Sem uma fonte `PortalReviewAuthoritySource` e um replay fence `PortalReviewReplayFence`, **nega por padrão**. A fonte de produção ainda precisa autenticar a identidade de serviço, consultar os direitos vigentes do proprietário/contrato e verificar revogação; o fence deve ser durável e atômico.

Fixture sintética assinada, com chave *pública de teste*, demonstra paridade entre o layout PHP do Sites e o verificador PHP no Portal. Testes PHPUnit cobrem replay, campos extras, autoria incorreta, assinatura, tenant/site/release/digest divergentes, versão antiga após revogação-reativação, expiração, conta suspensa e fonte indisponível.

O único retorno de sucesso em fixture é `proposal_pending_client_review`, sempre `officialClientAcceptance=false`, `publishable=false`, sem `ENTREGUE`, sem tocar estado de clientes. **Isso não é um contrato de aceite e não valida que houve aprovação do cliente.** Não há HTTP, endpoint, sessão, dados reais, custos, Portal operacional, deploy, VPS ou Cloudflare.

O direito de aceitar a versão exata e publicar o evento oficial de entrega continua exclusivo do fluxo autenticado do Portal. O motor Sites apenas gera a proposta, recebe uma eventual decisão autorizada e publica tecnicamente após todos os gates. Corrida entre revogação e consumo do nonce exige fence transacional futuro. P0/RF-PEND/RV-CT, AppSec/LGPD e readiness permanecem bloqueados.

Este PR do Portal está empilhado em #105 (somente laboratório) e não autoriza integração real ou merge.
