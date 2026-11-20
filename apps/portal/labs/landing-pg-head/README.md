# Portal → Sites: leitura transacional PG18 de aprovação Landing (candidata)

Branch filha da Portal #1012 (HEAD base imutável `e0f38d8d98c151ffa5743c9c372ac039074b9ea7`). Preserva Sites #541/#542 e o par de envelope da Sites #557; não altera seus contratos, branches ou gates.

## Implementado

- `Pg18LandingPageApprovedBriefHeadSourceCandidate` implementa a interface `CurrentApprovedLandingPageBriefHeadSource` sem alterar o default-deny da composição. A consulta usa **uma única snapshot READ ONLY REPEATABLE READ** para escolher a **revisão mais alta**, vinculada a tenant, pedido e site; exige linha aprovada, evento de aprovação auditável coincidente em revisão, referência, epoch e ator; recusa evento posterior na mesma revisão. A versão mais nova em draft impede reutilizar versão aprovada antiga.
- A fixture PG18 sob `apps/portal/labs/landing-pg-head` define esquema isolado, restrições e eventos. É **apenas banco descartável de CI**, não migration autorizada. Nenhuma RLS de liberação é concedida, inclusive após SELECT; conta comum vê zero registros. A prova positiva usa **superusuário somente no PostgreSQL efêmero**.
- Os testes adversariais verificam tenant/site/pedido, versão mais nova, evento ausente, ator divergente, epoch adulterado, digest alterado, revogação append-only, replay/unique, transação e rollback, injeção e ausência de escrita por role sem permissão.
- Executar no GitHub Actions com PostgreSQL 18 descartável e PHP 8.3/8.4; manter regressão Portal #1012. Registrar CI somente depois que finalizar.

## Não homologado / NO-GO

O schema não está no banco oficial Portal. Não existe emissor auditado de eventos de aprovação, comprovante de identidade do cliente/aprovador, vínculo de ator/autorização com o `portal_identities` e sessões MFA reais, política de tenant RLS aprovada ou role oficial autorizada. Um identificador de ator na fixture **não prova autenticidade**, e READ ONLY REPEATABLE READ **não impede revogação posterior**. Não integrar a instância PDO a endpoints, rotas HTTP, container Laravel, migrations ou serviços antes de transação, revisão de segurança e homologação de autorização independente; `LandingPageCurrentApprovedBriefGate` continua com fonte deny por padrão.

Permanecem pendentes no fluxo: compra/pagamento autorizado, briefing assinado pelo responsável, geração de artefato Landing, revisão independente, publicação técnica, aceite do cliente, mTLS e auditoria de ponta a ponta. Todos os dez gates permanecem BLOCKED. Sem merge, deploy, produção, VPS, DNS/Cloudflare, Asaas, credenciais ou fornecedores.
