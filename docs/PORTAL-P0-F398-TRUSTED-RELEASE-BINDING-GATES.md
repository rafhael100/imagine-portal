# Imagine Portal P0 — vínculo versionado pedido ↔ release no PostgreSQL18

## Lacuna que esta entrega resolve

O [Portal F1-395](https://github.com/rafhael100/imagine-portal/pull/756) já exige que o atestado financeiro e o aceite editorial se refiram **ao mesmo contrato, empresa, pedido e versão imutável do site**, mas sua fonte de vínculo era apenas uma interface `PortalCurrentSiteReleaseAssignmentSource` com implementação padrão que nega. Esta F1-398 prepara um **adaptador real de leitura de PostgreSQL18**, sem criar uma conexão operacional de produção.

A futura autoridade do Portal deverá produzir registros de associação do servidor, nunca aceitar associações provenientes de campos JSON do cliente, do webhook Asaas ou do próprio atestado Ed25519.

## Registro durável e isolamento

- O laboratório usa `portal_release.binding_history`, com empresa, pedido, contrato/revisão, produto, site/release, SHA256 de HTML final, revisão editorial, `binding_revision` e estado `active/revoked`. As gravações são **append-only** do lado da autoridade de teste. O leitor não recebe `INSERT`, `UPDATE` nem `DELETE`.
- Uma nova versão de release ou revogação **supera a versão anterior**. A consulta sempre considera a maior `binding_revision` de um pedido, sem procurar um release antigo ainda marcado como ativo. Um token assinado previamente não reativa um release substituído.
- O papel de leitura `portal_release_reader` é login SQL dedicado, sem `SUPERUSER`, `BYPASSRLS`, `INHERIT` ou acesso à tabela dos segredos HMAC.
- A tabela exige `ENABLE ROW LEVEL SECURITY` e `FORCE ROW LEVEL SECURITY`. A política de tenant usa uma função `SECURITY DEFINER` que confronta `session_user/current_user`, empresa de sessão e HMAC SHA256 sobre o contexto da empresa, com chave obtida de tabela protegida do dono do banco. Apenas mudar variáveis `SET` numa conexão SQL não cria um MAC válido.
- O código `PortalPg18SiteReleaseAssignmentSource` faz `SET LOCAL` de empresa+MAC no início de **cada transação independente**, consulta o vínculo corrente por pedido e empresa, compara site/release/revisão e retorna `PortalSitesReleaseAssignment` tipado. Identificadores suspeitos, falha de banco, login privilegiado e versão revogada são negados.
- **O MAC não deve ser fornecido pelo navegador**: só poderá ser gerado pela futura autoridade IAM/S2S, com segredo em vault. As chaves fixas desta SQL são **inteiramente fictícias e exclusivas de um PostgreSQL descartável na CI**.

## Provas automáticas

A CI executa PHP 8.3/8.4 conectado a PostgreSQL18 real, autentica como role mínima de leitura e usa duas empresas fictícias. Testa:
- leitura exata do vínculo da empresa A e B, sem qualquer visibilidade da outra empresa;
- conexão sem assinatura, `SET` falsificado, MAC de empresa diferente, tentativa de leitura de segredo e mutação SQL;
- ausência de transação compartilhada, IDs inválidos, site/revisão incorretos;
- revogação append-only, histórico auditável e aprovação somente da versão editorial mais recente;
- impossibilidade de usar uma conexão administrativa como leitor de produção.

## Limites e próximas etapas

A SQL `release-binding-pg18-ci.sql` **não é migração de produção** e contém identidade e segredos sintéticos. Não aplicar na VPS Leaseweb. O adaptador não está ligado a controller, provedor Laravel, S2S ou executor de Sites e **permanece default-deny** na aplicação. A escrita operacional dos vínculos exigirá trilha de auditoria, transações CAS, identidade/MFA, governança de contrato e autorização editorial. O Sites manterá uma fonte de vínculo independente e deverá revalidar pagamento, revogação e aceite no momento da publicação.

A próxima aprovação operacional exige migração reversível e backup/restore privado, IAM e gestão de chaves, fonte assinada de pedido/release, teste de transação concorrente, Asaas Sandbox consultado sem segredos no código, mTLS Portal↔Sites e relatório de AppSec/DBA/LGPD.

**DRAFT / NO-GO / NO MERGE / NO DEPLOY / NO BILLING / NO PUBLISH.**
