# PORTAL-SITES-F1-11 — Paridade da mensagem assinada CreateProject v1

**Status:** suíte de testes e codificador de laboratório. Não gera credenciais, não autentica usuários e não envia HTTP ao Sites.

Fonte Sites: [PR #11](https://github.com/rafhael100/imagine-sites/pull/11), dependente de #10 e anteriores. Este PR do Portal parte do [PR #101](https://github.com/rafhael100/imagine-portal/pull/101) via branch filha.

O `SyntheticSitesEnvelopeSigner` produz o envelope candidato `schemaVersion, issuer, audience, issuedAt, expiresAt, nonce, payload, mac`, com campos ordenados e HMAC-SHA256, conforme o verificador no Sites. Os testes em `SitesSignedEnvelopeParityTest` usam a mesma fixture sintética, sem dados pessoais, e verificam MAC exato, limites de tempo, campos extras e referências inválidas.

**Limite fundamental:** MAC igual não comprova existência de pedido/contrato autorizado. O Portal deverá usar fonte oficial com MFA/contrato/quitação/revogação; o Sites deverá validar issuer/audience, replay **durável**, autorização por recurso e revisão comercial, além da assinatura. A chave demonstrativa está no teste e é pública; não é segredo de produção. Não há integração real nem HTTPS, API, assinaturas de cliente, filas, banco ou Cloudflare.

Gates P0/RF-PEND/RV-CT continuam abertos; AGI-11-RB01 continua desabilitado. PR draft sem merge/deploy.
