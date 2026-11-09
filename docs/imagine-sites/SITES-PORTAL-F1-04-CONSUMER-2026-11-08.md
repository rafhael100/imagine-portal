# PORTAL-SITES-F1-04 — Consumidor sintético do contrato CreateProject v1

**Documento de desenvolvimento controlado; sem homologação operacional nem merge.**

O motor independente `rafhael100/imagine-sites` define um contrato candidato em
`contracts/portal-sites/v1/create-project.schema.json` (PR #4, filho dos PRs #1–3).
O novo Portal terá a responsabilidade comercial de converter **um contrato autorizado**
num envelope mínimo do tipo `CreateProject v1`.

Este PR acrescenta ao `apps/portal`:
- `PortalSiteContractSource` que em produção deverá consultar **fonte oficial** com tenant, contrato, quitação, elegibilidade, vínculo, revisão e revogação;
- `DenyingPortalSiteContractSource`, implementação **padrão sempre nega**;
- `SitesCreateProjectEncoder` que serializa exclusivamente identificadores opacos, produto e revisão, após uma fonte injetada devolver snapshot adequado;
- Testes com dados sintéticos contra o payload fictício correspondente do Sites v1.

**Sem HTTP, credenciais, login, RLS/SQL, outbox ou n8n nesta etapa.**
O mock não comprova verificação financeira ou identidade: jamais habilitar
fonte permissiva em ambiente real. Não encaminhar dados privados no evento.
Nenhum comando de publicação, aceite, DNS ou lead é gerado.

**Dependências:** Portal deve autenticar ator e contrato na fonte oficial,
Sites precisa revalidar o mesmo pedido em sua fonte autorizada, garantir
idempotência durável, concorrência, outbox e rastreabilidade. A simples
presença dos campos não constitui autorização de execução.

**Gates:** P0-DOC01..14, RF-PEND-01/RF-PEND-04 e contratos RV-CT ainda
abertos. AGI-11-RB01 rollback automático permanece desabilitado.
A main, VPS e produção não são tocadas neste recorte.
