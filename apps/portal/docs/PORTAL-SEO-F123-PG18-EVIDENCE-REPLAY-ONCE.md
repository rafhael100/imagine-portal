# Portal SEO F123 — consumo único de relatório assinado em PostgreSQL 18

**DRAFT / NO-GO / NO-MERGE / NO-DEPLOY.** Continua [F122 #860](https://github.com/rafhael100/imagine-portal/pull/860).

## Entrega
A assinatura Ed25519 F122 autentica bytes de um relatório dentro de uma janela temporal, mas não impedia que o mesmo relatório fosse usado novamente na janela. A F123 adiciona a migração de um ledger PostgreSQL, `portal_seo_build_evidence_nonces`, com chave única **(tenant_ref, nonce_digest)** — independente da chave pública do emissor — e política de isolamento de empresa `ENABLE/FORCE ROW LEVEL SECURITY`.

A classe PHP `PortalSeoPgSignedEvidenceReplayLab`:
- Exige PHP CLI, `APP_ENV=testing`, `CI=true`, flags F118/F122/F123 e banco de testes fixado. **Não tem rota HTTP.**
- Valida assinatura Ed25519, audience, tenant, run, commit Engine e prazo com F122 **antes** de acessar o banco.
- Extrai o nonce apenas de um payload já autenticado, deriva SHA-256 com separação de domínio e tenant, e armazena **somente o digest**.
- Realiza `INSERT … ON CONFLICT (tenant_ref,nonce_digest) DO NOTHING RETURNING nonce_digest` dentro de uma transação com `app.tenant_ref` em `set_config(...,true)`, utilizando papel PostgreSQL sem `SUPERUSER` ou `BYPASSRLS`.
- Aceita apenas a primeira inserção efetivamente confirmada pelo banco. Segunda utilização ou falha de permissão retorna recusa genérica; não libera dados privados.
- Mantém `realServiceSignerVerified=false`, `realPortalTenantVerified=false`, `realReplayLedgerDeployed=false`, `customerDisplayAuthorized=false` e `publicationAuthorized=false`.

## Prova com PostgreSQL real
GitHub Actions inicia um contêiner descartável **PostgreSQL 18**, executa as migrations Laravel, cria roles sem bypass e testa em PHP 8.3/8.4:
- Um relatório assinado real do Engine RC45, gerado de HTML sintético, é aceito uma vez; reutilização falha.
- O vínculo com outro tenant é recusado; uma consulta direta sem contexto RLS não vê registros da empresa.
- Papel de leitura sem `INSERT` não consegue consumir o evento.
- Dois processos PHP concorrentes usam o mesmo envelope: **exatamente um** deve conseguir registrar o nonce.
- Uma chave pública diferente, assinando o **mesmo nonce**, não torna o evento reutilizável (uniqueness por tenant+nonce).
- Rejeita execução em `APP_ENV=production`; verifica que conexões não retêm o contexto RLS entre transações.

## O que ainda não existe
Esse teste contém **identidades e chaves efêmeras sintéticas**. Não implementa login real, MFA, tenant autorizado por sessão, emissor operacional, KMS/rotação de chaves, expurgo de registros, revogação, política de retenção nem monitoramento da VPS. A migração fica apenas no código até revisão independente por DBA/AppSec e homologação, sem aplicação à produção.

Não há merge, deploy, cobrança, API Google ou exposição de cliente.
